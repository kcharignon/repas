<?php

namespace Repas\Repas\Application\Command\SeedUserIngredients;


use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Repas\Application\Port\CatalogOwnerCommandPort;
use Repas\Repas\Application\Port\SeedingAccountCommandPort;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientAdoptionException;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Model\IngredientToSeed;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Amorce UN compte : lui copie les ingrédients « de base » du propriétaire du catalogue et repointe vers ces copies tout ce qui, chez lui,
 * les référençait (ADR 0028, amendement du 2026-10-20, `docs/ingredients/sans-commun.md` §§ 2 et 6). C'est l'algorithme d'ING-003
 * (`docs/ingredients/migration.md` § 7, `AdoptCommonIngredientsForUserHandler`, qui ne change pas) avec une autre SOURCE et un autre PÉRIMÈTRE,
 * dans la transaction du bus de commandes :
 *
 * 0. verrou consultatif de l'amorçage du compte ; le compte doit exister, ne pas être le propriétaire du catalogue (ses ingrédients sont
 *    les siens) et ne pas être déjà amorcé (au moins un alias d'adoption), sauf `complete` ; sinon rien n'est écrit, versions comprises ;
 * 1. la source : les ingrédients « de base » du catalogue, plus (rattrapage d'un compte qui a des données) ceux du catalogue que ses lignes
 *    référencent. Aucun ingrédient « de base » : rien n'est écrit, le compte reste non amorcé (un avertissement est journalisé) ;
 * 2. empreinte d'AVANT (Repas et drive) ; les listes envoyées au drive qui seraient modifiées exigent `acceptSent` ;
 * 3. les alias d'abord (ils arbitrent une activation simultanée), puis les copies et leurs conversions propres, en lots ;
 * 4. repointage des lignes de ses recettes et de ses listes (versions +1), puis de ses choix du drive : jamais l'un sans l'autre ;
 * 5. empreinte d'APRÈS comparée à celle d'avant : à la moindre différence, `IngredientAdoptionException`, donc ANNULATION de tout ce qui
 *    précède pour ce compte (la transaction n'est pas validée) — et, lancé par l'activation, de l'activation elle-même (décision D6).
 *
 * Aucun événement n'est émis et aucune statistique ne bouge : un amorçage n'est pas une création (ADR 0028 décision 7), et le handler ne
 * charge ni n'enregistre le compte (ADR 0013 règle 7 : l'activation vient de l'enregistrer). Aucune donnée n'est supprimée, les ingrédients du
 * catalogue ne sont jamais écrits. Rejouable : un compte déjà amorcé ne reçoit rien.
 */
#[AsMessageHandler]
readonly class SeedUserIngredientsHandler
{
    private const int MAX_SLUG_LENGTH = 255;

    public function __construct(
        private IngredientAdoptionBulkRepository $bulkRepository,
        private CatalogOwnerCommandPort $catalogOwner,
        private SeedingAccountCommandPort $accounts,
        private DriveIngredientReferences $driveReferences,
        private IngredientSlugPolicy $slugPolicy,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
        private AdoptionVerifier $verifier,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws IngredientAdoptionException des listes envoyées non acceptées, un slug trop long, une empreinte qui diverge
     */
    public function __invoke(SeedUserIngredientsCommand $command): SeedResult
    {
        $userId = $command->userId;

        $this->bulkRepository->lockSeeding($userId);

        $ownerId = $this->catalogOwner->ownerId();
        if ($userId === $ownerId || !$this->accounts->exists($userId)) {
            return SeedResult::of(SeedOutcome::NOT_CONCERNED);
        }
        if (!$command->complete && $this->bulkRepository->isSeeded($userId)) {
            return SeedResult::of(SeedOutcome::ALREADY_SEEDED);
        }
        if ($ownerId === null) {
            $this->logger->warning('Amorçage des ingrédients impossible : le propriétaire du catalogue n\'existe pas. Rien n\'est écrit, le compte reste non amorcé.');

            return SeedResult::of(SeedOutcome::NO_CATALOG);
        }

        // La source (`sans-commun.md` § 2.2) : les ingrédients de base du catalogue, plus ceux que les lignes du compte référencent
        $driveSlugs = $this->driveReferences->referencedSlugs($userId);
        $toSeed = $this->bulkRepository->ingredientsToSeed($userId, $ownerId, $driveSlugs);
        if (!$command->complete && array_filter($toSeed, static fn(IngredientToSeed $ingredient): bool => $ingredient->isBase) === []) {
            // Le classement n'est pas posé : copier seulement ce que les lignes du compte référencent le rendrait « amorcé » avec une
            // partie du catalogue, et il ne serait jamais servi pour le reste. On n'écrit rien, il sera rattrapé.
            $this->logger->warning('Amorçage des ingrédients impossible : aucun ingrédient « de base » dans le catalogue. Rien n\'est écrit, le compte reste non amorcé.');

            return SeedResult::of(SeedOutcome::NO_CATALOG);
        }
        // `complete` répare aussi : une ligne ou un choix du drive resté sur l'ORIGINE d'une adoption que le compte a déjà (écriture concurrente
        // d'un vieil onglet) est repointé sur sa copie, comme le fait la migration d'ING-003 quand on la rejoue
        $adoptedOrigins = $command->complete ? array_keys($this->bulkRepository->adoptions($userId)) : [];
        if ($toSeed === [] && $adoptedOrigins === []) {
            return SeedResult::of(SeedOutcome::NOTHING_TO_ADD);
        }

        $sourceSlugs = array_values(array_unique([...array_map(static fn(IngredientToSeed $ingredient): string => $ingredient->slug, $toSeed), ...$adoptedOrigins]));
        $before = $this->bulkRepository->snapshot($userId, $sourceSlugs);
        $statisticsBefore = $this->accounts->statisticsFingerprint($userId);
        $driveBefore = $this->driveReferences->fingerprint($userId);
        if ($toSeed === [] && $before->watchedRows() === 0 && array_intersect($driveBefore->ingredientSlugByChoice, $adoptedOrigins) === []) {
            return SeedResult::of(SeedOutcome::NOTHING_TO_ADD);
        }

        $sentLists = $before->sentShoppingListsToRepoint();
        if ($sentLists > 0 && !$command->acceptSent) {
            throw IngredientAdoptionException::sentShoppingListsNotAccepted($sentLists);
        }

        // Les copies : les alias d'abord, puis les ingrédients, puis leurs conversions propres. Un ingrédient du compte déjà né de la source
        // et strictement conforme (copie de recette qui a retenu l'origine) est désigné par l'alias au lieu d'être doublé.
        $copySlugs = [];
        $reusedSlugs = [];
        $referencedOnly = [];
        $collisions = 0;
        $taken = array_fill_keys($this->bulkRepository->takenSlugs($userId), true);
        foreach ($toSeed as $ingredient) {
            if ($ingredient->existingCopySlug !== null) {
                $copySlugs[$ingredient->slug] = $reusedSlugs[$ingredient->slug] = $ingredient->existingCopySlug;
                continue;
            }
            $slug = $this->slugPolicy->forAdoption($ingredient->name, $userId, static fn(string $candidate): bool => isset($taken[$candidate]));
            if (strlen($slug) > self::MAX_SLUG_LENGTH) {
                throw IngredientAdoptionException::seedSlugTooLong(strlen($slug));
            }
            $taken[$slug] = true;
            $copySlugs[$ingredient->slug] = $slug;
            $referencedOnly[$ingredient->slug] = !$ingredient->isBase;
            $collisions += $slug === $this->slugPolicy->forAdoption($ingredient->name, $userId, []) ? 0 : 1;
        }

        $written = $copySlugs === [] ? [] : $this->bulkRepository->writeAliases($userId, $copySlugs, $command->source, $this->clock->now());
        $conversionsCopied = 0;
        $created = array_diff_key($written, $reusedSlugs);
        if ($created !== []) {
            $this->bulkRepository->copyCatalogIngredients($userId, $ownerId, $created);
            $copies = [];
            foreach ($this->bulkRepository->ownConversionsOf(array_keys($created)) as $conversion) {
                $copies[] = ['sourceId' => $conversion['id'], 'newId' => $this->uuidGenerator->generate(), 'copySlug' => $created[$conversion['commonSlug']]];
            }
            $conversionsCopied = $copies === [] ? 0 : $this->bulkRepository->copyConversions($copies);
        }

        // Le repointage : recettes et listes ensemble (deux requêtes, une transaction), puis les choix du drive
        $adoptions = $this->bulkRepository->adoptions($userId);
        $repointed = $this->bulkRepository->repointToCopies($userId);
        $driveChoices = $this->driveReferences->repoint($userId, $adoptions);

        // Les vérifications, DANS la transaction : un défaut annule tout pour ce compte (et l'activation qui l'a demandé)
        $after = $this->bulkRepository->snapshot($userId, $sourceSlugs);
        $statisticsAfter = $this->accounts->statisticsFingerprint($userId);
        $driveAfter = $this->driveReferences->fingerprint($userId);
        $problems = $this->verifier->discrepancies($before, $after, $statisticsBefore, $statisticsAfter, $driveBefore, $driveAfter, $adoptions);
        $stillOnSource = count(array_intersect($driveAfter->ingredientSlugByChoice, $sourceSlugs));
        if ($stillOnSource > 0) {
            $problems[] = sprintf('%d choix du drive portent encore le slug d\'un ingrédient copié', $stillOnSource);
        }
        if (($nonConforming = $this->bulkRepository->nonConformingCopies($userId, $written)) !== []) {
            $problems[] = sprintf('%d copie(s) ne sont pas conformes à leur origine', count($nonConforming));
        }
        if ($this->bulkRepository->ingredientsToSeed($userId, $ownerId, $driveSlugs) !== []) {
            $problems[] = 'des ingrédients du catalogue restent sans copie valide';
        }
        if ($problems !== []) {
            throw IngredientAdoptionException::verificationFailed($problems);
        }

        return new SeedResult(
            outcome: SeedOutcome::SEEDED,
            seeded: count($created),
            seededBecauseReferenced: count(array_filter(array_intersect_key($referencedOnly, $created))),
            reused: count($written) - count($created),
            slugCollisions: $collisions,
            conversionsCopied: $conversionsCopied,
            repointed: $repointed,
            driveChoicesRepointed: $driveChoices,
            sentShoppingListsRepointed: $sentLists,
            fingerprint: $after->contentFingerprint(),
        );
    }
}
