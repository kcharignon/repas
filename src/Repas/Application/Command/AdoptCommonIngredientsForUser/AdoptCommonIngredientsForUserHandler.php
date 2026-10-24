<?php

namespace Repas\Repas\Application\Command\AdoptCommonIngredientsForUser;


use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientAdoptionException;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Adopte pour un utilisateur tous les communs qu'il n'a pas adoptés, et repointe tout ce qui les référençait chez lui. L'algorithme de
 * `docs/ingredients/migration.md` § 7, dans la transaction du bus de commandes :
 *
 * 0. verrou consultatif de l'utilisateur ; il doit être dans le périmètre (non administrateur, au moins un ingrédient personnel ou une
 *    recette), sinon rien n'est fait ;
 * 1. empreinte d'AVANT (Repas et drive) ; les listes envoyées au drive qui seraient modifiées exigent `acceptSent` ;
 * 2. les alias d'abord (ils arbitrent une adoption simultanée), puis les copies et leurs conversions propres, en lots ;
 * 3. repointage des lignes de ses recettes et de ses listes (versions +1), puis de ses choix du drive : jamais l'un sans l'autre ;
 * 4. empreinte d'APRÈS comparée à celle d'avant : à la moindre différence, `IngredientAdoptionException`, donc ANNULATION de tout ce
 *    qui précède pour cet utilisateur (la transaction n'est pas validée).
 *
 * Aucun événement n'est émis et aucune statistique ne bouge : une adoption n'est pas une création (ADR 0028 décision 7). Aucune donnée
 * n'est supprimée, les communs ne sont ni lus pour être modifiés ni touchés. Rejouable : un utilisateur déjà migré ne reçoit rien et
 * rien n'est écrit.
 */
#[AsMessageHandler]
readonly class AdoptCommonIngredientsForUserHandler
{
    private const int MAX_SLUG_LENGTH = 255;

    public function __construct(
        private IngredientAdoptionBulkRepository $bulkRepository,
        private DriveIngredientReferences $driveReferences,
        private IngredientSlugPolicy $slugPolicy,
        private UuidGenerator $uuidGenerator,
        private ClockInterface $clock,
        private AdoptionVerifier $verifier,
    ) {
    }

    /**
     * @throws IngredientAdoptionException des listes envoyées non acceptées, un slug trop long, une empreinte qui diverge
     */
    public function __invoke(AdoptCommonIngredientsForUserCommand $command): AdoptionResult
    {
        $userId = $command->userId;

        $this->bulkRepository->lock($userId);
        if (!$this->bulkRepository->isConcerned($userId)) {
            return AdoptionResult::notConcerned();
        }

        $before = $this->bulkRepository->snapshot($userId);
        $driveBefore = $this->driveReferences->fingerprint($userId);

        $sentLists = $before->sentShoppingListsToRepoint();
        if ($sentLists > 0 && !$command->acceptSent) {
            throw IngredientAdoptionException::sentShoppingListsNotAccepted($sentLists);
        }

        // Les copies : les alias d'abord, puis les ingrédients, puis leurs conversions propres. Un ingrédient de l'utilisateur déjà né
        // du commun et strictement conforme à lui (retour arrière d'un lancement) est désigné par l'alias au lieu d'être doublé.
        $copySlugs = [];
        $reusedSlugs = [];
        $collisions = 0;
        $taken = array_fill_keys($this->bulkRepository->takenSlugs($userId), true);
        foreach ($this->bulkRepository->commonsToAdopt($userId) as $common) {
            if ($common->existingCopySlug !== null) {
                $copySlugs[$common->slug] = $reusedSlugs[$common->slug] = $common->existingCopySlug;
                continue;
            }
            $slug = $this->slugPolicy->forAdoption($common->name, $userId, static fn(string $candidate): bool => isset($taken[$candidate]));
            if (strlen($slug) > self::MAX_SLUG_LENGTH) {
                throw IngredientAdoptionException::slugTooLong($common->slug, strlen($slug));
            }
            $taken[$slug] = true;
            $copySlugs[$common->slug] = $slug;
            $collisions += $slug === $this->slugPolicy->forAdoption($common->name, $userId, []) ? 0 : 1;
        }

        $written = $copySlugs === [] ? [] : $this->bulkRepository->writeAliases($userId, $copySlugs, $command->source, $this->clock->now());
        $conversionsCopied = 0;
        $created = array_diff_key($written, $reusedSlugs);
        if ($created !== []) {
            $this->bulkRepository->copyIngredients($userId, $created);
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

        // Les vérifications, DANS la transaction : un défaut annule tout pour cet utilisateur
        $after = $this->bulkRepository->snapshot($userId);
        $driveAfter = $this->driveReferences->fingerprint($userId);
        $problems = $this->verifier->discrepancies($before, $after, $driveBefore, $driveAfter, $adoptions);
        $stillOnAdopted = count(array_intersect($driveAfter->ingredientSlugByChoice, array_keys($adoptions)));
        if ($stillOnAdopted > 0) {
            $problems[] = sprintf('%d choix du drive portent encore le slug d\'un commun adopté', $stillOnAdopted);
        }
        if (($nonConforming = $this->bulkRepository->nonConformingCopies($userId, $written)) !== []) {
            $problems[] = sprintf('%d copie(s) ne sont pas conformes à leur commun', count($nonConforming));
        }
        if ($this->bulkRepository->commonsToAdopt($userId) !== []) {
            $problems[] = 'des communs restent sans adoption valide';
        }
        if ($problems !== []) {
            throw IngredientAdoptionException::verificationFailed($problems);
        }

        return new AdoptionResult(
            concerned: true,
            adopted: count($created),
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
