<?php

namespace Repas\Repas\Infrastructure\Console;


use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedOutcome;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedResult;
use Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand;
use Repas\Repas\Application\Query\GetSeedingReport\GetSeedingReportQuery;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingHealthDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingUserReportDTO;
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
 * Rattrapage de l'amorçage des ingrédients (ING-008, ADR 0028 amendement du 2026-10-20, `docs/ingredients/sans-commun.md` §§ 2, 5 et 6) :
 * chaque compte ACTIF qui n'a pas encore ses ingrédients (le compte vide d'avant la passation, un compte activé quand l'interrupteur
 * `INGREDIENT_SEEDING_ENABLED` était coupé) reçoit une copie des ingrédients « de base » du propriétaire du catalogue, plus ceux de ce
 * catalogue que ses données référencent, avec leurs conversions propres ; tout ce qui les référençait chez lui (lignes de recettes et de
 * listes, listes envoyées au drive comprises, choix du drive) pointe vers sa copie. Le catalogue n'est jamais modifié, rien n'est jamais
 * supprimé, un compte déjà amorcé n'est jamais touché (sauf `--complete`, sur demande explicite pour un compte). Une transaction par
 * compte, rejouable. Elle marche interrupteur coupé : l'interrupteur ne commande que l'amorçage automatique à l'activation.
 *
 * Ici, seulement la boucle et l'affichage : le travail d'un compte est la commande applicative `SeedUserIngredients` (bus transactionnel),
 * le rapport est la requête `GetSeedingReport`. La sortie n'a jamais d'adresse e-mail, d'identifiant de compte ni de nom d'ingrédient
 * personnel : les comptes sont numérotés et désignés par une référence courte (SHA-256).
 *
 * Modes : sans option, rapport À BLANC (lecture seule) ; `--check`, lecture seule aussi, la requête de SANTÉ (code de sortie 0 si et
 * seulement si plus rien n'est à faire et si tout est conforme, 1 s'il reste à faire, 3 si seule la santé est rouge) ; `--apply` écrit. Lancée par l'humain avec une sauvegarde
 * (`.claude/skills/repas-ops/references/amorcer-ingredients.sh`), JAMAIS par un agent de code en production.
 */
#[AsCommand(
    name: 'repas:ingredients:seed',
    description: "Rattrapage ING-008 : chaque compte actif non amorcé reçoit les ingrédients « de base » (à blanc par défaut, --apply pour écrire, --check pour la santé)",
)]
final class SeedIngredientsCommand extends Command
{
    /**
     * Code de sortie de `--check` quand il n'y a plus rien à écrire pour la sélection mais que la requête de santé est rouge (un compte en attente
     * ou désactivé qui a des lignes sur le catalogue, par exemple) : le script d'exploitation n'a alors ni sauvegarde ni écriture à faire, et le dit.
     * Les codes de `--check` : 0 tout est conforme, 1 il reste à faire (ou une anomalie bloque), 3 seule la santé est rouge.
     */
    public const int EXIT_HEALTH_RED = 3;

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
            ->addOption('check', null, InputOption::VALUE_NONE, "Lecture seule : le rapport et la requête de santé (code de sortie 0 si et seulement si plus rien n'est à faire et tout est conforme, 1 s'il reste à faire, 3 si seule la santé est rouge)")
            ->addOption('accept-sent', null, InputOption::VALUE_NONE, "Autorise le repointage des listes déjà envoyées au drive (immuables pour l'API) ; obligatoire dès que l'une est concernée")
            ->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Se limite à ce compte (référence courte du rapport ou identifiant complet), de n'importe quel statut ; répétable")
            ->addOption('except', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclut ce compte (mêmes formes), répétable')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Nom du lancement, écrit dans ingredient_alias.source (64 caractères au plus ; défaut rattrapage-AAAA-MM-JJ)')
            ->addOption('keep-going', null, InputOption::VALUE_NONE, "En cas d'échec d'un compte (transaction annulée pour lui), continue avec les suivants au lieu de s'arrêter")
            ->addOption('complete', null, InputOption::VALUE_NONE, "Complète aussi un compte DÉJÀ amorcé avec ce que le catalogue a de plus (exige --user ; jamais sur tous les comptes)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $check = (bool) $input->getOption('check');
        $complete = (bool) $input->getOption('complete');
        if ($apply && $check) {
            $io->error('--apply et --check s\'excluent : --check ne fait que lire.');

            return Command::INVALID;
        }
        if ($complete && $this->selectors($input, 'user') === []) {
            $io->error('--complete exige --user : on ne complète jamais tous les comptes, seulement un compte désigné. Rien n\'a été modifié.');

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

        $report = $this->report($input);
        if ($report->unknownSelectors !== []) {
            $io->error(sprintf('Aucun compte ne correspond à : %s. Rien n\'a été modifié.', implode(', ', $report->unknownSelectors)));

            return Command::INVALID;
        }

        $io->title(match (true) {
            $apply => sprintf('Amorçage des ingrédients : lancement « %s »', $source),
            $check => 'Amorçage des ingrédients : vérification (lecture seule)',
            default => 'Amorçage des ingrédients : à blanc (lecture seule)',
        });
        $this->render($io, $report);

        if (!$apply) {
            return $this->verdict($io, $report, $check);
        }

        return $this->apply($io, $input, $report, $source, $complete);
    }

    /**
     * Mode à blanc et `--check` : lecture seule. Le code de sortie dit s'il reste quelque chose à faire ou si la santé est rouge (`--check`), ou
     * si une anomalie interdirait d'écrire.
     */
    private function verdict(SymfonyStyle $io, SeedingReportDTO $report, bool $check): int
    {
        $io->newLine();
        if ($report->hasBlockingAnomalies()) {
            $io->error("Anomalies bloquantes : --apply refusera d'écrire tant qu'elles ne sont pas réglées. Rien n'a été modifié.");

            return Command::FAILURE;
        }
        if ($report->sentShoppingListsToRepoint() > 0) {
            $io->warning(sprintf("%d liste(s) déjà envoyée(s) au drive seraient repointées : --apply exigera --accept-sent.", $report->sentShoppingListsToRepoint()));
        }

        if ($report->isConform()) {
            $io->success('Rien à faire : 0 à copier, 0 à repointer, la requête de santé est verte. Rien n\'a été modifié.');

            return Command::SUCCESS;
        }
        if ($check) {
            if ($report->isNothingToDo()) {
                $io->error('Rien à écrire pour cette sélection, mais la requête de santé n\'est pas verte (voir ci-dessus). Rien n\'a été modifié.');

                return self::EXIT_HEALTH_RED;
            }
            $io->error('Il reste à faire (voir ci-dessus). Rien n\'a été modifié.');

            return Command::FAILURE;
        }
        $io->text('À blanc : rien n\'a été modifié.');

        return Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, InputInterface $input, SeedingReportDTO $report, string $source, bool $complete): int
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
        $changed = [];
        $started = hrtime(true);
        foreach ($report->selected() as $user) {
            if ($user->isNothingToDo()) {
                $io->text(sprintf('Compte n°%d [%s] : 0 à copier, 0 à repointer, rien à faire.', $user->rank, $user->reference));
                continue;
            }

            try {
                $result = $this->commandBus->dispatch(new SeedUserIngredientsCommand($user->userId, $source, $acceptSent, $complete));
                \assert($result instanceof SeedResult);
            } catch (Throwable $e) {
                ++$failed;
                $io->error(sprintf('Compte n°%d [%s] : ÉCHEC, transaction annulée pour ce compte, rien n\'est écrit. %s', $user->rank, $user->reference, $this->sanitize($e)));
                if (!$input->getOption('keep-going')) {
                    $io->warning('Arrêt au premier échec (relance avec --keep-going pour continuer avec les comptes suivants). Les comptes déjà traités le restent ; relancer --apply est sans danger.');

                    return Command::FAILURE;
                }
                continue;
            }

            if ($result->outcome === SeedOutcome::NO_CATALOG) {
                ++$failed;
                $io->error(sprintf('Compte n°%d [%s] : rien n\'a pu être copié (pas de propriétaire du catalogue ou aucun ingrédient « de base »). Il reste non amorcé.', $user->rank, $user->reference));
                continue;
            }
            if (!$result->wroteSomething()) {
                $io->text(sprintf('Compte n°%d [%s] : plus concerné au moment de l\'écriture (%s), ignoré.', $user->rank, $user->reference, $result->outcome->value));
                continue;
            }

            ++$processed;
            $io->text($this->describe($user, $result));
            if ($result->fingerprint !== $user->fingerprint) {
                $changed[] = $user->rank;
            }
        }

        // Vérification d'après lancement : le même rapport, relu. Plus rien à copier ni à repointer, et la requête de santé.
        $after = $this->report($input);
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
        $io->success('Rien à faire : 0 à copier, 0 à repointer, empreintes identiques à celles d\'avant.');
        if (!$after->health->isHealthy()) {
            // Le travail demandé est fait ; la santé est une porte à part (--check), que le script d'exploitation rejoue en dernier. Un compte en
            // attente ou désactivé qui a des lignes sur le catalogue, par exemple, n'est servi que sur demande (--user) : il rend la santé rouge.
            $io->warning("La requête de santé n'est pas verte (voir ci-dessus) : ce n'est pas forcément à cause de ce lancement, lance --check pour la lire en entier.");
        }

        return Command::SUCCESS;
    }

    private function describe(SeedingUserReportDTO $user, SeedResult $result): string
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

    private function render(SymfonyStyle $io, SeedingReportDTO $report, bool $compact = false): void
    {
        $io->text(sprintf(
            'Catalogue : %d ingrédient(s) du propriétaire dont %d « de base » (jamais modifiés), %d conversion(s) propre(s) de ces ingrédients de base, slug le plus long : %d caractères.',
            $report->catalogIngredients,
            $report->baseIngredients,
            $report->baseOwnConversions,
            $report->longestBaseSlug,
        ));
        foreach ($report->catalogAnomalies as $anomaly) {
            $io->text('Anomalie du catalogue : ' . $anomaly);
        }
        $io->text(sprintf(
            'Comptes : %d dont %d propriétaire du catalogue et %d retenu(s) pour l\'amorçage.',
            count($report->users),
            count(array_filter($report->users, static fn(SeedingUserReportDTO $user): bool => $user->owner)),
            count($report->selected()),
        ));
        $io->newLine();

        $totals = ['seed' => 0, 'collisions' => 0, 'conversions' => 0, 'recipeRows' => 0, 'listRows' => 0, 'listIngredients' => 0, 'sent' => 0, 'choices' => 0];
        foreach ($report->users as $user) {
            if ($compact) {
                // Après lancement : une ligne par compte retenu, ce qui reste à faire et l'empreinte
                if ($user->selected) {
                    $io->text(sprintf(
                        'Compte n°%d [%s] : %d à copier, %d ligne(s) et %d choix du drive à repointer, empreinte du contenu %s.',
                        $user->rank,
                        $user->reference,
                        $user->toSeed,
                        $user->linesToRepoint(),
                        $user->driveChoicesToRepoint,
                        $user->fingerprint,
                    ));
                    foreach ($user->blockingAnomalies as $anomaly) {
                        $io->text('    ANOMALIE : ' . $anomaly);
                    }
                }
                continue;
            }
            $io->text($this->header($user));
            if (!$user->selected) {
                continue;
            }
            $io->text(sprintf(
                '    à copier : %d ingrédient(s) dont %d « de base » et %d demandé(s) par ses lignes (déjà présents et désignés sans copie : %d), dont %d slug(s) à suffixer ; %d conversion(s) propre(s) à copier',
                $user->toSeed,
                $user->toSeedBase,
                $user->toSeedReferenced,
                $user->reusable,
                $user->slugCollisions,
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
            foreach ($user->blockingAnomalies as $anomaly) {
                $io->text('    ANOMALIE BLOQUANTE : ' . $anomaly);
            }

            $totals['seed'] += $user->toSeed;
            $totals['collisions'] += $user->slugCollisions;
            $totals['conversions'] += $user->conversionsToCopy;
            $totals['recipeRows'] += $user->recipeRowsToRepoint;
            $totals['listRows'] += $user->shoppingListRowsToRepoint;
            $totals['listIngredients'] += $user->shoppingListIngredientsToRepoint;
            $totals['sent'] += $user->sentShoppingListsToRepoint;
            $totals['choices'] += $user->driveChoicesToRepoint;
        }

        if (!$compact) {
            $io->newLine();
            $io->text(sprintf(
                'Totaux : %d copie(s) à créer (dont %d à slug suffixé), %d conversion(s) à copier ; à repointer : %d ligne(s) de recettes, %d ligne(s) et %d ingrédient(s) de listes (dont %d liste(s) envoyée(s)), %d choix du drive.',
                $totals['seed'],
                $totals['collisions'],
                $totals['conversions'],
                $totals['recipeRows'],
                $totals['listRows'],
                $totals['listIngredients'],
                $totals['sent'],
                $totals['choices'],
            ));
        }
        $this->renderHealth($io, $report->health);
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
            ? sprintf('    lignes du propriétaire sur un ingrédient qui n\'est pas à lui : sans objet (pas de propriétaire du catalogue)')
            : sprintf('    lignes du propriétaire sur un ingrédient qui n\'est pas à lui : %s', $lines($health->ownerForeignLines)));
        $io->text(sprintf('    ingrédients sans propriétaire existant : %d', $health->ingredientsWithoutCreator));
        $io->text('    verdict : ' . ($health->isHealthy() ? 'VERT' : 'ROUGE'));
    }

    private function header(SeedingUserReportDTO $user): string
    {
        $status = match ($user->status) {
            'ACTIVE' => 'actif',
            'PENDING' => 'en attente',
            'DISABLED' => 'désactivé',
            default => strtolower($user->status),
        };
        $owns = sprintf('%d ingrédient(s), %d recette(s), %s', $user->ingredients, $user->recipes, $user->seeded ? 'amorcé' : 'non amorcé');

        return match ($user->exclusion) {
            null => sprintf('Compte n°%d [%s] (%s) : RETENU — %s', $user->rank, $user->reference, $status, $owns),
            SeedingUserReportDTO::OWNER => sprintf('Compte n°%d [%s] (%s) : ignoré, propriétaire du catalogue (ses ingrédients sont les siens) — %s', $user->rank, $user->reference, $status, $owns),
            SeedingUserReportDTO::NOT_ACTIVE => sprintf('Compte n°%d [%s] (%s) : ignoré, pas actif (il sera servi à son activation) — %s', $user->rank, $user->reference, $status, $owns),
            SeedingUserReportDTO::ALREADY_SEEDED => sprintf('Compte n°%d [%s] (%s) : ignoré, déjà amorcé — %s', $user->rank, $user->reference, $status, $owns),
            SeedingUserReportDTO::EXCEPTED => sprintf('Compte n°%d [%s] (%s) : écarté par --except — %s', $user->rank, $user->reference, $status, $owns),
            default => sprintf('Compte n°%d [%s] (%s) : hors de --user — %s', $user->rank, $user->reference, $status, $owns),
        };
    }

    private function report(InputInterface $input): SeedingReportDTO
    {
        $report = $this->queryBus->ask(new GetSeedingReportQuery(
            $this->selectors($input, 'user'),
            $this->selectors($input, 'except'),
            (bool) $input->getOption('complete'),
        ));
        \assert($report instanceof SeedingReportDTO);

        return $report;
    }

    private function source(InputInterface $input): ?string
    {
        $source = $input->getOption('source') ?? 'rattrapage-' . $this->clock->now()->format('Y-m-d');

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
