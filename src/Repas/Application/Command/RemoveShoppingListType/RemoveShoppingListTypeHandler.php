<?php

namespace Repas\Repas\Application\Command\RemoveShoppingListType;

use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Supprime un type de liste sans rien perdre (ADR 0036 décision 15), en une seule transaction (le bus des commandes) : les produits qui
 * le désignaient redeviennent « comme leur rayon », ses listes n'ont plus de type et celle qui est en cours passe en pause, puis ses
 * rattachements de rayons et lui-même disparaissent. Aucune ligne de liste n'est déplacée ni fusionnée.
 */
#[AsMessageHandler]
readonly class RemoveShoppingListTypeHandler
{
    public function __construct(
        private ShoppingListTypeRepository $listTypeRepository,
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
    ) {
    }

    /**
     * @throws ShoppingListTypeException le type n'est pas à l'utilisateur (inconnu, un administrateur compris)
     */
    public function __invoke(RemoveShoppingListTypeCommand $command): void
    {
        $listType = $this->listTypeRepository->findOneById($command->id);
        $listType->assertOwnedBy($command->userId);

        $this->ingredientRepository->clearListDestination($command->userId, $listType->getId());
        $this->shoppingListRepository->detachFromListType($command->userId, $listType->getId());
        $this->listTypeRepository->delete($listType);
    }
}
