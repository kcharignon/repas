<?php

namespace Repas\Repas\Application\Command\PlannedMeal;


use Repas\Repas\Application\Service\ActiveListForType;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ListRoutingRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Planifie une recette : chaque produit va dans la liste de SA destination (ADR 0036 décision 3 : le choix fait sur le produit, sinon le type de
 * son rayon, sinon la liste principale), lue en UNE requête quel que soit le nombre de produits. Chaque liste qui reçoit au moins un produit reçoit
 * un repas (recette, nombre de personnes) et ses lignes ; si la recette en remplit plusieurs, leurs repas partagent `planned_group_id` et chacun
 * garde les produits qu'il a posés (`Meal::getPlacedIngredients`, figés ici : changer une destination ensuite ne déplace aucune ligne).
 *
 * - Un compte sans type, ou dont tous les produits vont dans la liste principale : la liste principale seule, un repas sans groupe, exactement comme
 *   avant les types.
 * - Une liste de type sans liste en cours est ouverte à la demande, nommée comme le type (jamais une liste envoyée au drive : elle n'est pas en cours).
 *   La liste principale absente reste une erreur (`activeShoppingListNotFound`), jamais ouverte en silence.
 * - TOUT est jugé avant la première écriture (décision 10) : une liste visée qui contient déjà la recette, les quantités à convertir, la version de la
 *   liste principale (`If-Match` ne vaut que pour elle, décision 12). Le bus des commandes est transactionnel : ce qui ne se sait qu'en écrivant (le
 *   plafond de listes à l'ouverture d'une liste de type) annule aussi tout.
 */
#[AsMessageHandler]
readonly class PlannedMealHandler
{
    public function __construct(
        private ShoppingListRepository $shoppingListRepository,
        private RecipeRepository $recipeRepository,
        private ConversionService $conversionService,
        private UuidGenerator $uuidGenerator,
        private ListRoutingRepository $listRoutingRepository,
        private ActiveListForType $activeListForType,
    ) {
    }

    /**
     * @throws ShoppingListException aucune liste principale en cours alors qu'un produit y va, la recette est déjà dans une liste visée, ou une liste est pleine
     * @throws ShoppingListTypeException le type d'une destination n'est plus à l'utilisateur
     * @throws IngredientException un ingrédient de la recette ne se convertit pas dans son unité d'achat
     * @throws RecipeException la recette est inconnue, ou n'est pas celle du propriétaire
     */
    public function __invoke(PlannedMealCommand $command): PlannedMealResult
    {
        return $this->plan($this->recipeRepository->findOneById($command->recipeId), $command);
    }

    /**
     * Planifie une recette DÉJÀ chargée : la commande vocale a lu la recette pour juger sa phrase et ne la relit pas (deux lectures de moins).
     * `$command->recipeId` est celui de `$recipe`.
     *
     * @throws ShoppingListException aucune liste principale en cours alors qu'un produit y va, la recette est déjà dans une liste visée, ou une liste est pleine
     * @throws ShoppingListTypeException le type d'une destination n'est plus à l'utilisateur
     * @throws IngredientException un ingrédient de la recette ne se convertit pas dans son unité d'achat
     * @throws RecipeException la recette n'est pas celle du propriétaire
     */
    public function plan(Recipe $recipe, PlannedMealCommand $command): PlannedMealResult
    {
        // Une liste ne planifie que les recettes de son propriétaire (la recette d'un autre est une recette inconnue), avant de lire ou d'ouvrir quoi que ce soit
        if ($recipe->getAuthorId() !== $command->ownerId) {
            throw RecipeException::notFound($recipe->getId());
        }

        // On récupere les Ingredients de la recette dans les bonnes proportions
        $rows = $recipe->getRowForServing($command->defaultServing);

        // La destination de chaque produit, en une lecture ; les lignes sont rangées par destination (`''` pour la liste principale), la liste principale d'abord
        $destinations = $this->listRoutingRepository->destinationsFor(
            $command->ownerId,
            array_values(array_unique($rows->map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug())->toArray())),
        );
        $mainRows = [];
        $typedRows = [];
        foreach ($rows as $row) {
            $typeId = $destinations[$row->getIngredient()->getSlug()] ?? null;
            if ($typeId === null) {
                $mainRows[] = $row;
            } else {
                $typedRows[$typeId][] = $row;
            }
        }
        // Une recette sans produit va dans la liste principale, comme avant ; sinon elle ne la touche que si un produit y va
        $rowsByDestination = ($mainRows !== [] || $rows->count() === 0 ? ['' => $mainRows] : []) + $typedRows;

        // Les listes en cours visées, jugées avant toute écriture
        $lists = [];
        foreach (array_keys($rowsByDestination) as $key) {
            $typeId = $key === '' ? null : (string) $key;
            $list = $this->shoppingListRepository->findOneActiveByOwnerAndType($command->ownerId, $typeId);
            // Si aucune liste active on ne peut pas ajouter de repas, sauf si l'appelant demande de créer la liste principale (commande vocale)
            if (!$list instanceof ShoppingList && $typeId === null && $command->newMainListId === null) {
                throw ShoppingListException::activeShoppingListNotFound();
            }
            $lists[$key] = $list;
        }
        foreach ($lists as $list) {
            if (!$list instanceof ShoppingList) {
                continue;
            }
            // `If-Match` ne vaut que pour la liste principale (ADR 0036 décision 12)
            if ($list->getListTypeId() === null) {
                $list->assertVersion($command->expectedVersion);
            }
            $list->assertCanAddMeal($recipe);
        }

        // On calcule la quantité dans l'unité d'achat : une conversion manquante refuse tout avant d'avoir ouvert ou modifié une liste
        $this->conversionService->preload($rows);
        $quantities = [];
        foreach ($rowsByDestination as $key => $destinationRows) {
            foreach ($destinationRows as $row) {
                $quantities[$key][] = $this->conversionService->convertRecipeRowToPurchaseUnit($row);
            }
        }

        // Les listes de type sans liste en cours sont ouvertes à la demande, nommées comme leur type ; la liste principale n'est créée que sur demande
        // de l'appelant (la commande vocale), jamais en silence
        $mainListCreated = false;
        foreach ($lists as $key => $list) {
            if (!$list instanceof ShoppingList) {
                $isMain = $key === '';
                $lists[$key] = $this->activeListForType->open($command->ownerId, $isMain ? null : (string) $key, $isMain ? $command->newMainListId : null)->shoppingList;
                $mainListCreated = $mainListCreated || $isMain;
            }
        }

        // Un seul repas : il pose toute la recette, sans groupe. Plusieurs : un groupe, et chacun garde les produits qu'il a posés (décision 5)
        $groupId = count($lists) > 1 ? $this->uuidGenerator->generate() : null;

        $planned = [];
        foreach ($lists as $key => $list) {
            $slugs = array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $rowsByDestination[$key]);
            $list->addMeal($this->uuidGenerator->generate(), $recipe, $command->defaultServing, $groupId, $groupId === null ? null : $slugs);

            foreach ($rowsByDestination[$key] as $index => $row) {
                // On ajoute cette quantité dans la liste d'achat
                $list->addRow($row->getIngredient()->getSlug(), $row->getIngredient()->getDefaultPurchaseUnit()->getSlug(), $quantities[$key][$index]);
            }

            $this->shoppingListRepository->save($list);
            $planned[] = new PlannedMealList($list->getId(), $list->getName(), $list->getCreatedAt(), $list->getListTypeId());
        }

        return new PlannedMealResult($planned, $mainListCreated);
    }
}
