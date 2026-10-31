<?php

namespace Repas\Repas\Application\Command\AddSpokenIngredient;

use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListCommand;
use Repas\Repas\Application\Command\CreateShoppingList\CreateShoppingListHandler;
use Repas\Repas\Application\Service\ActiveListForType;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\SpokenListName;
use Repas\Repas\Application\Service\SpokenLineQuantity;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Model\AddedItem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ajoute un article dit à la voix : à la liste de SA destination (ADR 0036 décision 3 : le choix fait sur le produit, sinon le type de son rayon, sinon
 * la liste principale). La liste principale garde son comportement d'avant les types : la liste active, sinon une liste neuve (la liste envoyée au drive
 * n'est jamais modifiée). Une liste de type est la liste en cours de ce type, ouverte à la demande si elle manque (LST-008) ; le résultat dit son nom
 * (`AddedItem::$listName`). Comme
 * `AddIngredientToShoppingListHandler` (alias résolus, ingrédient d'un autre = 404), mais sans refuser l'absence de liste active, et avec la
 * quantité et l'unité dites (ADR 0029 décision 5, amendée le 2026-10-25) :
 *
 * - la quantité (1 à 99) est celle de l'unité de la ligne ; sans unité dite, c'est l'unité de la ligne (celle de la première ligne de cet
 *   ingrédient dans la liste, à défaut son unité d'achat) ;
 * - l'unité dite n'est utilisée que si l'ingrédient la connaît (son unité d'achat ou une de ses unités compatibles) ; la ligne n'a qu'une unité,
 *   donc si la ligne existe déjà dans une autre, la quantité dite est CONVERTIE dans l'unité de la ligne quand une conversion existe, sinon
 *   ajoutée telle quelle ; une unité inconnue de l'ingrédient est laissée de côté (`SpokenLineQuantity`, la même règle que le retrait). Dans ces
 *   trois cas `AddedItem::unitSubstituted` le dit à Alexa ;
 * - le résultat donne la quantité TOTALE de la ligne après l'ajout (`addRow` additionne à la ligne existante), son unité, et si la ligne existait déjà.
 *
 * L'ingrédient et l'unité dite ne sont lus que lorsqu'une unité est dite et diffère de celle de la ligne : l'ajout ordinaire ne coûte qu'une
 * lecture de l'unité de la ligne (son nom).
 */
#[AsMessageHandler]
readonly class AddSpokenIngredientHandler
{
    private SpokenLineQuantity $lineQuantity;

    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private IngredientRepository $ingredientRepository,
        private IngredientSlugResolver $slugResolver,
        private CreateShoppingListHandler $createShoppingListHandler,
        private UnitRepository $unitRepository,
        ConversionService $conversionService,
        private ListRoutingRepository $listRoutingRepository,
        private ActiveListForType $activeListForType,
        private SpokenListName $listName,
    ) {
        $this->lineQuantity = new SpokenLineQuantity($ingredientRepository, $unitRepository, $conversionService);
    }

    /**
     * @throws ShoppingListException
     * @throws IngredientException
     */
    public function __invoke(AddSpokenIngredientCommand $command): AddedItem
    {
        if ($command->quantity < AddSpokenIngredientCommand::MIN_QUANTITY || $command->quantity > AddSpokenIngredientCommand::MAX_QUANTITY) {
            throw ShoppingListException::invalidSpokenQuantity($command->quantity);
        }

        // Un ancien slug (ancien commun dont l'utilisateur a reçu une copie, ingrédient fusionné) est remplacé par l'ingrédient actuel
        // de l'utilisateur (ADR 0028 décision 4)
        $ingredientSlug = $this->slugResolver->resolve($command->ownerId, [$command->ingredientSlug])[$command->ingredientSlug] ?? $command->ingredientSlug;
        // L'ingrédient d'un autre ou inconnu est refusé (404) avant toute écriture, y compris la création de la liste
        // La destination du produit (ADR 0036 décision 3) et son unité d'achat, en une lecture : un produit inconnu ou à un autre est refusé (404)
        $placement = $this->listRoutingRepository->placementsFor($command->ownerId, [$ingredientSlug])[$ingredientSlug] ?? throw IngredientException::notFound($ingredientSlug);
        $purchaseUnitSlug = $placement->purchaseUnitSlug;

        $listCreated = false;
        $previousListSent = false;
        $spokenListName = null;
        if ($placement->listTypeId !== null) {
            // La liste en cours de son type, ouverte à la demande et nommée comme lui si elle manque (jamais une liste envoyée au drive : elle n'est
            // pas en cours) : Alexa nomme la liste, il n'y a ni « nouvelle liste » ni liste précédente envoyée à dire
            $shoppingList = $this->activeListForType->forType($command->ownerId, $placement->listTypeId)->shoppingList;
            $spokenListName = $this->listName->ofTyped($shoppingList->getName(), $placement->listTypeId);
        } else {
            $shoppingList = $this->shoppingListRepository->findOneActivateByOwner($command->ownerId);
            $listCreated = !$shoppingList instanceof ShoppingList;
            if ($listCreated) {
                $previousListSent = $this->shoppingListRepository->hasSentShoppingList($command->ownerId);
                // Une seule fois, et seulement sans liste active : la création met en pause la liste active qui existerait
                ($this->createShoppingListHandler)(new CreateShoppingListCommand($command->newShoppingListId, $command->ownerId));
                $shoppingList = $this->shoppingListRepository->findOneById($command->newShoppingListId);
            }
        }

        $existingRow = $shoppingList->findRow($ingredientSlug);
        [$unitSlug, $quantity, $substituted] = $this->lineQuantity->inLineUnit($ingredientSlug, $command->quantity, $command->unitSlug, $existingRow?->getUnitSlug(), $purchaseUnitSlug);

        $shoppingList->addIngredient($ingredientSlug, $unitSlug, $quantity);
        $shoppingList->addRow($ingredientSlug, $unitSlug, $quantity);

        $this->shoppingListRepository->save($shoppingList);

        return new AddedItem(
            listCreated: $listCreated,
            previousListSent: $previousListSent,
            quantity: $shoppingList->findRow($ingredientSlug)?->getQuantity() ?? $quantity,
            unitSlug: $unitSlug,
            unitName: $this->unitRepository->findOneBySlug($unitSlug)->getName(),
            lineExisted: $existingRow !== null,
            unitSubstituted: $substituted,
            addedQuantity: $quantity,
            listName: $spokenListName,
        );
    }
}
