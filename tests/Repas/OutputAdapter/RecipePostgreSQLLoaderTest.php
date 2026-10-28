<?php

namespace Repas\Tests\Repas\OutputAdapter;


use PHPUnit\Framework\Attributes\DataProvider;
use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
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

    public function testListOfOtherPeopleKeepsOriginalsNotYetCopiedSortedByCategory(): void
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
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0);

        // Then : toutes les recettes tiennent dans la page, le total en est déduit sans seconde requête
        $this->assertEquals(
            new OtherPeopleRecipesDTO(
                [new OtherPeopleRecipeDTO($kept1->getId(), 'aaa carbonara', 'plat', 'plat'), new OtherPeopleRecipeDTO($kept2->getId(), 'zzz tarte', 'dessert', 'dessert')],
                2,
            ),
            $result,
        );
        $this->assertSame(1, $counter->count(), implode("\n", $counter->statements()));
    }

    public function testListOfOtherPeopleGivesAPageAndTheRealTotal(): void
    {
        // Given : cinq recettes d'un autre
        $me = $this->savedUser();
        $other = $this->savedUser();
        $recipes = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            $recipes[$letter] = $this->savedRecipe($other, $letter . ' recette', new RecipeTypeBuilder()->isMeal());
        }

        // When
        $first = $this->recipeLoader->listOfOtherPeople($me->getId(), 2, 0);
        $second = $this->recipeLoader->listOfOtherPeople($me->getId(), 2, 2);
        $last = $this->recipeLoader->listOfOtherPeople($me->getId(), 2, 4);
        $beyond = $this->recipeLoader->listOfOtherPeople($me->getId(), 2, 10);

        // Then : le total est toujours le vrai, la page est triée par catégorie puis par nom
        $this->assertSame([[$recipes['a']->getId(), $recipes['b']->getId()], 5], [array_column($first->member, 'id'), $first->totalItems]);
        $this->assertSame([[$recipes['c']->getId(), $recipes['d']->getId()], 5], [array_column($second->member, 'id'), $second->totalItems]);
        $this->assertSame([[$recipes['e']->getId()], 5], [array_column($last->member, 'id'), $last->totalItems]);
        $this->assertSame([[], 5], [$beyond->member, $beyond->totalItems]);
    }

    public function testListOfOtherPeopleKeepsTheTotalWhenThePageIsFull(): void
    {
        // Given : exactement autant de recettes que la page en peut donner
        $me = $this->savedUser();
        $other = $this->savedUser();
        $this->savedRecipe($other, 'a recette', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($other, 'b recette', new RecipeTypeBuilder()->isMeal());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 2, 0);

        // Then
        $this->assertSame([2, 2], [count($result->member), $result->totalItems]);
    }

    public function testListOfOtherPeopleOrdersTwoRecipesOfTheSameNameTheSameWayOnEveryCall(): void
    {
        // Given : deux recettes de même nom (donc de même slug)
        $me = $this->savedUser();
        $this->savedRecipe($this->savedUser(), 'tarte', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($this->savedUser(), 'tarte', new RecipeTypeBuilder()->isMeal());

        // When
        $full = array_column($this->recipeLoader->listOfOtherPeople($me->getId(), 2, 0)->member, 'id');
        $pages = [
            ...array_column($this->recipeLoader->listOfOtherPeople($me->getId(), 1, 0)->member, 'id'),
            ...array_column($this->recipeLoader->listOfOtherPeople($me->getId(), 1, 1)->member, 'id'),
        ];

        // Then : lues page par page, elles sont dans le même ordre que lues d'un coup
        $this->assertSame($full, $pages);
    }

    public function testListOfOtherPeopleWithoutSearchIsSortedByCategoryThenNameThenId(): void
    {
        // Given : l'ordre des catégories est celui de leur colonne `sequence` (entrée, plat, dessert), pas l'ordre alphabétique ni celui de la saisie
        $me = $this->savedUser();
        $other = $this->savedUser();
        $dessertB = $this->savedRecipe($other, 'b gâteau', new RecipeTypeBuilder()->isDessert());
        $mealB = $this->savedRecipe($other, 'b pâtes', new RecipeTypeBuilder()->isMeal());
        $starter = $this->savedRecipe($other, 'z soupe', new RecipeTypeBuilder()->isStarter());
        $dessertA = $this->savedRecipe($other, 'a tarte', new RecipeTypeBuilder()->isDessert());
        $mealA = $this->savedRecipe($other, 'a riz', new RecipeTypeBuilder()->isMeal());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0);

        // Then
        $this->assertSame(
            [$starter->getId(), $mealA->getId(), $mealB->getId(), $dessertA->getId(), $dessertB->getId()],
            array_column($result->member, 'id'),
        );
        $this->assertSame(['entree', 'plat', 'plat', 'dessert', 'dessert'], array_column($result->member, 'typeName'));
        $this->assertSame(['entrée', 'plat', 'plat', 'dessert', 'dessert'], array_column($result->member, 'typeSlug'));
    }

    public function testSearchRanksByRelevanceAcrossAllCategoriesAndShowsTheCategory(): void
    {
        // Given : le nom exact d'abord, puis les noms qui commencent par le texte (le plus court avant), toutes catégories confondues
        $me = $this->savedUser();
        $other = $this->savedUser();
        $long = $this->savedRecipe($other, 'tarte tatin caramélisée', new RecipeTypeBuilder()->isDessert());
        $exact = $this->savedRecipe($other, 'tarte', new RecipeTypeBuilder()->isStarter());
        $short = $this->savedRecipe($other, 'tartelette', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($other, 'omelette', new RecipeTypeBuilder()->isMeal());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0, 'tarte');

        // Then : l'ordre n'est pas celui des catégories (entrée, plat, dessert) et chaque recette porte la sienne
        $this->assertEquals(
            [
                new OtherPeopleRecipeDTO($exact->getId(), 'tarte', 'entree', 'entrée'),
                new OtherPeopleRecipeDTO($short->getId(), 'tartelette', 'plat', 'plat'),
                new OtherPeopleRecipeDTO($long->getId(), 'tarte tatin caramélisée', 'dessert', 'dessert'),
            ],
            $result->member,
        );
        $this->assertSame(3, $result->totalItems);
    }

    public function testSearchPutsAShorterNameBeforeALongerOneStartingWithTheText(): void
    {
        // Given
        $me = $this->savedUser();
        $other = $this->savedUser();
        $long = $this->savedRecipe($other, 'pâtes carbonara végétariennes', new RecipeTypeBuilder()->isMeal());
        $short = $this->savedRecipe($other, 'pâtes carbonara', new RecipeTypeBuilder()->isMeal());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0, 'pâtes carbo');

        // Then
        $this->assertSame([$short->getId(), $long->getId()], array_column($result->member, 'id'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function carbonaraSearches(): iterable
    {
        yield 'début de mot' => ['carbo'];
        yield 'mot sans accent ni pluriel complet' => ['pate'];
        yield 'faute de frappe' => ['carbonnara'];
        yield 'majuscules et accents' => ['  PÂTES   Carbonara '];
        yield 'nom entier' => ['pâtes carbonara'];
    }

    #[DataProvider('carbonaraSearches')]
    public function testSearchFindsAMultiWordNameByWordPrefixTypoAccentsAndCase(string $search): void
    {
        // Given
        $me = $this->savedUser();
        $other = $this->savedUser();
        $carbonara = $this->savedRecipe($other, 'pâtes carbonara', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($other, 'salade de fruits', new RecipeTypeBuilder()->isDessert());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0, $search);

        // Then
        $this->assertSame([$carbonara->getId()], array_column($result->member, 'id'));
        $this->assertSame(1, $result->totalItems);
    }

    public function testSearchNeverReturnsMyOwnRecipesNorCopiesNorAlreadyCopiedOnes(): void
    {
        // Given
        $me = $this->savedUser();
        $other = $this->savedUser();
        $visible = $this->savedRecipe($other, 'tarte citron', new RecipeTypeBuilder()->isDessert());
        $this->savedRecipe($me, 'tarte citron maison', new RecipeTypeBuilder()->isDessert());
        $alreadyCopied = $this->savedRecipe($other, 'tarte pommes', new RecipeTypeBuilder()->isDessert());
        $this->savedRecipe($me, 'tarte pommes', new RecipeTypeBuilder()->isDessert(), originalId: $alreadyCopied->getId());
        $this->savedRecipe($other, 'tarte copie d\'un tiers', new RecipeTypeBuilder()->isDessert(), originalId: $visible->getId());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0, 'tarte');

        // Then
        $this->assertSame([$visible->getId()], array_column($result->member, 'id'));
        $this->assertSame(1, $result->totalItems);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function searchesWithoutAnyLetter(): iterable
    {
        yield 'ponctuation' => ['!!!'];
        yield 'tirets et points' => [' - . '];
        yield 'espaces' => ['    '];
    }

    #[DataProvider('searchesWithoutAnyLetter')]
    public function testASearchWithoutLetterOrDigitFindsNothing(string $search): void
    {
        // Given
        $me = $this->savedUser();
        $this->savedRecipe($this->savedUser(), 'tarte', new RecipeTypeBuilder()->isDessert());

        // When
        $result = $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0, $search);

        // Then
        $this->assertSame([[], 0], [$result->member, $result->totalItems]);
    }

    public function testSearchPaginatesTheRankedResultAndKeepsTheRealTotal(): void
    {
        // Given : quatre recettes dont le nom commence par le texte, une de plus qui ne correspond pas
        $me = $this->savedUser();
        $other = $this->savedUser();
        $ids = [];
        foreach (['tarte a', 'tarte b', 'tarte c', 'tarte d'] as $name) {
            $ids[] = $this->savedRecipe($other, $name, new RecipeTypeBuilder()->isDessert())->getId();
        }
        $this->savedRecipe($other, 'omelette', new RecipeTypeBuilder()->isMeal());

        // When
        $first = $this->recipeLoader->listOfOtherPeople($me->getId(), 3, 0, 'tarte');
        $second = $this->recipeLoader->listOfOtherPeople($me->getId(), 3, 3, 'tarte');
        $beyond = $this->recipeLoader->listOfOtherPeople($me->getId(), 3, 30, 'tarte');

        // Then : à pertinence égale, l'ordre du nom
        $this->assertSame([array_slice($ids, 0, 3), 4], [array_column($first->member, 'id'), $first->totalItems]);
        $this->assertSame([array_slice($ids, 3), 4], [array_column($second->member, 'id'), $second->totalItems]);
        $this->assertSame([[], 4], [$beyond->member, $beyond->totalItems]);
    }

    public function testSearchOrdersTwoRecipesOfTheSameNameTheSameWayOnEveryCall(): void
    {
        // Given
        $me = $this->savedUser();
        $this->savedRecipe($this->savedUser(), 'tarte', new RecipeTypeBuilder()->isMeal());
        $this->savedRecipe($this->savedUser(), 'tarte', new RecipeTypeBuilder()->isDessert());

        // When
        $full = array_column($this->recipeLoader->listOfOtherPeople($me->getId(), 2, 0, 'tarte')->member, 'id');
        $pages = [
            ...array_column($this->recipeLoader->listOfOtherPeople($me->getId(), 1, 0, 'tarte')->member, 'id'),
            ...array_column($this->recipeLoader->listOfOtherPeople($me->getId(), 1, 1, 'tarte')->member, 'id'),
        ];
        sort($full);

        // Then : par identifiant à pertinence et nom égaux, d'un appel à l'autre et d'une page à l'autre
        $this->assertSame($full, $pages);
        $this->assertSame(1, $this->searchQueryCount($me));
    }

    private function searchQueryCount(User $me): int
    {
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();
        $this->recipeLoader->listOfOtherPeople($me->getId(), 200, 0, 'tarte');

        return $counter->count();
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
