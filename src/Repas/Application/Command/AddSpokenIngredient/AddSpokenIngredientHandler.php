<?php

namespace Repas\Repas\Application\Command\AddSpokenIngredient;

use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Shared\Application\Interface\AddedItem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute un article dit à la voix : à la liste active, sinon à une liste neuve (la liste envoyée au drive n'est jamais modifiée). Comme
 * `AddIngredientToShoppingListHandler` (alias résolus, ingrédient d'un autre = 404), mais sans refuser l'absence de liste active.
 */
#[AsMessageHandler]
readonly class AddSpokenIngredientHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientSlugResolver $slugResolver,
        private CreateShoppingListHandler $createShoppingListHandler,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(AddSpokenIngredientCommand $command): AddedItem
    {
        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel
        // de l'utilisateur (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        // L'ingrédient d'un autre ou inconnu est refusé (404) avant toute écriture, y compris la création de la liste
        $purchaseUnitSlug = $this->ingredientRepository->findPurchaseUnitSlugUsableBy($ingredientSlug, $command->ownerId);

        $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
        $listCreated = !$shoppingList instanceof ShoppingList;
        $previousListSent = false;
        if ($listCreated) {
            $previousListSent = $this->shoppingListRepository->hasSentShoppingList($command->ownerId);
            // Une seule fois, et seulement sans liste active : la création met en pause la liste active qui existerait
            ($this->createShoppingListHandler)(new CreateShoppingListCommand($command->newShoppingListId, $command->ownerId));
            $shoppingList = $this->shoppingListRepository->findOneById($command->newShoppingListId);
        }

        $shoppingList->addIngredient($ingredientSlug, $purchaseUnitSlug);
        $shoppingList->addRow($ingredientSlug, $purchaseUnitSlug, 1);

        $this->shoppingListRepository->save($shoppingList);

        return new AddedItem(listCreated: $listCreated, previousListSent: $previousListSent);
    }
}
