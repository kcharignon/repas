<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\User\Domain\Model\User;

/**
 * Une recette éclatée entre des listes (ADR 0036, LST-005), en mémoire : de quoi planifier, changer les personnes et retirer une recette dont les
 * produits n'ont pas tous la même destination. Un propriétaire, ses types « Boucherie » (rayon de la viande) et « Crèmerie » (rayon du fromage), et
 * « Gâteau salé » pour 4 personnes :
 *
 *   - Farine 500 g (rayon boulangerie, achetée au kilo)  : aucun type, donc la liste principale
 *   - Œuf 2 (rayon divers)                               : la liste principale
 *   - Lardon 250 g (rayon viande, acheté au gramme)      : « Boucherie »
 *   - Beurre 125 g (rayon fromage, acheté en plaquette)  : « Crèmerie »
 *
 * Les quantités de la liste sont celles de la recette dans l'unité d'achat : 0,5 kg de farine, 2 œufs, 250 g de lardon, 0,5 plaquette de beurre
 * pour 4 personnes.
 */
final class SplitRecipeScenario
{
    public const int SERVING = 4;

    public User $owner;
    public Recipe $recipe;
    public ShoppingListType $butcher;
    public ShoppingListType $creamery;
    public Ingredient $flour;
    public Ingredient $egg;
    public Ingredient $bacon;
    public Ingredient $butter;
    public ShoppingListInMemoryRepository $lists;
    public IngredientInMemoryRepository $ingredients;
    public RecipeInMemoryRepository $recipes;
    public ConversionInMemoryRepository $conversionRepository;
    public ConversionService $conversions;
    public TypedLists $typed;

    /**
     * @param bool $withTypes faux : le même compte avant d'avoir créé le moindre type de liste
     */
    public function __construct(bool $withTypes = true)
    {
        $this->owner = new UserBuilder()->withId('owner-id')->withServing(self::SERVING)->build();
        $this->flour = new IngredientBuilder()->isFlour()->withCreator($this->owner)->build();
        $this->egg = new IngredientBuilder()->isEgg()->withCreator($this->owner)->build();
        $this->bacon = new IngredientBuilder()->isDicedBacon()->withCreator($this->owner)->build();
        $this->butter = new IngredientBuilder()->isButter()->withCreator($this->owner)->build();

        $this->recipe = new RecipeBuilder()
            ->withId('recipe-id')
            ->withName('Gâteau salé')
            ->withServing(self::SERVING)
            ->withAuthor($this->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($this->flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(500))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->egg)->withUnit(new UnitBuilder()->isUnite())->withQuantity(2))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->bacon)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->butter)->withUnit(new UnitBuilder()->isGramme())->withQuantity(125))
            ->build();

        $this->lists = new ShoppingListInMemoryRepository();
        $this->ingredients = new IngredientInMemoryRepository([$this->flour, $this->egg, $this->bacon, $this->butter]);
        $this->recipes = new RecipeInMemoryRepository([$this->recipe]);
        $this->conversionRepository = new ConversionInMemoryRepository([
            new ConversionBuilder()->isKiloToGramme()->build(),
            new ConversionBuilder()->isBlockToGrammeForButter()->build(),
        ]);
        $this->conversions = new ConversionService($this->conversionRepository, new UnitInMemoryRepository());
        $this->typed = new TypedLists($this->lists, $this->ingredients);
        $this->butcher = ShoppingListType::create('butcher-type', $this->owner->getId(), 'Boucherie', new \DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE));
        $this->creamery = ShoppingListType::create('creamery-type', $this->owner->getId(), 'Crèmerie', new \DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE));
        if ($withTypes) {
            $this->butcher = $this->typed->type('butcher-type', $this->owner->getId(), 'Boucherie', ['viande']);
            $this->creamery = $this->typed->type('creamery-type', $this->owner->getId(), 'Crèmerie', ['fromage']);
        }
    }

    public function planned(): PlannedMealHandler
    {
        return new PlannedMealHandler($this->lists, $this->recipes, $this->conversions, new UuidGenerator(), $this->typed->routing, $this->typed->activeListForType);
    }

    public function serving(): UpdateServingMealHandler
    {
        return new UpdateServingMealHandler($this->lists, $this->recipes, $this->conversions, $this->typed->group);
    }

    public function removal(): removeMealFromPlanHandler
    {
        return new removeMealFromPlanHandler($this->lists, $this->recipes, $this->conversions, $this->typed->group);
    }

    public function command(int $serving = self::SERVING, ?int $expectedVersion = null): PlannedMealCommand
    {
        return new PlannedMealCommand($this->owner->getId(), $this->recipe->getId(), $serving, $expectedVersion);
    }

    /**
     * Une liste en cours (ou du statut demandé) du propriétaire, vide, enregistrée. `$typeId` : un type, ou `null` pour la liste principale.
     */
    public function list(string $id, ?string $typeId = null, ShoppingListStatus $status = ShoppingListStatus::ACTIVE, int $version = 1): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withId($id)->withOwner($this->owner)->withListTypeId($typeId)->withVersion($version);
        if ($status === ShoppingListStatus::SENT) {
            $builder->addIngredient(new IngredientBuilder()->isEgg());
        }
        if ($typeId !== null) {
            $builder->withName($typeId === $this->butcher->getId() ? 'Boucherie' : 'Crèmerie');
        }
        $list = $builder->withStatus($status)->build();
        $this->lists->save($list);

        return $list;
    }

    /**
     * Les listes principale, Boucherie et Crèmerie en cours, nommées `main`, `butcher` et `creamery`.
     *
     * @return array{main: ShoppingList, butcher: ShoppingList, creamery: ShoppingList}
     */
    public function openLists(): array
    {
        return [
            'main' => $this->list('main'),
            'butcher' => $this->list('butcher', $this->butcher->getId()),
            'creamery' => $this->list('creamery', $this->creamery->getId()),
        ];
    }

    /**
     * La recette est déjà planifiée dans les trois listes en cours (`openLists()`), comme `PlannedMealHandler` le fait.
     *
     * @return array{main: ShoppingList, butcher: ShoppingList, creamery: ShoppingList}
     */
    public function plannedEverywhere(int $serving = self::SERVING): array
    {
        $lists = $this->openLists();
        ($this->planned())($this->command($serving));

        return $lists;
    }

    /**
     * Les lignes d'une liste : `slug => quantité`, ordre des slugs.
     *
     * @return array<string, float>
     */
    public function rows(ShoppingList $list): array
    {
        $rows = [];
        foreach ($list->getRows() as $row) {
            /** @var ShoppingListRow $row */
            $rows[$row->getIngredientSlug()] = $row->getQuantity();
        }
        ksort($rows);

        return $rows;
    }

    /**
     * Le repas de la recette dans la liste, `null` sans repas.
     */
    public function meal(ShoppingList $list): ?Meal
    {
        return $list->mealOf($this->recipe);
    }

    /**
     * La liste enregistrée, relue (une liste ouverte par le cas d'usage n'est connue que par le repository).
     */
    public function reload(string $id): ShoppingList
    {
        return $this->lists->findOneById($id);
    }
}
