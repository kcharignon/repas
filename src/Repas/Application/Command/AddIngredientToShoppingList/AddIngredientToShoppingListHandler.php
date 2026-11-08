<?php

namespace Repas\Repas\Application\Command\AddIngredientToShoppingList;

use Repas\Repas\Application\Service\ActiveListForType;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\PartAwareLines;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute 1 à un produit dans la liste de SA destination (ADR 0036 décisions 3 et 11) : le choix fait sur le produit, sinon le type de son rayon,
 * sinon la liste principale. Une liste de type sans liste en cours est ouverte à la demande, nommée comme le type (jamais une liste envoyée au
 * drive) ; la liste principale absente reste une erreur (`activeShoppingListNotFound`). `If-Match` ne vaut que pour la liste principale (décision 12).
 *
 * La destination et l'unité d'achat du produit viennent d'une seule lecture (`ListRoutingRepository::placementsFor`) : un compte sans type ne
 * paie aucune requête de plus qu'avant les types.
 *
 * Une PARTIE d'ingrédient (« Jaune d'œuf », ADR 0038) n'est jamais une ligne : le placement dit alors celui de son parent (`partOf`), et 1 est ajouté à
 * la partie, donc au registre de la liste du parent. Un ingrédient ordinaire fait exactement les mêmes écritures qu'avant.
 */
#[AsMessageHandler]
readonly class AddIngredientToShoppingListHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientSlugResolver $slugResolver,
        private ListRoutingRepository $listRoutingRepository,
        private ActiveListForType $activeListForType,
        private PartAwareLines $partLines,
    ) {
    }

    /**
     * @throws ShoppingListException
     * @throws ShoppingListTypeException
     * @throws IngredientException
     */
    public function __invoke(AddIngredientToShoppingListCommand $command): void
    {
        // Un ancien slug (ancien commun dont le propriétaire a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel
        // du propriétaire (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;

        // Un produit inconnu ou à un autre n'a pas de placement : la liste principale, puis l'ingrédient est refusé comme inconnu (404)
        $placement = $this->listRoutingRepository->placementsFor($command->ownerId, [$ingredientSlug])[$ingredientSlug] ?? null;

        $shoppingListActive = $this->activeListForType->forType($command->ownerId, $placement?->listTypeId)->shoppingList;
        if ($shoppingListActive->getListTypeId() === null) {
            $shoppingListActive->assertVersion($command->expectedVersion);
        }

        if ($placement === null) {
            throw IngredientException::notFound($ingredientSlug);
        }
        if ($placement->partOf !== null) {
            // Une partie (ADR 0038 décision 11) n'est jamais une ligne : on lui ajoute 1, qui va au registre de la liste de son parent et fait grandir
            // la ligne du parent si besoin
            $this->partLines->prepareBySlug($ingredientSlug, 1.0)->addTo($shoppingListActive);
            $this->shoppingListRepository->save($shoppingListActive);

            return;
        }

        $purchaseUnitSlug = $placement->purchaseUnitSlug;
        $shoppingListActive->addIngredient($ingredientSlug, $purchaseUnitSlug);

        $shoppingListActive->addRow($ingredientSlug, $purchaseUnitSlug, 1);

        $this->shoppingListRepository->save($shoppingListActive);
    }
}
