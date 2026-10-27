<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

class RecipePostgreSQLLoaderTest extends DatabaseTestCase
{
    private RecipeLoader $recipeLoader;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipeLoader = static::getContainer()->get(RecipeLoader::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testGetReturnsTheRecipeWithItsRows(): void
    {
        // Given
        $author = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($author)->withName('zzz gâteau')->withServing(6)->withRecipeType(new RecipeTypeBuilder()->isDessert())
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->withName('zzz sucre')->withImage('images/sucre.png'))->withUnit(new UnitBuilder()->isUnite())->withQuantity(2))
            ->addRow(new RecipeRowBuilder()->withIngredient(new IngredientBuilder()->withName('aaa farine')->withImage(''))->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->build();
        $this->persister->saveRecipe($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then
        $this->assertSame([$recipe->getId(), 'zzz gâteau', 6, 'dessert', 'dessert'], [$result->id, $result->name, $result->serving, $result->typeSlug, $result->typeName]);
        // L'ordre est celui de la saisie (colonne `position`), pas l'ordre alphabétique
        $this->assertEquals(
            [
                new RecipeRowDTO('zzz-sucre', 'zzz sucre', 'images/sucre.png', 2.0, 'unite', '', 'unité', 'unite', 'unité', true),
                // Le gramme ne se convertit pas en l'unité d'achat (l'unité) de cet ingrédient : aucune conversion ne les relie
                new RecipeRowDTO('aaa-farine', 'aaa farine', '', 250.0, 'gramme', 'g', 'gramme', 'unite', 'unité', false),
            ],
            $result->rows,
        );
    }

    public function testGetSaysWhichRowsConvertToThePurchaseUnitOfTheirIngredient(): void
    {
        // Given : la farine (recettes en gramme, achat au kilo), le beurre (recettes en gramme, achat à la plaquette) et un ingrédient sans conversion
        $author = $this->savedUser();
        $flour = new IngredientBuilder()->isFlour()->withCreator($author, true)->build();
        $butter = new IngredientBuilder()->isButter()->withCreator($author, true)->build();
        $salt = new IngredientBuilder()->withName('sel')->withCreator($author, true)->withDefaultCookingUnit(new UnitBuilder()->isGramme())->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->build();
        // Une conversion UNIVERSELLE (kilo > gramme) et une PROPRE au beurre (plaquette > gramme), la cuillère du sel n'est reliée à rien
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveConversion(new ConversionBuilder()->isBlockToGrammeForButter()->withIngredient($butter)->build());
        // La cuillère à soupe de LA FARINE seule : elle ne sert ni au sel ni au beurre
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build());
        $recipe = new RecipeBuilder()->withAuthor($author)
            ->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))
            ->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isKilo())->withQuantity(1))
            ->addRow(new RecipeRowBuilder()->withIngredient($flour)->withUnit(new UnitBuilder()->isSoupSpoon())->withQuantity(3))
            ->addRow(new RecipeRowBuilder()->withIngredient($butter)->withUnit(new UnitBuilder()->isKilo())->withQuantity(1))
            ->addRow(new RecipeRowBuilder()->withIngredient($salt)->withUnit(new UnitBuilder()->isSoupSpoon())->withQuantity(1))
            ->build();
        $this->persister->saveRecipe($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then : à l'unité d'achat elle-même ; directement par l'universelle (kilo > gramme) ; par la conversion propre de la farine (cuillère > gramme)
        // puis l'universelle ; par DEUX étapes pour le beurre (kilo > gramme universelle puis gramme > plaquette propre) ; jamais par la conversion de la
        // farine pour un autre ingrédient (le sel)
        $this->assertSame(
            [
                [$flour->getSlug(), 'gramme', 'gramme', 'kilo', true],
                [$flour->getSlug(), 'kilo', 'kilo', 'kilo', true],
                [$flour->getSlug(), 'cuillere-a-soupe', 'cuillère à soupe', 'kilo', true],
                [$butter->getSlug(), 'kilo', 'kilo', 'plaquette', true],
                [$salt->getSlug(), 'cuillere-a-soupe', 'cuillère à soupe', 'kilo', false],
            ],
            array_map(static fn(RecipeRowDTO $row): array => [$row->ingredientSlug, $row->unitSlug, $row->unitName, $row->purchaseUnitName, $row->convertible], $result->rows),
        );
    }

    public function testGetJudgesEachRecipeOnTheConversionsOfItsOwnAuthorsIngredients(): void
    {
        // Given : deux auteurs ont chacun leur farine ; seule celle du premier sait convertir la cuillère à soupe
        $first = $this->savedUser();
        $second = $this->savedUser();
        $firstFlour = new IngredientBuilder()->isFlour()->withCreator($first, true)->build();
        $secondFlour = new IngredientBuilder()->isFlour()->withCreator($second, true)->build();
        $this->persister->saveConversion(new ConversionBuilder()->isKiloToGramme()->build());
        $this->persister->saveConversion(new ConversionBuilder()->withIngredient($firstFlour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build());
        $firstRecipe = new RecipeBuilder()->withAuthor($first)->addRow(new RecipeRowBuilder()->withIngredient($firstFlour)->withUnit(new UnitBuilder()->isSoupSpoon())->withQuantity(3))->build();
        $secondRecipe = new RecipeBuilder()->withAuthor($second)->addRow(new RecipeRowBuilder()->withIngredient($secondFlour)->withUnit(new UnitBuilder()->isSoupSpoon())->withQuantity(3))->build();
        $this->persister->saveRecipe($firstRecipe);
        $this->persister->saveRecipe($secondRecipe);

        // When
        $firstResult = $this->recipeLoader->get($firstRecipe->getId());
        $secondResult = $this->recipeLoader->get($secondRecipe->getId());

        // Then
        $this->assertSame([true, false], [$firstResult->rows[0]->convertible, $secondResult->rows[0]->convertible]);
    }

    public function testGetReadsTheConversionsOfAllTheIngredientsInOneQuery(): void
    {
        // Given : une recette de plusieurs ingrédients, chacun avec sa conversion propre
        $author = $this->savedUser();
        $recipe = new RecipeBuilder()->withAuthor($author);
        foreach (range(1, 5) as $index) {
            $ingredient = new IngredientBuilder()->isFlour()->withName("farine $index")->withCreator($author, true)->build();
            $this->persister->saveConversion(new ConversionBuilder()->withIngredient($ingredient)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(10)->build());
            $recipe->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit(new UnitBuilder()->isSoupSpoon())->withQuantity($index));
        }
        $recipe = $recipe->build();
        $this->persister->saveRecipe($recipe);
        static::getContainer()->get('doctrine')->getManager()->clear();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then : la recette, ses lignes, les conversions : trois lectures, quel que soit le nombre de lignes
        $this->assertCount(5, $result->rows);
        $this->assertSame(['SELECT recipe' => 1, 'SELECT recipe_row' => 1, 'SELECT conversion' => 1], $counter->byTable());
    }

    public function testGetReturnsTheVersionOfTheRecipe(): void
    {
        // Given : une recette neuve (version 1) puis enregistrée une seconde fois (version 2)
        $recipe = new RecipeBuilder()->withAuthor($this->savedUser())->build();
        $this->persister->saveRecipe($recipe);
        $this->assertSame(1, $this->recipeLoader->get($recipe->getId())->version);
        $recipe->setName('zzz autre nom');
        static::getContainer()->get(RecipeRepository::class)->save($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then
        $this->assertSame(2, $result->version);
    }

    public function testGetReturnsARecipeWithoutRow(): void
    {
        // Given
        $recipe = new RecipeBuilder()->withAuthor($this->savedUser())->build();
        $this->persister->saveRecipe($recipe);

        // When
        $result = $this->recipeLoader->get($recipe->getId());

        // Then
        $this->assertSame([], $result->rows);
    }

    public function testGetFailsWhenTheRecipeDoesNotExist(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::notFound('unknown-recipe'));

        // When
        $this->recipeLoader->get('unknown-recipe');
    }

    public function testListByAuthorAndTypeReturnsTheTypeAndTheRecipesOfTheAuthorSortedBySlug(): void
    {
        // Given
        $author = $this->savedUser();
        $second = $this->savedRecipe($author, 'zzz tarte', new RecipeTypeBuilder()->isDessert());
        $first = $this->savedRecipe($author, 'aaa gâteau', new RecipeTypeBuilder()->isDessert());
        $this->savedRecipe($author, 'mmm plat', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($this->savedUser(), 'bbb dessert d\'un autre', new RecipeTypeBuilder()->isDessert());

        // When
        $result = $this->recipeLoader->listByAuthorAndType($author->getId(), 'dessert');

        // Then
        $this->assertEquals(new RecipeTypeDTO('dessert', 'dessert', new RecipeTypeBuilder()->isDessert()->build()->getImage(), 3), $result->recipeType);
        $this->assertEquals(
            [new RecipeSummaryDTO($first->getId(), 'aaa gâteau'), new RecipeSummaryDTO($second->getId(), 'zzz tarte')],
            $result->recipes,
        );
    }

    public function testListByAuthorAndTypeReturnsAnEmptyListForAKnownTypeWithoutRecipe(): void
    {
        // Given
        $author = $this->savedUser();
        $this->savedRecipe($author, 'mmm plat', new RecipeTypeBuilder()->isMeal());
        $this->persister->saveRecipeType(new RecipeTypeBuilder()->isDessert()->build());

        // When
        $result = $this->recipeLoader->listByAuthorAndType($author->getId(), 'dessert');

        // Then
        $this->assertSame('dessert', $result->recipeType->slug);
        $this->assertSame([], $result->recipes);
    }

    public function testListByAuthorAndTypeFailsForAnUnknownType(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::typeNotFound('unknown-type'));

        // When
        $this->recipeLoader->listByAuthorAndType($this->savedUser()->getId(), 'unknown-type');
    }

    public function testListOfOtherPeopleKeepsOriginalsNotYetCopiedSortedBySlug(): void
    {
        // Given
        $me = $this->savedUser();
        $other = $this->savedUser();
        $kept2 = $this->savedRecipe($other, 'zzz tarte', new RecipeTypeBuilder()->isDessert());
        $kept1 = $this->savedRecipe($other, 'aaa carbonara', new RecipeTypeBuilder()->isMeal());
        $alreadyCopied = $this->savedRecipe($other, 'bbb déjà copiée', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($me, 'bbb déjà copiée', new RecipeTypeBuilder()->isMeal(), originalId: $alreadyCopied->getId());
        $this->savedRecipe($me, 'ccc ma recette', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($other, 'ddd copie d\'un tiers', new RecipeTypeBuilder()->isMeal(), originalId: $kept1->getId());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId());

        // Then
        $this->assertEquals(
            [new OtherPeopleRecipeDTO($kept1->getId(), 'aaa carbonara', 'plat'), new OtherPeopleRecipeDTO($kept2->getId(), 'zzz tarte', 'dessert')],
            $result,
        );
    }

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedRecipe(User $author, string $name, RecipeTypeBuilder $type, ?string $originalId = null): Recipe
    {
        $recipe = new RecipeBuilder()->withAuthor($author)->withName($name)->withRecipeType($type)->withOriginalId($originalId)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }
}
