<?php

namespace Repas\Repas\Application\Command\RemoveIngredientToShoppingList;

use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Retire 1 à un produit : d'abord dans la liste de SA destination (ADR 0036 décisions 3 et 11), sinon dans n'importe quelle liste en cours qui en a
 * une ligne (la destination a pu changer depuis l'ajout, ce qui ne déplace aucune ligne, décision 5). Elle ne crée jamais de liste : sans liste en
 * cours qui convienne, c'est `activeShoppingListNotFound`. `If-Match` ne vaut que pour la liste principale (décision 12).
 *
 * Un compte sans type ne paie aucune requête de plus qu'avant les types : la destination et l'unité d'achat viennent d'une seule lecture.
 */
#[AsMessageHandler]
readonly class RemoveIngredientToShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientSlugResolver $slugResolver,
        private ListRoutingRepository $listRoutingRepository,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(RemoveIngredientToShoppingListCommand $command): void
    {
        // Un ancien slug (ancien commun dont le propriétaire a reçu une copie, ingrédient fusionné) désigne l'ingrédient actuel du
        // propriétaire (ADR 0028 décision 4). On ne fait que retrouver ce que la liste contient
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        // Un produit inconnu ou à un autre n'a pas de placement : la liste principale (une ligne d'avant la passation peut encore porter un commun)
        $placement = $this->listRoutingRepository->placementsFor($command->ownerId, [$ingredientSlug])[$ingredientSlug] ?? null;

        $activeShoppingList = $this->shoppingListRepository->findOneActiveByOwnerAndType($command->ownerId, $placement?->listTypeId);
        if (!$activeShoppingList instanceof ShoppingList || $activeShoppingList->findRow($ingredientSlug) === null) {
            $holding = $this->shoppingListRepository->findOneActiveWithRow($command->ownerId, $ingredientSlug);
            if ($holding instanceof ShoppingList) {
                $activeShoppingList = $holding;
            }
        }
        if (!$activeShoppingList instanceof ShoppingList) {
            throw ShoppingListException::activeShoppingListNotFound();
        }
        if ($activeShoppingList->getListTypeId() === null) {
            $activeShoppingList->assertVersion($command->expectedVersion);
        }

        $purchaseUnitSlug = $placement->purchaseUnitSlug ?? $this->ingredientRepository->findPurchaseUnitSlug($ingredientSlug);

        $activeShoppingList->removeIngredient($ingredientSlug, $purchaseUnitSlug);

        // On ajoute une quantité d'achat
        $activeShoppingList->subtractRow($ingredientSlug, 1);

        $this->shoppingListRepository->save($activeShoppingList);
    }

}
