<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Application\Command\PlannedMeal\PlannedMealCommand;
use Repas\Repas\Application\Command\PlannedMeal\PlannedMealHandler;
use Repas\Repas\Application\Command\RemoveMealFromPlan\removeMealFromPlanHandler;
use Repas\Repas\Application\Command\UpdateServingMeal\UpdateServingMealHandler;
use Repas\Repas\Application\Service\PartAwareLines;
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
use Repas\Tests\Helper\Builder\DepartmentBuilder;
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
 * Une recette qui en utilise une autre (ADR 0040, COMP-004), en mémoire : de quoi planifier, changer les personnes et retirer une tarte dont la pâte
 * est une recette à part. Un propriétaire pour quatre personnes et :
 *
 *   - « Pâte sablée » pour 6 personnes : Farine 250 g (rayon boulangerie, achetée au kilo), Beurre 125 g (rayon fromage, acheté en plaquette de 250 g) ;
 *   - « Tarte au citron » pour 4 personnes : Sucre 100 g (rayon boulangerie, acheté au kilo), Œuf 2 (rayon divers) ; elle utilise la pâte `$multiplier` fois.
 *
 * Les quantités de la liste sont celles des deux recettes dans l'unité d'achat : pour un repas de 4 personnes et une pâte « 1 fois », 0,1 kg de sucre,
 * 2 œufs, 0,25 kg de farine, 0,5 plaquette de beurre. Les options ajoutent un « Jaune d'œuf » (une partie de l'œuf, ADR 0038) à la pâte, le type de
 * liste « Boulangerie » (le rayon de la farine et du sucre), ou retirent la conversion de la plaquette de beurre.
 */
final class ComposedScenario
{
    public const int SERVING = 4;
    public const int DOUGH_SERVING = 6;

    public User $owner;
    public Ingredient $flour;
    public Ingredient $sugar;
    public Ingredient $butter;
    public Ingredient $egg;
    public Ingredient $yolk;
    public Recipe $dough;
    public Recipe $pie;
    public ShoppingListType $bakery;
    public ShoppingListInMemoryRepository $lists;
    public IngredientInMemoryRepository $ingredients;
    public RecipeInMemoryRepository $recipes;
    public ConversionInMemoryRepository $conversionRepository;
    public ConversionService $conversions;
    public TypedLists $typed;

    /**
     * @param float $multiplier       combien de fois la pâte « telle qu'elle est écrite » la tarte l'utilise
     * @param bool  $butterConversion faux : la plaquette de beurre n'a aucune conversion vers le gramme (la pâte ne se planifie pas)
     * @param bool  $yolkInDough      la pâte porte aussi 3 « Jaune d'œuf », une partie de l'œuf (ratio 1)
     * @param bool  $withBakery       le propriétaire a le type « Boulangerie » (rayon de la farine et du sucre)
     */
    public function __construct(float $multiplier = 1.0, bool $butterConversion = true, bool $yolkInDough = false, bool $withBakery = false)
    {
        $this->owner = new UserBuilder()->withId('owner-id')->withServing(self::SERVING)->build();
        $this->flour = new IngredientBuilder()->isFlour()->withCreator($this->owner)->build();
        $this->sugar = new IngredientBuilder()->isSugar()->withCreator($this->owner)->build();
        $this->butter = new IngredientBuilder()->isButter()->withCreator($this->owner)->build();
        $this->egg = new IngredientBuilder()->isEgg()->withCreator($this->owner)->build();
        $this->yolk = new IngredientBuilder()->withName("Jaune d'œuf")->withSlug('jaune')->withDepartment(new DepartmentBuilder()->isMiscellaneous())
            ->withDefaultCookingUnit(new UnitBuilder()->isUnite())->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())
            ->withCreator($this->owner)->withParent($this->egg->getSlug(), 1.0)->build();

        $doughBuilder = new RecipeBuilder()->withId('dough-id')->withName('Pâte sablée')->withServing(self::DOUGH_SERVING)->withAuthor($this->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($this->flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->butter)->withUnit(new UnitBuilder()->isGramme())->withQuantity(125));
        if ($yolkInDough) {
            $doughBuilder->addRow(new RecipeRowBuilder()->withIngredient($this->yolk)->withUnit(new UnitBuilder()->isUnite())->withQuantity(3));
        }
        $this->dough = $doughBuilder->build();
        $this->pie = new RecipeBuilder()->withId('pie-id')->withName('Tarte au citron')->withServing(self::SERVING)->withAuthor($this->owner)
            ->addRow(new RecipeRowBuilder()->withIngredient($this->sugar)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100))
            ->addRow(new RecipeRowBuilder()->withIngredient($this->egg)->withUnit(new UnitBuilder()->isUnite())->withQuantity(2))
            ->withComponent($this->dough, $multiplier)
            ->build();

        $this->lists = new ShoppingListInMemoryRepository();
        $this->ingredients = new IngredientInMemoryRepository([$this->flour, $this->sugar, $this->butter, $this->egg, $this->yolk]);
        $this->recipes = new RecipeInMemoryRepository([$this->dough, $this->pie]);
        $this->conversionRepository = new ConversionInMemoryRepository(array_filter([
            new ConversionBuilder()->isKiloToGramme()->build(),
            $butterConversion ? new ConversionBuilder()->isBlockToGrammeForButter()->build() : null,
        ]));
        $this->conversions = new ConversionService($this->conversionRepository, new UnitInMemoryRepository());
        $this->typed = new TypedLists($this->lists, $this->ingredients);
        $this->bakery = ShoppingListType::create('bakery-type', $this->owner->getId(), 'Boulangerie', new \DateTimeImmutable(ShoppingListBuilder::DEFAULT_DATE));
        if ($withBakery) {
            $this->bakery = $this->typed->type('bakery-type', $this->owner->getId(), 'Boulangerie', ['boulangerie']);
        }
    }

    public function partLines(): PartAwareLines
    {
        return PartLines::for($this->ingredients, $this->conversions);
    }

    public function planned(): PlannedMealHandler
    {
        return new PlannedMealHandler($this->lists, $this->recipes, $this->conversions, new UuidGenerator(), $this->typed->routing, $this->typed->activeListForType, $this->partLines(), FlatRows::for($this->recipes));
    }

    public function serving(): UpdateServingMealHandler
    {
        return new UpdateServingMealHandler($this->lists, $this->recipes, $this->conversions, $this->typed->group, $this->partLines(), FlatRows::for($this->recipes));
    }

    public function removal(): removeMealFromPlanHandler
    {
        return new removeMealFromPlanHandler($this->lists, $this->recipes, $this->conversions, $this->typed->group, FlatRows::for($this->recipes));
    }

    public function plan(Recipe $recipe, int $serving = self::SERVING, ?int $expectedVersion = null): PlannedMealCommand
    {
        return new PlannedMealCommand($this->owner->getId(), $recipe->getId(), $serving, $expectedVersion);
    }

    /**
     * Une liste (ou du statut demandé) du propriétaire, vide, enregistrée. `$typeId` : un type, ou `null` pour la liste principale.
     */
    public function list(string $id = 'main', ?string $typeId = null, ShoppingListStatus $status = ShoppingListStatus::ACTIVE): ShoppingList
    {
        $builder = new ShoppingListBuilder()->withId($id)->withOwner($this->owner)->withListTypeId($typeId);
        if ($status === ShoppingListStatus::SENT) {
            $builder->addIngredient(new IngredientBuilder()->isEgg());
        }
        if ($typeId !== null) {
            $builder->withName('Boulangerie');
        }
        $list = $builder->withStatus($status)->build();
        $this->lists->save($list);

        return $list;
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
     * La liste enregistrée, relue (une liste ouverte par le cas d'usage n'est connue que par le repository).
     */
    public function reload(string $id = 'main'): ShoppingList
    {
        return $this->lists->findOneById($id);
    }

    /**
     * Le repas de la recette dans la liste, `null` sans repas.
     */
    public function meal(ShoppingList $list, Recipe $recipe): ?Meal
    {
        return $list->mealOf($recipe);
    }
}
