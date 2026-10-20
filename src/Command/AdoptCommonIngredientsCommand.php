<?php

namespace Repas\Command;


use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserCommand;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptionResult;
use Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser\UndoCommonIngredientsAdoptionForUserCommand;
use Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser\UndoResult;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionReportDTO;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionUserReportDTO;
use Repas\Repas\Application\Query\GetAdoptionReport\GetAdoptionReportQuery;
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
 * Migration ING-003 (ADR 0028, `docs/ingredients/migration.md`) : chaque compte CONCERNÉ (non administrateur, avec au moins un ingrédient
 * personnel ou une recette) reçoit sa propre copie de chaque commun, avec ses conversions propres, et tout ce qui référençait un commun
 * chez lui (lignes de ses recettes et de ses listes, listes envoyées au drive comprises, choix du drive) pointe vers sa copie. Les communs
 * restent, intacts. Rien n'est jamais supprimé. Une transaction par compte, rejouable.
 *
 * Ici, seulement la boucle et l'affichage : le travail est la commande applicative `AdoptCommonIngredientsForUser` (bus transactionnel),
 * le rapport est la requête `GetAdoptionReport`. La sortie n'a jamais d'adresse e-mail, d'identifiant de compte ni de nom d'ingrédient
 * personnel : les comptes sont numérotés et désignés par une référence courte (SHA-256).
 *
 * Modes : sans option, rapport À BLANC (lecture seule) ; `--check`, lecture seule aussi, avec le verdict de la vérification (« rien à
 * faire » après un lancement) ; `--apply` écrit ; `--undo=<lancement> --apply` défait les données d'un lancement. Lancée par l'humain avec
 * une sauvegarde (`.claude/skills/repas-ops/references/adopter-ingredients.sh`), JAMAIS par un agent de code en production.
 */
#[AsCommand(
    name: 'repas:ingredients:adopt-commons',
    description: "Migration ING-003 : chaque compte concerné reçoit sa copie des ingrédients communs (à blanc par défaut, --apply pour écrire)",
)]
final class AdoptCommonIngredientsCommand extends Command
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
            ->addOption('apply', null, InputOption::VALUE_NONE, "Écrit (une transaction par compte). Sans cette option, rien n'est modifié.")
            ->addOption('check', null, InputOption::VALUE_NONE, "Lecture seule : le rapport et la vérification d'après lancement (code de sortie 0 si et seulement s'il n'y a plus rien à faire)")
            ->addOption('accept-sent', null, InputOption::VALUE_NONE, 'Autorise le repointage des listes déjà envoyées au drive (immuables pour l\'API) ; obligatoire dès que l\'une est concernée')
            ->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Se limite à ce compte (référence courte du rapport ou identifiant complet), répétable')
            ->addOption('except', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclut ce compte (mêmes formes), répétable')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, "Nom du lancement, écrit dans ingredient_alias.source (64 caractères au plus ; défaut migration-AAAA-MM-JJ)")
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'Synonyme de --source')
            ->addOption('undo', null, InputOption::VALUE_REQUIRED, "Retour arrière des DONNÉES du lancement indiqué (avec --apply) : repointe vers les communs, efface ses alias, laisse les copies")
            ->addOption('keep-going', null, InputOption::VALUE_NONE, "En cas d'échec d'un compte (transaction annulée pour lui), continue avec les suivants au lieu de s'arrêter");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $check = (bool) $input->getOption('check');
        $undo = $input->getOption('undo');
        if ($apply && $check) {
            $io->error('--apply et --check s\'excluent : --check ne fait que lire.');

            return Command::INVALID;
        }
        $source = $this->source($input);
        if ($source === null) {
            $io->error(sprintf('Nom de lancement invalide : lettres, chiffres, « . _ : - », %d caractères au plus.', IngredientAlias::MAX_SOURCE_LENGTH));

            return Command::INVALID;
        }
        if ($undo !== null && (!is_string($undo) || mb_strlen($undo) > IngredientAlias::MAX_SOURCE_LENGTH || preg_match(self::SOURCE_PATTERN, $undo) !== 1)) {
            $io->error('Nom de lancement à défaire invalide.');

            return Command::INVALID;
        }
        if ($undo !== null && !$apply) {
            $io->error("Le retour arrière écrit : ajoute --apply. Rien n'a été modifié.");

            return Command::INVALID;
        }
        if ($apply && !in_array($this->environment, $this->allowedEnvironments, true)) {
            $io->error(sprintf("Écriture refusée dans l'environnement « %s » (permis : %s). Rien n'a été modifié.", $this->environment, implode(', ', $this->allowedEnvironments)));

            return Command::INVALID;
        }

        $report = $this->queryBus->ask(new GetAdoptionReportQuery($this->selectors($input, 'user'), $this->selectors($input, 'except')));
        \assert($report instanceof AdoptionReportDTO);
        if ($report->unknownSelectors !== []) {
            $io->error(sprintf('Aucun compte ne correspond à : %s. Rien n\'a été modifié.', implode(', ', $report->unknownSelectors)));

            return Command::INVALID;
        }

        $io->title(match (true) {
            $undo !== null => sprintf('Retour arrière du lancement « %s »', $undo),
            $apply => sprintf('Adoption des ingrédients communs : lancement « %s »', $source),
            $check => 'Adoption des ingrédients communs : vérification (lecture seule)',
            default => 'Adoption des ingrédients communs : à blanc (lecture seule)',
        });
        $this->render($io, $report);

        if ($undo !== null) {
            return $this->undo($io, $report, $undo, (bool) $input->getOption('accept-sent'), (bool) $input->getOption('keep-going'));
        }
        if (!$apply) {
            return $this->verdict($io, $report, $check);
        }

        return $this->apply($io, $input, $report, $source);
    }

    /**
     * Mode à blanc et `--check` : lecture seule. Le code de sortie dit s'il reste quelque chose à faire (`--check`) ou si une anomalie
     * interdirait d'écrire.
     */
    private function verdict(SymfonyStyle $io, AdoptionReportDTO $report, bool $check): int
    {
        $io->newLine();
        if ($report->hasBlockingAnomalies()) {
            $io->error("Anomalies bloquantes : --apply refusera d'écrire tant qu'elles ne sont pas réglées. Rien n'a été modifié.");

            return Command::FAILURE;
        }
        if ($report->sentShoppingListsToRepoint() > 0) {
            $io->warning(sprintf("%d liste(s) déjà envoyée(s) au drive seraient repointées : --apply exigera --accept-sent.", $report->sentShoppingListsToRepoint()));
        }

        if ($report->isNothingToDo()) {
            $io->success('Rien à faire : 0 à adopter, 0 à repointer, aucun alias invalide. Rien n\'a été modifié.');

            return Command::SUCCESS;
        }
        $io->text($check ? 'Il reste à faire (voir ci-dessus). Rien n\'a été modifié.' : 'À blanc : rien n\'a été modifié.');

        return $check ? Command::FAILURE : Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, InputInterface $input, AdoptionReportDTO $report, string $source): int
    {
        $acceptSent = (bool) $input->getOption('accept-sent');
        if ($report->hasBlockingAnomalies()) {
            $io->error('Anomalies bloquantes : écriture refusée. Rien n\'a été modifié.');

            return Command::FAILURE;
        }
        if ($report->sentShoppingListsToRepoint() > 0 && !$acceptSent) {
            $io->error(sprintf(
                "%d liste(s) déjà envoyée(s) au drive seraient repointées (immuables pour l'API, ADR 0023) : relance avec --accept-sent si l'envoi est terminé. Rien n'a été modifié.",
                $report->sentShoppingListsToRepoint(),
            ));

            return Command::FAILURE;
        }

        $failed = 0;
        $processed = 0;
        $started = hrtime(true);
        foreach ($report->selected() as $user) {
            if ($user->isNothingToDo()) {
                $io->text(sprintf('Compte n°%d [%s] : 0 à adopter, 0 à repointer, rien à faire.', $user->rank, $user->reference));
                continue;
            }

            try {
                $result = $this->commandBus->dispatch(new AdoptCommonIngredientsForUserCommand($user->userId, $source, $acceptSent));
                \assert($result instanceof AdoptionResult);
            } catch (Throwable $e) {
                ++$failed;
                $io->error(sprintf('Compte n°%d [%s] : ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit. %s', $user->rank, $user->reference, $this->sanitize($e)));
                if (!$input->getOption('keep-going')) {
                    $io->warning('Arrêt au premier échec (relance avec --keep-going pour continuer avec les comptes suivants). Les comptes déjà traités le restent ; relancer --apply est sans danger.');

                    return Command::FAILURE;
                }
                continue;
            }

            ++$processed;
            $io->text($this->describe($user, $result));
        }

        // Vérification d'après lancement : le même rapport, relu. Les empreintes de contenu doivent être celles d'avant.
        $after = $this->queryBus->ask(new GetAdoptionReportQuery($this->selectors($input, 'user'), $this->selectors($input, 'except')));
        \assert($after instanceof AdoptionReportDTO);
        $changed = [];
        foreach ($report->selected() as $user) {
            foreach ($after->users as $now) {
                if ($now->userId === $user->userId && $now->fingerprint !== $user->fingerprint) {
                    $changed[] = $user->rank;
                }
            }
        }
        $io->newLine();
        $io->section('Vérification d\'après lancement');
        $this->render($io, $after, true);
        $io->text(sprintf('Durée : %.1f s, %d compte(s) traité(s).', (hrtime(true) - $started) / 1e9, $processed));

        if ($failed > 0 || $changed !== []) {
            if ($changed !== []) {
                $io->error(sprintf("L'empreinte du contenu a changé pour le(s) compte(s) n°%s depuis le rapport d'avant (ce peut être une modification faite par l'utilisateur pendant le lancement : relire avant de conclure).", implode(', n°', $changed)));
            }

            return Command::FAILURE;
        }
        if (!$after->isNothingToDo()) {
            $io->error("Il reste quelque chose à faire après l'écriture : voir le rapport ci-dessus.");

            return Command::FAILURE;
        }
        $io->success('Rien à faire : 0 à adopter, 0 à repointer, empreintes identiques à celles d\'avant.');

        return Command::SUCCESS;
    }

    private function undo(SymfonyStyle $io, AdoptionReportDTO $report, string $run, bool $acceptSent, bool $keepGoing): int
    {
        $failed = 0;
        foreach ($report->selected() as $user) {
            try {
                $result = $this->commandBus->dispatch(new UndoCommonIngredientsAdoptionForUserCommand($user->userId, $run, $acceptSent));
                \assert($result instanceof UndoResult);
            } catch (Throwable $e) {
                ++$failed;
                $io->error(sprintf('Compte n°%d [%s] : ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit. %s', $user->rank, $user->reference, $this->sanitize($e)));
                if (!$keepGoing) {
                    return Command::FAILURE;
                }
                continue;
            }

            $io->text(sprintf(
                'Compte n°%d [%s] : %d adoption(s) défaite(s), %d copie(s) modifiée(s) depuis laissée(s) telle(s) quelle(s) ; repointé : %d ligne(s) de recettes, %d de listes, %d ingrédient(s) de listes, %d choix du drive. Les copies restent.',
                $user->rank,
                $user->reference,
                $result->undone,
                $result->keptBecauseModified,
                $result->repointed->recipeRows,
                $result->repointed->shoppingListRows,
                $result->repointed->shoppingListIngredients,
                $result->driveChoicesRepointed,
            ));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function describe(AdoptionUserReportDTO $user, AdoptionResult $result): string
    {
        if (!$result->concerned) {
            return sprintf('Compte n°%d [%s] : plus concerné au moment de l\'écriture, ignoré.', $user->rank, $user->reference);
        }

        return sprintf(
            'Compte n°%d [%s] : %d copie(s) créée(s) (dont %d à slug suffixé) et %d déjà présente(s) désignée(s), %d conversion(s) copiée(s) ; repointé : %d ligne(s) de %d recette(s), %d ligne(s) + %d ingrédient(s) de %d liste(s) dont %d envoyée(s), %d choix du drive ; empreinte %s identique.',
            $user->rank,
            $user->reference,
            $result->adopted,
            $result->slugCollisions,
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

    private function render(SymfonyStyle $io, AdoptionReportDTO $report, bool $compact = false): void
    {
        $io->text(sprintf(
            'Catalogue : %d communs (jamais modifiés), %d conversions propres, slug de commun le plus long : %d caractères.',
            $report->commons,
            $report->commonOwnConversions,
            $report->longestCommonSlug,
        ));
        foreach ($report->catalogAnomalies as $anomaly) {
            $io->text('Anomalie du catalogue (sans effet sur la migration) : ' . $anomaly);
        }
        $io->text(sprintf(
            'Comptes : %d dont %d administrateur(s) et %d retenu(s) pour la migration.',
            count($report->users),
            count(array_filter($report->users, static fn(AdoptionUserReportDTO $user): bool => $user->administrator)),
            count($report->selected()),
        ));
        $io->newLine();

        $totals = ['adopt' => 0, 'collisions' => 0, 'conversions' => 0, 'recipeRows' => 0, 'listRows' => 0, 'listIngredients' => 0, 'sent' => 0, 'choices' => 0];
        foreach ($report->users as $user) {
            if ($compact) {
                // Après lancement : une ligne par compte retenu, ce qui reste à faire et l'empreinte
                if ($user->selected) {
                    $io->text(sprintf(
                        'Compte n°%d [%s] : %d à adopter, %d ligne(s) et %d choix du drive à repointer, %d alias invalide(s), empreinte du contenu %s.',
                        $user->rank,
                        $user->reference,
                        $user->toAdopt,
                        $user->linesToRepoint(),
                        $user->driveChoicesToRepoint,
                        $user->invalidAliases,
                        $user->fingerprint,
                    ));
                    foreach ($user->blockingAnomalies as $anomaly) {
                        $io->text('    ANOMALIE : ' . $anomaly);
                    }
                    $totals['adopt'] += $user->toAdopt;
                    $totals['recipeRows'] += $user->recipeRowsToRepoint;
                    $totals['listRows'] += $user->shoppingListRowsToRepoint;
                    $totals['listIngredients'] += $user->shoppingListIngredientsToRepoint;
                    $totals['choices'] += $user->driveChoicesToRepoint;
                }
                continue;
            }
            $io->text($this->header($user));
            if (!$user->selected) {
                continue;
            }
            $io->text(sprintf(
                '    à adopter : %d commun(s) (déjà adoptés : %d), dont %d slug(s) à suffixer%s ; %d conversion(s) propre(s) à copier',
                $user->toAdopt,
                $user->alreadyAdopted,
                $user->slugCollisions,
                $user->collidingCommons === [] ? '' : ' (' . implode(', ', $user->collidingCommons) . ')',
                $user->conversionsToCopy,
            ));
            $io->text(sprintf(
                '    à repointer : %d ligne(s) dans %d recette(s) ; %d ligne(s) + %d ingrédient(s) dans %d liste(s) dont %d envoyée(s) au drive ; %d choix du drive (%d produit(s)) sur %d choix et %d produit(s) au total',
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
            $io->text(sprintf('    empreinte du contenu (identique avant et après) : %s', $user->fingerprint));
            if ($user->invalidAliases > 0) {
                $io->text(sprintf('    ANOMALIE : %d alias d\'adoption invalide(s) (copie absente, à un autre compte ou d\'un autre commun)', $user->invalidAliases));
            }
            foreach ($user->blockingAnomalies as $anomaly) {
                $io->text('    ANOMALIE BLOQUANTE : ' . $anomaly);
            }

            $totals['adopt'] += $user->toAdopt;
            $totals['collisions'] += $user->slugCollisions;
            $totals['conversions'] += $user->conversionsToCopy;
            $totals['recipeRows'] += $user->recipeRowsToRepoint;
            $totals['listRows'] += $user->shoppingListRowsToRepoint;
            $totals['listIngredients'] += $user->shoppingListIngredientsToRepoint;
            $totals['sent'] += $user->sentShoppingListsToRepoint;
            $totals['choices'] += $user->driveChoicesToRepoint;
        }

        $io->newLine();
        $io->text(sprintf(
            'Totaux : %d copie(s) à créer (dont %d à slug suffixé), %d conversion(s) à copier ; à repointer : %d ligne(s) de recettes, %d ligne(s) et %d ingrédient(s) de listes (dont %d liste(s) envoyée(s)), %d choix du drive.',
            $totals['adopt'],
            $totals['collisions'],
            $totals['conversions'],
            $totals['recipeRows'],
            $totals['listRows'],
            $totals['listIngredients'],
            $totals['sent'],
            $totals['choices'],
        ));
    }

    private function header(AdoptionUserReportDTO $user): string
    {
        $status = match ($user->status) {
            'ACTIVE' => 'actif',
            'PENDING' => 'en attente',
            'DISABLED' => 'désactivé',
            default => strtolower($user->status),
        };
        $owns = sprintf(
            '%d ingrédient(s) personnel(s) + %d copie(s) d\'adoption, %d recette(s) dont %d copiée(s)',
            $user->personalIngredients,
            $user->adoptedCopies,
            $user->recipes,
            $user->copiedRecipes,
        );

        return match ($user->exclusion) {
            null => sprintf('Compte n°%d [%s] (%s) : RETENU — %s', $user->rank, $user->reference, $status, $owns),
            AdoptionUserReportDTO::ADMINISTRATOR => sprintf('Compte n°%d [%s] (%s) : ignoré, administrateur (il garde les communs) — %s', $user->rank, $user->reference, $status, $owns),
            AdoptionUserReportDTO::NOTHING_OWNED => sprintf('Compte n°%d [%s] (%s) : ignoré, ni ingrédient personnel ni recette (il garde les communs partagés)', $user->rank, $user->reference, $status),
            AdoptionUserReportDTO::EXCEPTED => sprintf('Compte n°%d [%s] (%s) : écarté par --except — %s', $user->rank, $user->reference, $status, $owns),
            default => sprintf('Compte n°%d [%s] (%s) : hors de --user — %s', $user->rank, $user->reference, $status, $owns),
        };
    }

    private function source(InputInterface $input): ?string
    {
        $source = $input->getOption('source');
        $run = $input->getOption('run');
        if ($source !== null && $run !== null && $source !== $run) {
            return null;
        }
        $source ??= $run ?? 'migration-' . $this->clock->now()->format('Y-m-d');

        return is_string($source) && mb_strlen($source) <= IngredientAlias::MAX_SOURCE_LENGTH && preg_match(self::SOURCE_PATTERN, $source) === 1 ? $source : null;
    }

    /**
     * @return list<string>
     */
    private function selectors(InputInterface $input, string $option): array
    {
        $values = $input->getOption($option);

        return is_array($values) ? array_values(array_filter(array_map('strval', $values), static fn(string $value): bool => $value !== '')) : [];
    }

    /**
     * Le message d'une erreur sans identifiant ni nom d'ingrédient personnel : une erreur de base de données cite volontiers la clé en
     * cause (`Key (slug)=(praline<identifiant>)`), qui contient l'identifiant d'un compte et le nom d'un ingrédient. Les sorties de cette
     * commande sont conservées dans des journaux : tout slug qui se termine par un identifiant est remplacé par `<id>`, et les valeurs
     * d'un `Key (…)=(…)` sont effacées.
     */
    private function sanitize(Throwable $e): string
    {
        $message = preg_replace('/\\(([^()]*)\\)=\\(([^()]*)\\)/', '(…)=(…)', $e->getMessage()) ?? '';
        $message = preg_replace(self::ID_PATTERN, '<id>', $message) ?? '';

        return sprintf('%s : %s', $e::class, mb_strimwidth($message, 0, 400, '…'));
    }
}
