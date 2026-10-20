<?php

namespace Repas\Command;


use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassificationResult;
use Repas\Repas\Application\Command\ClassifyBaseIngredients\ClassifyBaseIngredientsCommand;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverCatalogCommand;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverOutcome;
use Repas\Repas\Application\Command\HandOverCatalog\HandOverResult;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedOutcome;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedResult;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\CatalogHandoverReportDTO;
use Repas\Repas\Application\Query\GetCatalogHandoverReport\GetCatalogHandoverReportQuery;
use Repas\Repas\Application\Query\GetSeedingReport\GetSeedingReportQuery;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingHealthDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingUserReportDTO;
use Repas\Repas\Domain\Model\HandoverPrecondition;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * La passation du catalogue (ING-009, ADR 0028 amendement du 2026-10-20, `docs/ingredients/sans-commun.md` §§ 5 et 6) : les 261 anciens communs
 * (les ingrédients SANS créateur) passent au propriétaire du catalogue, le compte administrateur protégé. UNE commande, des phases dans l'ordre :
 *
 *   1. classer : `ingredient.is_base` des anciens communs d'après le fichier validé (151 « de base », 110 « spécifiques ») ;
 *   2. amorcer : tout compte ACTIF, hors propriétaire, qui n'a pas ses ingrédients (le compte vide) reçoit ceux « de base » et ce que ses données
 *      référencent, par `SeedUserIngredients` (le moteur d'ING-008 : une transaction par compte, empreintes avant/après) ;
 *   3. contrôler : les préconditions P1 à P7 (`CatalogHandoverPreconditions`), relues DANS la transaction de la phase 4 ;
 *   4. passer : UN `UPDATE ingredient SET creator = <propriétaire>` des anciens communs, empreintes avant/après, statistique annoncée une fois ;
 *   5. vérifier : le rapport est relu, la requête de santé doit être verte.
 *
 * Elle s'arrête au premier échec, ne crée ni ne supprime aucun ingrédient, et est rejouable : un second `--apply` n'écrit rien et le dit.
 *
 * Ici, seulement la boucle et l'affichage : le travail est dans les commandes applicatives (bus transactionnel) et le rapport est la requête
 * `GetCatalogHandoverReport`. La sortie n'a jamais d'adresse e-mail, d'identifiant de compte ni de nom d'ingrédient personnel : les comptes sont
 * numérotés et désignés par une référence courte (SHA-256).
 *
 * Modes : sans option, rapport À BLANC (lecture seule, aucun verrou) ; `--check`, lecture seule aussi (code 0 si et seulement si la passation est
 * faite et conforme) ; `--apply` écrit, `--apply --stop-before-handover` s'arrête après l'amorçage (phases 1 et 2). Lancée par l'humain avec une
 * sauvegarde (`.claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh`), JAMAIS par un agent de code en production.
 */
#[AsCommand(
    name: 'repas:ingredients:hand-over-catalog',
    description: "Passation ING-009 : les anciens communs passent au propriétaire du catalogue (à blanc par défaut, --apply pour écrire, --check pour vérifier)",
)]
final class HandOverCatalogIngredientsCommand extends Command
{
    private const string SOURCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/';
    /** Un identifiant de compte, avec le début de slug qui le précède : `praline<identifiant>` est un nom d'ingrédient personnel. */
    private const string ID_PATTERN = '/[a-z0-9-]*[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    /**
     * @param list<string> $allowedEnvironments les environnements où l'écriture est permise (`prod` et `dev` ; `test` seulement en test)
     */
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        #[Autowire(param: 'repas.adoption.environments')]
        private readonly array $allowedEnvironments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, "Écrit les phases 1 à 5 (classement, amorçage, passation, vérification). Sans cette option, rien n'est modifié.")
            ->addOption('check', null, InputOption::VALUE_NONE, "Lecture seule : le rapport et la vérification (code de sortie 0 si et seulement si la passation est faite et conforme ; avec --stop-before-handover, si le classement est posé et plus rien n'est à amorcer)")
            ->addOption('stop-before-handover', null, InputOption::VALUE_NONE, "S'arrête après l'amorçage (phases 1 et 2) ; la passation (phase 4) n'est pas faite. À blanc, dit si --apply avec cette option écrirait")
            ->addOption('accept-sent', null, InputOption::VALUE_NONE, "Autorise le repointage des listes déjà envoyées au drive d'un compte amorcé (immuables pour l'API) ; obligatoire dès que l'une est concernée")
            ->addOption('source', null, InputOption::VALUE_REQUIRED, "Nom du lancement de l'amorçage, écrit dans ingredient_alias.source (64 caractères au plus ; défaut rattrapage-AAAA-MM-JJ)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $check = (bool) $input->getOption('check');
        $stop = (bool) $input->getOption('stop-before-handover');
        if ($apply && $check) {
            $io->error('--apply et --check s\'excluent : --check ne fait que lire.');

            return Command::INVALID;
        }
        $source = $this->source($input);
        if ($source === null) {
            $io->error(sprintf('Nom de lancement invalide : lettres, chiffres, « . _ : - », %d caractères au plus.', IngredientAlias::MAX_SOURCE_LENGTH));

            return Command::INVALID;
        }
        if ($apply && !in_array($this->environment, $this->allowedEnvironments, true)) {
            $io->error(sprintf("Écriture refusée dans l'environnement « %s » (permis : %s). Rien n'a été modifié.", $this->environment, implode(', ', $this->allowedEnvironments)));

            return Command::INVALID;
        }

        $report = $this->report();
        $io->title(match (true) {
            $apply && $stop => sprintf('Passation du catalogue : classement et amorçage seulement, lancement « %s »', $source),
            $apply => sprintf('Passation du catalogue : lancement « %s »', $source),
            $check => 'Passation du catalogue : vérification (lecture seule)',
            $stop => 'Passation du catalogue : à blanc du classement et de l\'amorçage seulement (lecture seule)',
            default => 'Passation du catalogue : à blanc (lecture seule)',
        });
        $this->render($io, $report);

        if (!$apply) {
            return $this->verdict($io, $report, $check, $stop, (bool) $input->getOption('accept-sent'));
        }

        return $this->apply($io, $input, $report, $source, $stop);
    }

    /**
     * Pourquoi `--apply` (avec ces options) refuserait d'écrire, ou `null` s'il écrirait. Rien n'est écrit tant que l'une de ces raisons existe :
     * ni classement, ni amorçage, ni passation (jamais d'écriture à moitié quand on sait déjà que la suite sera refusée).
     */
    private function refusal(CatalogHandoverReportDTO $report, bool $stop, bool $acceptSent): ?string
    {
        if ($report->hasBlockingAnomalies()) {
            return 'Anomalies bloquantes (voir ci-dessus).';
        }
        if (!$stop && !$report->handedOver && !$report->isReadyToHandOverAfterSeeding()) {
            return sprintf(
                "La passation complète serait refusée même une fois le classement posé et l'amorçage fait : %s. Pour classer et amorcer seulement : --apply --stop-before-handover.",
                implode(' ; ', array_map(static fn(HandoverPrecondition $precondition): string => $precondition->describe(), $report->unmetAfterSeeding())),
            );
        }
        if ($report->seeding->sentShoppingListsToRepoint() > 0 && !$acceptSent) {
            return sprintf(
                "%d liste(s) déjà envoyée(s) au drive seraient repointées (immuables pour l'API, ADR 0023) : relance avec --accept-sent si l'envoi est terminé.",
                $report->seeding->sentShoppingListsToRepoint(),
            );
        }

        return null;
    }

    /**
     * Mode à blanc et `--check` : lecture seule. Le code de sortie de `--check` dit s'il reste quelque chose à faire ; celui du mode à blanc dit
     * si `--apply`, avec les MÊMES options, écrirait (0) ou refuserait d'écrire (1) : le script d'exploitation s'en sert pour s'arrêter avant la
     * sauvegarde.
     */
    private function verdict(SymfonyStyle $io, CatalogHandoverReportDTO $report, bool $check, bool $stop, bool $acceptSent): int
    {
        $io->newLine();
        if ($report->hasBlockingAnomalies()) {
            $io->error("Anomalies bloquantes : --apply refusera d'écrire tant qu'elles ne sont pas réglées. Rien n'a été modifié.");

            return Command::FAILURE;
        }
        if ($report->seeding->sentShoppingListsToRepoint() > 0 && ($check || $acceptSent)) {
            $io->warning(sprintf("%d liste(s) déjà envoyée(s) au drive seraient repointées : --apply exigera --accept-sent.", $report->seeding->sentShoppingListsToRepoint()));
        }

        if ($check && $stop) {
            // Le classement et l'amorçage seulement (le script d'exploitation, `--jusqu-a-l-amorcage`) : conforme quand le classement est posé et que plus rien n'est à amorcer
            if ($report->isClassified() && $report->seeding->isNothingToDo()) {
                $io->success('Rien à faire : classement posé, plus aucun compte à amorcer. Rien n\'a été modifié.');

                return Command::SUCCESS;
            }
            $io->error('Il reste à faire : le classement n\'est pas posé ou des comptes sont à amorcer (voir ci-dessus). Rien n\'a été modifié.');

            return Command::FAILURE;
        }
        if ($report->isConform()) {
            $io->success('Rien à faire : passation faite et conforme. Rien n\'a été modifié.');

            return Command::SUCCESS;
        }
        if ($check) {
            $io->error($report->handedOver
                ? "La passation est faite, mais il reste à amorcer ou la requête de santé n'est pas verte (voir ci-dessus). Rien n'a été modifié."
                : "Il reste à faire : la passation n'est pas faite (voir ci-dessus). Rien n'a été modifié.");

            return Command::FAILURE;
        }

        $this->renderReadiness($io, $report);
        $refusal = $this->refusal($report, $stop, $acceptSent);
        if ($refusal !== null) {
            $io->warning($refusal . ' --apply, avec ces options, refuserait d\'écrire.');
            $io->text('À blanc : rien n\'a été modifié.');

            return Command::FAILURE;
        }
        $io->text('À blanc : rien n\'a été modifié. --apply, avec ces options, écrirait.');

        return Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, InputInterface $input, CatalogHandoverReportDTO $report, string $source, bool $stop): int
    {
        $acceptSent = (bool) $input->getOption('accept-sent');
        $refusal = $this->refusal($report, $stop, $acceptSent);
        if ($refusal !== null) {
            $io->error($refusal . ' Rien n\'a été modifié.');

            return Command::FAILURE;
        }

        $started = hrtime(true);

        // Phase 1 : le classement
        $io->section('Phase 1 — classement des ingrédients « de base »');
        if ($report->handedOver) {
            $io->text('Passation déjà faite : plus aucun ingrédient sans créateur, rien à classer.');
        } elseif ($report->isClassified()) {
            // Le classement est posé : rien à écrire, pas même une instruction qui ne changerait aucune ligne
            $io->text(sprintf('Classement déjà posé : %d ingrédient(s) sans créateur, %d « de base », %d « spécifiques ». Rien n\'est écrit.', $report->withoutCreator, $report->fileBase, $report->fileEntries - $report->fileBase));
        } else {
            try {
                $classified = $this->commandBus->dispatch(new ClassifyBaseIngredientsCommand());
                \assert($classified instanceof ClassificationResult);
            } catch (Throwable $e) {
                return $this->failure($io, 'Phase 1 (classement)', $e, 'Rien n\'est écrit.');
            }
            $io->text($classified->changed === 0
                ? sprintf('Classement déjà posé (relu dans la transaction) : %d ingrédient(s) sans créateur, %d « de base », %d « spécifiques ». Rien n\'est écrit.', $classified->classified, $classified->base, $classified->specific)
                : sprintf('%d ingrédient(s) sans créateur classés : %d « de base », %d « spécifiques » ; %d marqueur(s) écrit(s).', $classified->classified, $classified->base, $classified->specific, $classified->changed));
        }

        // Phase 2 : l'amorçage des comptes actifs sans ingrédients, avec les marqueurs désormais posés
        $io->section('Phase 2 — amorçage des comptes actifs qui n\'ont pas leurs ingrédients');
        $seeding = $this->seedingReport();
        if ($seeding->hasBlockingAnomalies()) {
            $io->error('Anomalies bloquantes de l\'amorçage après le classement : écriture des comptes refusée (voir le rapport). Le classement posé le reste ; rien d\'autre n\'est écrit.');

            return Command::FAILURE;
        }
        $seededCount = 0;
        foreach ($seeding->selected() as $user) {
            if ($user->isNothingToDo()) {
                $io->text(sprintf('Compte n°%d [%s] : 0 à copier, 0 à repointer, rien à faire.', $user->rank, $user->reference));
                continue;
            }
            try {
                $result = $this->commandBus->dispatch(new SeedUserIngredientsCommand($user->userId, $source, $acceptSent));
                \assert($result instanceof SeedResult);
            } catch (Throwable $e) {
                $io->error(sprintf('Compte n°%d [%s] : ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit pour lui. %s', $user->rank, $user->reference, $this->sanitize($e)));
                $io->warning("Arrêt au premier échec. Les phases et les comptes déjà traités le restent ; relancer --apply est sans danger.");

                return Command::FAILURE;
            }
            if ($result->outcome === SeedOutcome::NO_CATALOG) {
                $io->error(sprintf('Compte n°%d [%s] : rien n\'a pu être copié (pas de propriétaire du catalogue ou aucun ingrédient « de base »). Il reste non amorcé. Arrêt.', $user->rank, $user->reference));

                return Command::FAILURE;
            }
            if (!$result->wroteSomething()) {
                $io->text(sprintf('Compte n°%d [%s] : plus concerné au moment de l\'écriture (%s), ignoré.', $user->rank, $user->reference, $result->outcome->value));
                continue;
            }
            ++$seededCount;
            $io->text($this->describeSeed($user, $result));
            if ($result->fingerprint !== $user->fingerprint) {
                $io->error(sprintf("Compte n°%d [%s] : l'empreinte du contenu a changé depuis le rapport d'avant (une modification faite par l'utilisateur pendant le lancement ? relire avant de conclure). Arrêt.", $user->rank, $user->reference));

                return Command::FAILURE;
            }
        }
        if ($seeding->selected() === []) {
            $io->text('Aucun compte à amorcer.');
        }

        // Phases 3 et 4 : les préconditions relues dans la transaction, puis la passation
        $handedOver = null;
        if ($stop) {
            $io->section('Phases 3 et 4 — passation');
            $io->text('Arrêt avant la passation (--stop-before-handover) : les ingrédients sans créateur le restent.');
        } else {
            $io->section('Phases 3 et 4 — contrôle des préconditions et passation');
            try {
                $handedOver = $this->commandBus->dispatch(new HandOverCatalogCommand());
                \assert($handedOver instanceof HandOverResult);
            } catch (Throwable $e) {
                return $this->failure($io, 'Phase 4 (passation)', $e, 'Rien n\'est écrit pour la passation (transaction annulée) ; le classement et l\'amorçage déjà faits le restent.');
            }
            $io->text($handedOver->outcome === HandOverOutcome::ALREADY_DONE
                ? 'Passation déjà faite : plus aucun ingrédient sans créateur, rien à passer. Rien n\'est écrit.'
                : sprintf(
                    '%d ingrédient(s) passé(s) au propriétaire en une instruction ; %d conversion(s) propre(s) restées rattachées à leur ingrédient (inchangées) ; empreinte des ingrédients %s et du contenu du propriétaire %s identiques avant et après ; statistique « ingrédients créés » du propriétaire annoncée une fois.',
                    $handedOver->handedOver,
                    $handedOver->ownConversions,
                    substr($handedOver->ingredientsFingerprint, 0, 12),
                    $handedOver->ownerFingerprint,
                ));
        }

        // Phase 5 : le rapport relu
        $after = $this->report();
        $io->section('Phase 5 — vérification d\'après lancement');
        $this->render($io, $after, true);
        $io->text(sprintf('Durée : %.1f s, %d compte(s) amorcé(s).', (hrtime(true) - $started) / 1e9, $seededCount));

        $problems = [];
        if ($after->hasBlockingAnomalies()) {
            $problems[] = 'une anomalie bloquante reste (voir ci-dessus)';
        }
        if (!$after->seeding->isNothingToDo()) {
            $problems[] = "il reste quelque chose à amorcer";
        }
        if ($stop) {
            if (!$after->isClassified()) {
                $problems[] = "le classement n'est pas posé";
            }
        } else {
            if (!$after->handedOver) {
                $problems[] = "il reste des ingrédients sans créateur";
            }
            if ($handedOver?->wroteSomething() === true && $after->atOwnerMarkersDiffering > 0) {
                $problems[] = sprintf('%d marqueur(s) « de base » ne sont pas ceux du fichier', $after->atOwnerMarkersDiffering);
            }
            if (!$after->seeding->health->isHealthy()) {
                $problems[] = "la requête de santé n'est pas verte";
            }
        }
        if ($problems !== []) {
            $io->error('Vérification : ' . implode(' ; ', $problems) . '.');

            return Command::FAILURE;
        }

        if ($stop) {
            $io->success('Classement posé et comptes amorcés : plus rien à copier ni à repointer, empreintes identiques à celles d\'avant. La passation n\'est pas faite (--stop-before-handover).');
            $this->renderReadiness($io, $after);
            if (!$after->seeding->health->isHealthy()) {
                $io->warning("La requête de santé n'est pas verte (voir ci-dessus) : ce n'est pas forcément à cause de ce lancement ; la passation complète le refusera tant que P2 ou P3 manque.");
            }
        } else {
            $io->success('Passation faite et conforme : plus aucun ingrédient sans créateur, plus rien à amorcer, la requête de santé est verte.');
        }

        return Command::SUCCESS;
    }

    private function renderReadiness(SymfonyStyle $io, CatalogHandoverReportDTO $report): void
    {
        $io->newLine();
        if ($report->handedOver) {
            $io->text('Prêt pour la passation : sans objet, elle est faite.');

            return;
        }
        $unmet = $report->unmet();
        $unmetAfter = $report->unmetAfterSeeding();
        $io->text(sprintf('Prêt pour le classement et l\'amorçage (--apply --stop-before-handover) : %s.', $report->hasBlockingAnomalies() ? 'NON (anomalie bloquante)' : 'oui'));
        if ($unmet === []) {
            $io->text('Prêt pour la passation complète (--apply) : OUI, toutes les préconditions P1 à P7 sont remplies.');
        } elseif ($unmetAfter === []) {
            $io->text(sprintf('Prêt pour la passation complète (--apply) : oui, une fois le classement posé et l\'amorçage fait. Aujourd\'hui il manque : %s.', implode(', ', array_map(static fn(HandoverPrecondition $precondition): string => $precondition->code, $unmet))));
        } else {
            $io->text(sprintf('Prêt pour la passation complète (--apply) : NON. Après le classement et l\'amorçage il manquera encore : %s.', implode(', ', array_map(static fn(HandoverPrecondition $precondition): string => $precondition->code, $unmetAfter))));
        }
    }

    private function render(SymfonyStyle $io, CatalogHandoverReportDTO $report, bool $compact = false): void
    {
        $owner = match (true) {
            !$report->ownerExists => "le propriétaire du catalogue n'existe pas",
            default => sprintf(
                '[%s] %s, %s ; %d compte(s) administrateur au total',
                $report->ownerReference,
                match ($report->ownerStatus) {
                    'ACTIVE' => 'actif',
                    'PENDING' => 'en attente',
                    'DISABLED' => 'désactivé',
                    default => strtolower((string) $report->ownerStatus),
                },
                $report->ownerIsAdministrator ? 'administrateur' : "PAS administrateur",
                $report->administrators,
            ),
        };
        $io->text('Propriétaire du catalogue : ' . $owner);
        $io->text(sprintf(
            'Fichier de classement : %d ingrédient(s) dont %d « de base » et %d « spécifiques » ; somme de contrôle des slugs %s, de ceux « de base » %s.',
            $report->fileEntries,
            $report->fileBase,
            $report->fileEntries - $report->fileBase,
            $report->fileChecksum,
            $report->fileBaseChecksum,
        ));
        $io->text(sprintf(
            'Anciens communs : %d sans créateur, %d déjà au propriétaire (sans origine) parmi ceux du fichier ; %d conversion(s) propre(s) rattachée(s) à ceux sans créateur.',
            $report->withoutCreator,
            $report->atOwner,
            $report->ownConversions,
        ));
        $io->text(sprintf(
            '    %d ingrédient(s) %s, somme de contrôle des slugs %s (%s) ; dont %d « de base », somme %s (%s).',
            $report->catalogCount,
            $report->catalogAtOwner ? 'chez le propriétaire' : 'sans créateur',
            $report->catalogChecksum,
            $report->catalogChecksum === $report->fileChecksum ? 'celle du fichier' : 'DIFFÉRENTE de celle du fichier',
            $report->catalogMarked,
            $report->catalogMarkedChecksum,
            $report->catalogMarkedChecksum === $report->fileBaseChecksum ? 'celle du fichier' : ($report->catalogMarked === 0 ? 'classement pas encore posé' : 'différente de celle du fichier'),
        ));
        if (!$report->handedOver && $report->withoutCreator > 0) {
            $io->text(sprintf(
                '    accord avec le fichier : %s ; phase 1 : %d marqueur(s) à écrire ; %d avec une origine, %d au format d\'un slug de compte.',
                $report->unknown === 0 && $report->missing === 0 ? 'exact' : sprintf('%d hors fichier, %d du fichier manquants', $report->unknown, $report->missing),
                $report->markersToWrite,
                $report->withOrigin,
                $report->accountFormat,
            ));
            foreach ([['hors fichier', $report->unknownSample], ['manquants', $report->missingSample]] as [$label, $sample]) {
                if ($sample !== []) {
                    $io->text(sprintf('    exemples %s : %s', $label, implode(', ', $sample)));
                }
            }
        }
        if ($report->handedOver && $report->atOwnerMarkersDiffering > 0) {
            $io->text(sprintf('    %d marqueur(s) « de base » du propriétaire diffèrent du fichier initial (il a le droit de les changer : information).', $report->atOwnerMarkersDiffering));
        }
        foreach ($report->blockingAnomalies as $anomaly) {
            $io->text('ANOMALIE BLOQUANTE : ' . $anomaly);
        }

        $this->renderSeeding($io, $report->seeding, $compact);

        if (!$compact && !$report->handedOver) {
            $io->newLine();
            $io->text(sprintf('Préconditions de la passation (état actuel ; l\'amorçage de %d compte(s) en lèvera certaines) :', count($report->seeding->selected())));
            foreach ($report->preconditions as $precondition) {
                $io->text(sprintf(
                    '    %s : %s%s',
                    $precondition->code,
                    $precondition->met ? 'OK' : 'MANQUE',
                    $precondition->met ? '' : sprintf(' — %s%s', $precondition->detail, $precondition->metAfterSeeding ? ' (levée par l\'amorçage)' : ''),
                ));
            }
        }
    }

    private function renderSeeding(SymfonyStyle $io, SeedingReportDTO $seeding, bool $compact): void
    {
        $io->newLine();
        $io->text(sprintf(
            'Amorçage (phase 2), classement lu comme posé : %d compte(s) au total dont %d retenu(s) (actifs, hors propriétaire, sans ingrédients) ; le catalogue compterait %d ingrédient(s) dont %d « de base ».',
            count($seeding->users),
            count($seeding->selected()),
            $seeding->catalogIngredients,
            $seeding->baseIngredients,
        ));
        foreach ($seeding->selected() as $user) {
            if ($compact) {
                $io->text(sprintf(
                    '    Compte n°%d [%s] : %d à copier, %d ligne(s) et %d choix du drive à repointer, empreinte du contenu %s.',
                    $user->rank,
                    $user->reference,
                    $user->toSeed,
                    $user->linesToRepoint(),
                    $user->driveChoicesToRepoint,
                    $user->fingerprint,
                ));
                continue;
            }
            $io->text(sprintf('    Compte n°%d [%s] (%s) : RETENU — %d ingrédient(s), %d recette(s), %s', $user->rank, $user->reference, match ($user->status) { 'ACTIVE' => 'actif', 'PENDING' => 'en attente', 'DISABLED' => 'désactivé', default => strtolower($user->status) }, $user->ingredients, $user->recipes, $user->seeded ? 'amorcé' : 'non amorcé'));
            $io->text(sprintf(
                '        à copier : %d ingrédient(s) dont %d « de base » et %d demandé(s) par ses lignes (déjà présents et désignés sans copie : %d), dont %d slug(s) à suffixer ; %d conversion(s) propre(s) à copier',
                $user->toSeed,
                $user->toSeedBase,
                $user->toSeedReferenced,
                $user->reusable,
                $user->slugCollisions,
                $user->conversionsToCopy,
            ));
            $io->text(sprintf(
                '        à repointer : %d ligne(s) dans %d recette(s) ; %d ligne(s) + %d ingrédient(s) dans %d liste(s) dont %d envoyée(s) au drive ; %d choix du drive (%d produit(s)) sur %d choix et %d produit(s) au total',
                $user->recipeRowsToRepoint,
                $user->recipesToRepoint,
                $user->shoppingListRowsToRepoint,
                $user->shoppingListIngredientsToRepoint,
                $user->shoppingListsToRepoint,
                $user->sentShoppingListsToRepoint,
                $user->driveChoicesToRepoint,
                $user->driveProductsOfChoicesToRepoint,
                $user->driveChoices,
                $user->driveProducts,
            ));
            $io->text('        empreinte du contenu (identique avant et après) : ' . $user->fingerprint);
            foreach ($user->blockingAnomalies as $anomaly) {
                $io->text('        ANOMALIE BLOQUANTE : ' . $anomaly);
            }
        }
        $this->renderHealth($io, $seeding->health);
    }

    private function renderHealth(SymfonyStyle $io, SeedingHealthDTO $health): void
    {
        $lines = static fn(array $byTable): string => $byTable === [] ? 'aucune' : implode(', ', array_map(static fn(string $table, int $n): string => sprintf('%s : %d', $table, $n), array_keys($byTable), $byTable));
        $io->newLine();
        $io->text('Santé de l\'amorçage (requête de santé) :');
        $io->text(sprintf('    comptes actifs sans amorçage (hors propriétaire) : %d', $health->activeNotSeeded));
        $io->text(sprintf('    comptes actifs sans aucun ingrédient : %d', $health->activeWithoutIngredient));
        $io->text(sprintf('    lignes d\'un compte (hors propriétaire) sur un ingrédient qui n\'est pas à lui : %s', $lines($health->foreignLines)));
        $io->text($health->ownerForeignLines === null
            ? sprintf('    lignes du propriétaire sur un ingrédient qui n\'est pas à lui : sans objet (%d ingrédient(s) sans créateur, ou pas de propriétaire)', $health->ingredientsWithoutCreator)
            : sprintf('    lignes du propriétaire sur un ingrédient qui n\'est pas à lui : %s', $lines($health->ownerForeignLines)));
        $io->text('    verdict : ' . ($health->isHealthy() ? 'VERT' : 'ROUGE'));
    }

    private function describeSeed(SeedingUserReportDTO $user, SeedResult $result): string
    {
        return sprintf(
            'Compte n°%d [%s] : %d copie(s) créée(s) (dont %d à slug suffixé, %d demandée(s) par ses lignes) et %d déjà présente(s) désignée(s), %d conversion(s) copiée(s) ; repointé : %d ligne(s) de %d recette(s), %d ligne(s) + %d ingrédient(s) de %d liste(s) dont %d envoyée(s), %d choix du drive ; empreinte %s identique.',
            $user->rank,
            $user->reference,
            $result->seeded,
            $result->slugCollisions,
            $result->seededBecauseReferenced,
            $result->reused,
            $result->conversionsCopied,
            $result->repointed->recipeRows,
            $result->repointed->recipes,
            $result->repointed->shoppingListRows,
            $result->repointed->shoppingListIngredients,
            $result->repointed->shoppingLists,
            $result->sentShoppingListsRepointed,
            $result->driveChoicesRepointed,
            $result->fingerprint,
        );
    }

    private function failure(SymfonyStyle $io, string $phase, Throwable $e, string $consequence): int
    {
        $io->error(sprintf('%s : ÉCHEC. %s %s', $phase, $this->sanitize($e), $consequence));
        $io->warning('Arrêt au premier échec. Relancer --apply est sans danger une fois la cause comprise.');

        return Command::FAILURE;
    }

    private function report(): CatalogHandoverReportDTO
    {
        $report = $this->queryBus->ask(new GetCatalogHandoverReportQuery());
        \assert($report instanceof CatalogHandoverReportDTO);

        return $report;
    }

    /**
     * L'amorçage des comptes avec les marqueurs RÉELS de la base (après la phase 1), ce que le moteur d'ING-008 lira pour écrire.
     */
    private function seedingReport(): SeedingReportDTO
    {
        $report = $this->queryBus->ask(new GetSeedingReportQuery());
        \assert($report instanceof SeedingReportDTO);

        return $report;
    }

    private function source(InputInterface $input): ?string
    {
        $source = $input->getOption('source') ?? 'rattrapage-' . $this->clock->now()->format('Y-m-d');

        return is_string($source) && mb_strlen($source) <= IngredientAlias::MAX_SOURCE_LENGTH && preg_match(self::SOURCE_PATTERN, $source) === 1 ? $source : null;
    }

    /**
     * Le message d'une erreur sans identifiant ni nom d'ingrédient personnel : une erreur de base de données cite volontiers la clé en cause
     * (`Key (slug)=(praline<identifiant>)`), qui contient l'identifiant d'un compte. Les sorties de cette commande sont conservées dans des
     * journaux : tout slug qui se termine par un identifiant est remplacé par `<id>`, et les valeurs d'un `Key (…)=(…)` sont effacées.
     */
    private function sanitize(Throwable $e): string
    {
        $message = preg_replace('/\\(([^()]*)\\)=\\(([^()]*)\\)/', '(…)=(…)', $e->getMessage()) ?? '';
        $message = preg_replace(self::ID_PATTERN, '<id>', $message) ?? '';

        return sprintf('%s : %s', $e::class, mb_strimwidth($message, 0, 600, '…'));
    }
}
