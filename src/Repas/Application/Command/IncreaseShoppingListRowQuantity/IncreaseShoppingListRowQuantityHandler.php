<?php

namespace Repas\Repas\Application\Command\IncreaseShoppingListRowQuantity;

use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListRowException;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\ShoppingListRowRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute 1 à une LIGNE précise d'une liste, depuis l'écran de la liste : la ligne est désignée par son identifiant, donc c'est la liste qui la porte qui
 * change, jamais une autre (le geste par ingrédient, `…/active/ingredients/{slug}/increase`, va dans la liste de la DESTINATION du produit, qui a pu
 * changer depuis l'ajout, ADR 0036 décision 5). C'est le même geste que l'ajout à la main d'un produit : 1 de plus en unité d'achat, sur la ligne et dans ce
 * que la liste retient de l'ajout à la main. Une liste envoyée au drive ne bouge pas (`409 SHOPPING_LIST_SENT`).
 *
 * Une ligne n'est jamais une partie d'ingrédient (ADR 0038 : une partie se plie dans la ligne de son parent) : ajouter 1 à la ligne d'un parent
 * ajoute 1 à sa quantité propre, le registre des parties ne change pas.
 */
#[AsMessageHandler]
readonly class IncreaseShoppingListRowQuantityHandler
{
    public function __construct(
        private ShoppingListRowRepository $shoppingListRowRepository,
        private ShoppingListRepository $shoppingListRepository,
    ) {
    }

    /**
     * @throws ShoppingListRowException
     * @throws ShoppingListException
     */
    public function __invoke(IncreaseShoppingListRowQuantityCommand $command): void
    {
        $row = $this->shoppingListRowRepository->findOneById($command->shoppingListRowId);

        $shoppingList = $this->shoppingListRepository->findOneById($row->getShoppingListId());
        $shoppingList->assertVersion($command->expectedVersion);

        $shoppingList->addIngredient($row->getIngredientSlug(), $row->getUnitSlug());
        $shoppingList->addRow($row->getIngredientSlug(), $row->getUnitSlug(), 1);

        $this->shoppingListRepository->save($shoppingList);
    }
}
