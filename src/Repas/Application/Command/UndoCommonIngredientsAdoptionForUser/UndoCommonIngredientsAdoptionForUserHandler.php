<?php

namespace Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser;


use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientAdoptionException;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Défait, pour un utilisateur, les adoptions d'un lancement de la migration (`docs/ingredients/migration.md` § 10) : repointe vers le
 * commun d'origine les lignes de ses recettes et de ses listes et ses choix du drive qui référencent encore une copie du lancement,
 * puis efface les alias de ce lancement (le commun redevient visible pour lui). Les copies et leurs conversions RESTENT (inertes, l'ancien
 * code les lit comme des ingrédients personnels) : aucune suppression. Une copie que l'utilisateur a modifiée depuis n'est pas défaite
 * (elle porte maintenant son travail) ; la commande de console la liste. Mêmes empreintes que l'adoption, dans la même transaction.
 */
#[AsMessageHandler]
readonly class UndoCommonIngredientsAdoptionForUserHandler
{
    public function __construct(
        private IngredientAdoptionBulkRepository $bulkRepository,
        private DriveIngredientReferences $driveReferences,
        private AdoptionVerifier $verifier,
    ) {
    }

    /**
     * @throws IngredientAdoptionException des listes envoyées non acceptées, une empreinte qui diverge
     */
    public function __invoke(UndoCommonIngredientsAdoptionForUserCommand $command): UndoResult
    {
        $userId = $command->userId;

        $this->bulkRepository->lock($userId);

        $communBySlugOfCopy = [];
        $modified = 0;
        foreach ($this->bulkRepository->adoptionsOfRun($userId, $command->source) as $adoption) {
            if ($adoption->modified) {
                ++$modified;
                continue;
            }
            $communBySlugOfCopy[$adoption->copySlug] = $adoption->commonSlug;
        }
        if ($communBySlugOfCopy === []) {
            return new UndoResult(keptBecauseModified: $modified);
        }

        $before = $this->bulkRepository->snapshot($userId, array_keys($communBySlugOfCopy));
        $driveBefore = $this->driveReferences->fingerprint($userId);
        if (($sentLists = $before->sentShoppingListsToRepoint()) > 0 && !$command->acceptSent) {
            throw IngredientAdoptionException::sentShoppingListsNotAccepted($sentLists);
        }

        $repointed = $this->bulkRepository->repointToCommons($userId, $communBySlugOfCopy);
        $driveChoices = $this->driveReferences->repoint($userId, $communBySlugOfCopy);
        $deleted = $this->bulkRepository->deleteAdoptionAliases($userId, $command->source, array_values($communBySlugOfCopy));

        $after = $this->bulkRepository->snapshot($userId, array_keys($communBySlugOfCopy));
        $driveAfter = $this->driveReferences->fingerprint($userId);
        $problems = $this->verifier->discrepancies($before, $after, $driveBefore, $driveAfter, $communBySlugOfCopy);
        if ($deleted !== count($communBySlugOfCopy)) {
            $problems[] = sprintf('%d alias effacé(s) au lieu de %d', $deleted, count($communBySlugOfCopy));
        }
        if ($problems !== []) {
            throw IngredientAdoptionException::verificationFailed($problems);
        }

        return new UndoResult($deleted, $modified, $repointed, $driveChoices);
    }
}
