<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeHandler;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\IngredientSlugResolverFactory;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMatchCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

/**
 * La copie d'une recette et le rapprochement de ses ingrédients (ADR 0028 décision 8, ING-004) : un ingrédient du copieur n'est JAMAIS
 * écrasé ; on se branche sur ce qu'il a déjà quand c'est certain, sinon on lui crée une copie.
 */
class CopyRecipeHandlerTest extends TestCase
{
    private const string AUTHOR = 'author-id';
    private const string COPIER = 'copier-id';

    private CopyRecipeHandler $handler;
    private RecipeInMemoryRepository $recipes;
    private IngredientInMemoryRepository $ingredients;
    private ConversionInMemoryRepository $conversions;
    private IngredientAliasInMemoryRepository $aliases;
    private IngredientMatchCandidatesInMemoryRepository $candidates;
    private SpyEventDispatcher $events;

    protected function setUp(): void
    {
        $this->events = new SpyEventDispatcher();
        $this->assemble();
    }

    /**
     * @param list<Ingredient> $ingredients
     */
    private function assemble(array $ingredients = [], array $aliases = [], array $conversions = [], array $recipes = []): void
    {
        $this->ingredients = new IngredientInMemoryRepository($ingredients);
        $this->aliases = new IngredientAliasInMemoryRepository($aliases);
        $this->conversions = new ConversionInMemoryRepository($conversions);
        $this->recipes = new RecipeInMemoryRepository($recipes);
        $this->candidates = new IngredientMatchCandidatesInMemoryRepository($this->ingredients, $this->aliases);
        $this->events = new SpyEventDispatcher();
        $units = new UnitInMemoryRepository([
            new UnitBuilder()->isUnite()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isKilo()->build(),
            new UnitBuilder()->isBox()->build(),
        ]);
        $this->handler = new CopyRecipeHandler(
            $this->recipes,
            $this->ingredients,
            $this->conversions,
            IngredientSlugResolverFactory::create($this->ingredients, $this->aliases, $this->conversions),
            $this->candidates,
            new IngredientNameMatcher(),
            new IngredientSlugPolicy(),
            new ConversionService($this->conversions, $units),
            new UuidGenerator(),
            $this->events,
        );
    }

    // --- Ce que le copieur n'a pas : une copie, comme avant -----------------------------------------------------------------------

    public function testAPersonalIngredientTheCopierDoesNotHaveIsCopiedWithItsOwnConversions(): void
    {
        // Given
        $secret = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $conversion = new ConversionBuilder()->withIngredient($secret)->withStartUnit(new UnitBuilder()->isBox())->withEndUnit(new UnitBuilder()->isUnite())->withCoefficient(10)->build();
        $this->assemble([$secret], conversions: [$conversion], recipes: [$this->recipe([$secret], unit: $this->unit()->isBox())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : la recette copiée référence la copie, au nom du copieur
        $copy = $this->ingredients->findOneBySlug('ingredient-secret' . self::COPIER);
        RepasAssert::assertIngredient(
            new IngredientBuilder()->withName('ingredient secret')->withDepartment($this->cheese())->withDefaultCookingUnit($this->unit()->isBox())->withDefaultPurchaseUnit($this->unit()->isBox())
                ->withImage($secret->getImage())->withCreator(new UserBuilder()->withId(self::COPIER)->build(), true)->build(),
            $copy,
        );
        $this->assertSame([$copy->getSlug()], $this->slugsOfCopy());
        // ses conversions propres sont copiées (nouveaux identifiants), celles de l'auteur restent intactes
        $copiedConversions = $this->conversions->findByIngredient($copy);
        $this->assertCount(1, $copiedConversions);
        $this->assertSame(10.0, $copiedConversions->reset()->getCoefficient());
        $this->assertNotSame($conversion->getId(), $copiedConversions->reset()->getId());
        $this->assertCount(1, $this->conversions->findByIngredient($secret));
        // la création est annoncée aux statistiques
        $this->assertSame([$copy->getSlug()], $this->createdSlugs());
    }

    public function testACommonIngredientStaysTheCommonOneForSomeoneWhoAdoptedNothing(): void
    {
        // Given
        $butter = $this->common('beurre', $this->cheese(), $this->unit()->isBox());
        $this->assemble([$butter], recipes: [$this->recipe([$butter])]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : rien n'est créé, la ligne référence le commun, comme avant
        $this->assertSame(['beurre'], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame([], $this->aliases->all());
        $this->assertCount(1, $this->ingredients->findAll());
        $this->assertSame(0, $this->candidates->reads, 'Un commun se retient par sa provenance : aucune comparaison de noms.');
    }

    // --- Jamais d'écrasement -------------------------------------------------------------------------------------------------------

    public function testAnExistingIngredientOfTheCopierIsNeverOverwrittenEvenWithTheSameSlug(): void
    {
        // Given : le copieur a « crème » au rayon fromage ; l'auteur a « crème » au rayon boulangerie (deux produits différents)
        $theirs = $this->personal('crème', $this->cheese(), $this->unit()->isBox(), self::COPIER);
        $ownConversion = new ConversionBuilder()->withIngredient($theirs)->withStartUnit(new UnitBuilder()->isBox())->withEndUnit(new UnitBuilder()->isUnite())->withCoefficient(4)->build();
        $authors = $this->personal('crème', $this->bakery(), $this->unit()->isGramme(), self::AUTHOR);
        $this->assemble([$theirs, $authors], conversions: [$ownConversion], recipes: [$this->recipe([$authors], unit: $this->unit()->isGramme())]);
        $before = [$theirs->getSlug(), $theirs->getDepartment()->getSlug(), $theirs->getDefaultPurchaseUnit()->getSlug(), $theirs->getImage()];

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : une copie au slug suivant, l'ingrédient du copieur est intact (rayon, unités, image, conversions)
        $this->assertSame(['creme-2' . self::COPIER], $this->slugsOfCopy());
        $intact = $this->ingredients->findOneBySlug($theirs->getSlug());
        $this->assertSame($before, [$intact->getSlug(), $intact->getDepartment()->getSlug(), $intact->getDefaultPurchaseUnit()->getSlug(), $intact->getImage()]);
        $this->assertCount(1, $this->conversions->findByIngredient($intact));
        $this->assertSame(4.0, $this->conversions->findByIngredient($intact)->reset()->getCoefficient());
        $copy = $this->ingredients->findOneBySlug('creme-2' . self::COPIER);
        $this->assertSame($this->bakery()->build()->getSlug(), $copy->getDepartment()->getSlug());
        $this->assertSame(['creme-2' . self::COPIER], $this->createdSlugs());
    }

    public function testASecondCopyOfTheSameRecipeLinksToTheFirstCopyAndCreatesNothing(): void
    {
        // Given
        $secret = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $this->assemble([$secret], recipes: [$this->recipe([$secret], unit: $this->unit()->isBox())]);
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-1'));
        $this->events->eventDispatched = [];

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-2'));

        // Then : le nom exact du même rayon se retrouve, il n'y a pas de doublon
        $this->assertCount(2, $this->ingredients->findAll());
        $this->assertSame(['ingredient-secret' . self::COPIER], $this->slugsOfCopy('copy-2'));
        $this->assertSame([], $this->createdSlugs());
    }

    // --- Le nom, dans le même rayon -----------------------------------------------------------------------------------------------

    public function testAnIngredientOfTheCopierWithTheSameNameAndDepartmentAndAUsableUnitIsLinked(): void
    {
        // Given : « Tomates » chez l'auteur, « tomate » chez le copieur, même rayon, même unité
        $theirs = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::COPIER);
        $authors = $this->personal('Tomates', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$theirs, $authors], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame([$theirs->getSlug()], $this->slugsOfCopy());
        $this->assertCount(2, $this->ingredients->findAll(), 'Rien n\'est créé.');
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame('tomate', $this->ingredients->findOneBySlug($theirs->getSlug())->getName(), 'Le nom du copieur n\'est pas réécrit.');
    }

    public function testTheSameNameInAnotherDepartmentIsAnotherIngredient(): void
    {
        // Given : « tomate » surgelée chez le copieur, « tomate » fruit et légume chez l'auteur
        $theirs = $this->personal('tomate', $this->frozen(), $this->unit()->isKilo(), self::COPIER);
        $authors = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$theirs, $authors], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : une copie, sans toucher à la surgelée (même slug de base : le suivant est choisi)
        $this->assertSame(['tomate-2' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame($this->frozen()->build()->getSlug(), $this->ingredients->findOneBySlug($theirs->getSlug())->getDepartment()->getSlug());
        $this->assertSame($this->vegetable()->build()->getSlug(), $this->ingredients->findOneBySlug('tomate-2' . self::COPIER)->getDepartment()->getSlug());
    }

    public function testAnIngredientWhoseUnitCannotServeTheLineIsNotLinked(): void
    {
        // Given : le copieur compte sa « farine » en kilos, sans aucune conversion vers les unités ; la ligne de l'auteur est en boîtes
        $theirs = $this->personal('farine', $this->bakery(), $this->unit()->isKilo(), self::COPIER);
        $authors = $this->personal('farine', $this->bakery(), $this->unit()->isBox(), self::AUTHOR);
        $this->assemble([$theirs, $authors], recipes: [$this->recipe([$authors], unit: $this->unit()->isBox())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : sinon la recette copiée ne se planifierait pas
        $this->assertSame(['farine-2' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame($theirs->getDefaultPurchaseUnit()->getSlug(), $this->ingredients->findOneBySlug($theirs->getSlug())->getDefaultPurchaseUnit()->getSlug());
    }

    public function testAnIngredientWhoseUnitServesTheLineByACommonConversionIsLinked(): void
    {
        // Given : la ligne est en grammes, l'ingrédient du copieur s'achète au kilo ; une conversion commune relie les deux
        $theirs = $this->personal('farine', $this->bakery(), $this->unit()->isKilo(), self::COPIER);
        $authors = $this->personal('farine', $this->bakery(), $this->unit()->isGramme(), self::AUTHOR);
        $common = new ConversionBuilder()->withoutIngredient()->withStartUnit(new UnitBuilder()->isKilo())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(1000)->build();
        $this->assemble([$theirs, $authors], conversions: [$common], recipes: [$this->recipe([$authors], unit: $this->unit()->isGramme())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame([$theirs->getSlug()], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testTwoCandidatesAsGoodAsEachOtherAreAmbiguousAndGiveACopy(): void
    {
        // Given : deux ingrédients du copieur à une faute du nom reçu, à moins de 0,02 l'un de l'autre
        $first = $this->personal('concombrre', $this->vegetable(), $this->unit()->isUnite(), self::COPIER);
        $second = $this->personal('concomber', $this->vegetable(), $this->unit()->isUnite(), self::COPIER);
        $authors = $this->personal('concombre', $this->vegetable(), $this->unit()->isUnite(), self::AUTHOR);
        $this->assemble([$first, $second, $authors], recipes: [$this->recipe([$authors])]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : on ne choisit pas à la place du copieur
        $this->assertSame(['concombre' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame(['concombre' . self::COPIER], $this->createdSlugs());
    }

    public function testTwoExactCandidatesGiveTheFirstBySlug(): void
    {
        // Given : le copieur a lui-même deux fois « tomate » dans le rayon
        $first = $this->personal('tomate', $this->vegetable(), $this->unit()->isUnite(), self::COPIER);
        $second = Ingredient::copyFromOriginal($first, self::COPIER, 'tomate-2' . self::COPIER);
        $authors = $this->personal('tomate', $this->vegetable(), $this->unit()->isUnite(), self::AUTHOR);
        $this->assemble([$second, $first, $authors], recipes: [$this->recipe([$authors])]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : déterministe, et les deux ingrédients du copieur ne sont pas fusionnés
        $this->assertSame(['tomate-2' . self::COPIER], $this->slugsOfCopy());
        $this->assertCount(3, $this->ingredients->findAll());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testNothingOfAnotherUserIsEverACandidate(): void
    {
        // Given : un troisième utilisateur a « tomate » au même rayon, ce n'est pas celui du copieur
        $someoneElses = $this->personal('tomate', $this->vegetable(), $this->unit()->isUnite(), 'someone-else');
        $authors = $this->personal('tomate', $this->vegetable(), $this->unit()->isUnite(), self::AUTHOR);
        $this->assemble([$someoneElses, $authors], recipes: [$this->recipe([$authors])]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame(['tomate' . self::COPIER], $this->createdSlugs());
    }

    public function testACommonIngredientWithTheSameNameIsLinkedForSomeoneWhoDidNotAdoptIt(): void
    {
        // Given : l'auteur a son « sucre » personnel, le copieur (sans alias) voit le commun « sucre »
        $common = $this->common('sucre', $this->bakery(), $this->unit()->isKilo());
        $authors = $this->personal('Sucre', $this->bakery(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$common, $authors], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : il garde les communs partagés, comme avant
        $this->assertSame(['sucre'], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame([], $this->aliases->all());
    }

    public function testAnAdopterLinkedByNameToACommonHeHasNotAdoptedYetAdoptsItFirst(): void
    {
        // Given : le copieur est adoptant (il a adopté la farine) ; le commun « sucre » lui est encore visible
        $flour = $this->common('farine', $this->bakery(), $this->unit()->isKilo());
        $flourCopy = Ingredient::adopt($flour, self::COPIER, 'farine' . self::COPIER);
        $sugar = $this->common('sucre', $this->bakery(), $this->unit()->isKilo());
        $authors = $this->personal('Sucre', $this->bakery(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$flour, $flourCopy, $sugar, $authors], [$this->alias('farine', $flourCopy->getSlug())], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : la ligne référence sa copie à lui, adoptée à la volée (le commun lui est désormais masqué), ce n'est pas une création
        $this->assertSame(['sucre' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame('sucre', $this->ingredients->findOneBySlug('sucre' . self::COPIER)->getAdoptedFrom());
        $this->assertSame('sucre' . self::COPIER, $this->aliases->find(self::COPIER, 'sucre')?->getIngredientSlug());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testAnAdopterLinksByNameToHisOwnCopyOfACommonWhichIsHiddenForHim(): void
    {
        // Given : le copieur a adopté « tomate » (le commun lui est masqué) ; l'auteur a son « Tomates » personnel
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $tomatoCopy = Ingredient::adopt($tomato, self::COPIER, 'tomate' . self::COPIER);
        $authors = $this->personal('Tomates', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$tomato, $tomatoCopy, $authors], [$this->alias('tomate', $tomatoCopy->getSlug())], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame([$tomatoCopy->getSlug()], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
        $this->assertCount(3, $this->ingredients->findAll());
    }

    // --- La provenance ------------------------------------------------------------------------------------------------------------

    public function testTheCopiersAliasOfTheCommonTheAuthorAdoptedIsUsedWithoutCreatingAnything(): void
    {
        // Given : l'auteur a adopté « tomate » (tomate<A>), le copieur aussi (tomate<C>)
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::adopt($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $copiersCopy = Ingredient::adopt($tomato, self::COPIER, 'tomate' . self::COPIER);
        $this->assemble(
            [$tomato, $authorsCopy, $copiersCopy],
            [$this->alias('tomate', $copiersCopy->getSlug())],
            recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertCount(3, $this->ingredients->findAll());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame(0, $this->candidates->reads, 'La provenance suffit : aucune comparaison de noms.');
    }

    public function testTheProvenanceWinsOverTheNameWhenTheCopiersIngredientWasRenamed(): void
    {
        // Given : le copieur a renommé sa copie de « tomate » en « Fruit rouge » ; l'auteur a gardé « tomate »
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::adopt($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $copiersCopy = Ingredient::adopt($tomato, self::COPIER, 'tomate' . self::COPIER);
        $copiersCopy->update('Fruit rouge', '', $this->vegetable()->build(), $this->unit()->isKilo()->build(), $this->unit()->isKilo()->build(), $copiersCopy->getCompatibleUnits());
        $this->assemble(
            [$tomato, $authorsCopy, $copiersCopy],
            [$this->alias('tomate', $copiersCopy->getSlug())],
            recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testAnAdopterWithoutAliasAdoptsTheCommonTheAuthorAdoptedOnTheFly(): void
    {
        // Given : le copieur est adoptant (farine), pas encore « tomate » que l'auteur a adoptée
        $flour = $this->common('farine', $this->bakery(), $this->unit()->isKilo());
        $flourCopy = Ingredient::adopt($flour, self::COPIER, 'farine' . self::COPIER);
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::adopt($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $this->assemble(
            [$flour, $flourCopy, $tomato, $authorsCopy],
            [$this->alias('farine', $flourCopy->getSlug())],
            recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : adoption à la volée, ni création ni statistique
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame('tomate', $this->ingredients->findOneBySlug('tomate' . self::COPIER)->getAdoptedFrom());
        $this->assertSame('tomate' . self::COPIER, $this->aliases->find(self::COPIER, 'tomate')?->getIngredientSlug());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testSomeoneWhoAdoptedNothingGetsTheCommonTheAuthorAdopted(): void
    {
        // Given : le copieur n'a aucun alias, il voit les communs
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::adopt($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $this->assemble([$tomato, $authorsCopy], recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : comme avant, la ligne référence le commun
        $this->assertSame(['tomate'], $this->slugsOfCopy());
        $this->assertCount(2, $this->ingredients->findAll());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame([], $this->aliases->all());
    }

    public function testAnAdopterCopiesARecipeMadeOfCommonsAndGetsHisOwnCopies(): void
    {
        // Given : l'auteur est un administrateur (ses ingrédients sont des communs), le copieur est adoptant
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $flour = $this->common('farine', $this->bakery(), $this->unit()->isKilo());
        $flourCopy = Ingredient::adopt($flour, self::COPIER, 'farine' . self::COPIER);
        $this->assemble(
            [$tomato, $flour, $flourCopy],
            [$this->alias('farine', $flourCopy->getSlug())],
            recipes: [$this->recipe([$tomato, $flour], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : l'alias existant, l'adoption à la volée pour la tomate ; jamais un commun masqué
        $this->assertSame(['tomate' . self::COPIER, 'farine' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testWhenTheCommonTheAuthorAdoptedIsGoneTheNamesAreCompared(): void
    {
        // Given : l'ingrédient de l'auteur vient d'un commun supprimé depuis ; le copieur a « tomate »
        $authorsCopy = new IngredientBuilder()->withName('tomate')->withDepartment($this->vegetable())->withDefaultCookingUnit($this->unit()->isKilo())
            ->withDefaultPurchaseUnit($this->unit()->isKilo())->withCreator(new UserBuilder()->withId(self::AUTHOR)->build(), true)->withAdoptedFrom('tomate')->build();
        $theirs = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::COPIER);
        $this->assemble([$authorsCopy, $theirs], recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame([$theirs->getSlug()], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testWhenTheCopiersCopyCannotConvertTheLineACopyKeepsTheOriginAndIsNotCounted(): void
    {
        // Given : le copieur a adopté « beurre » puis changé son unité d'achat en boîte ; la ligne de l'auteur est en kilos
        $butter = $this->common('beurre', $this->cheese(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::adopt($butter, self::AUTHOR, 'beurre' . self::AUTHOR);
        $copiersCopy = Ingredient::adopt($butter, self::COPIER, 'beurre' . self::COPIER);
        $copiersCopy->update('beurre', '', $this->cheese()->build(), $this->unit()->isBox()->build(), $this->unit()->isBox()->build(), $copiersCopy->getCompatibleUnits());
        $this->assemble(
            [$butter, $authorsCopy, $copiersCopy],
            [$this->alias('beurre', $copiersCopy->getSlug())],
            recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : on ne branche pas sur ce qui ne peut pas servir la ligne ; la copie garde le commun d'origine (pas une création)
        $this->assertSame(['beurre-2' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame('beurre', $this->ingredients->findOneBySlug('beurre-2' . self::COPIER)->getAdoptedFrom());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame('beurre' . self::COPIER, $this->aliases->find(self::COPIER, 'beurre')?->getIngredientSlug(), 'L\'alias existant n\'est pas modifié.');
        $this->assertSame($this->unit()->isBox()->build()->getSlug(), $this->ingredients->findOneBySlug($copiersCopy->getSlug())->getDefaultPurchaseUnit()->getSlug());
    }

    // --- Sa propre recette --------------------------------------------------------------------------------------------------------

    public function testCopyingYourOwnRecipeCreatesNothingAndDuplicatesNoConversion(): void
    {
        // Given
        $mine = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::COPIER);
        $conversion = new ConversionBuilder()->withIngredient($mine)->withStartUnit(new UnitBuilder()->isBox())->withEndUnit(new UnitBuilder()->isUnite())->withCoefficient(10)->build();
        $this->assemble([$mine], conversions: [$conversion], recipes: [$this->recipe([$mine], authorId: self::COPIER, unit: $this->unit()->isBox())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : mêmes ingrédients, une seule conversion
        $this->assertSame([$mine->getSlug()], $this->slugsOfCopy());
        $this->assertCount(1, $this->ingredients->findAll());
        $this->assertCount(1, $this->conversions->findAll());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame(0, $this->candidates->reads);
    }

    public function testAnAdopterCopyingHisOwnRecipeKeepsHisCopies(): void
    {
        // Given
        $tomato = $this->common('tomate', $this->vegetable(), $this->unit()->isKilo());
        $mine = Ingredient::adopt($tomato, self::COPIER, 'tomate' . self::COPIER);
        $this->assemble([$tomato, $mine], [$this->alias('tomate', $mine->getSlug())], recipes: [$this->recipe([$mine], authorId: self::COPIER, unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame([$mine->getSlug()], $this->slugsOfCopy());
        $this->assertCount(2, $this->ingredients->findAll());
        $this->assertSame([], $this->createdSlugs());
    }

    // --- Un seul traitement par ingrédient d'origine -------------------------------------------------------------------------------

    public function testTheSameIngredientOnSeveralRowsIsCopiedOnce(): void
    {
        // Given : l'oeuf apparaît sur deux lignes (jaune et blanc)
        $egg = $this->personal('oeuf', $this->cheese(), $this->unit()->isUnite(), self::AUTHOR);
        $conversion = new ConversionBuilder()->withIngredient($egg)->withStartUnit(new UnitBuilder()->isUnite())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(60)->build();
        $this->assemble([$egg], conversions: [$conversion], recipes: [$this->recipe([$egg, $egg])]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : une copie, une conversion copiée, deux lignes sur la même copie
        $this->assertSame(['oeuf' . self::COPIER, 'oeuf' . self::COPIER], $this->slugsOfCopy());
        $this->assertCount(2, $this->ingredients->findAll());
        $this->assertCount(2, $this->conversions->findAll());
        $this->assertSame(['oeuf' . self::COPIER], $this->createdSlugs());
    }

    public function testEveryRowKeepsItsQuantityAndUnitAndTheRecipeIsLinkedToItsOriginal(): void
    {
        // Given
        $secret = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $this->assemble([$secret], recipes: [$this->recipe([$secret], unit: $this->unit()->isBox(), quantity: 7)]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertSame(self::COPIER, $copy->getAuthorId());
        $this->assertSame('recipe-id', $copy->getOriginalId());
        $this->assertSame('Gloubiboulga', $copy->getName());
        $row = $copy->getRows()->reset();
        $this->assertSame(7.0, (float) $row->getQuantity());
        $this->assertSame('boite', $row->getUnit()->getSlug());
        $this->assertSame('copy-id', $row->getRecipeId());
        $this->assertNotSame($this->recipes->findOneById('recipe-id')->getRows()->reset()->getId(), $row->getId());
    }

    // --- Statistiques -------------------------------------------------------------------------------------------------------------

    public function testTheStatisticsEventIsDispatchedOnceWithOnlyTheCreations(): void
    {
        // Given : un ingrédient créé, un lié par le nom
        $theirs = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::COPIER);
        $linked = $this->personal('Tomates', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $created = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$theirs, $linked, $created], recipes: [$this->recipe([$linked, $created], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertCount(1, $this->events->eventDispatched);
        $event = $this->events->eventDispatched[0];
        $this->assertInstanceOf(RecipesOrIngredientsCreatedEvent::class, $event);
        $this->assertSame(self::COPIER, $event->userId);
        $this->assertSame(['ingredient-secret' . self::COPIER], $event->ingredientSlugs->toArray());
        $this->assertSame(['copy-id'], $event->recipeIds->toArray());
    }

    public function testAnUnknownRecipeIsRefused(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::NotFound('not-found'));

        // When
        ($this->handler)(new CopyRecipeCommand('not-found', self::COPIER, 'copy-id'));
    }

    // --- Aides --------------------------------------------------------------------------------------------------------------------

    private function unit(): UnitBuilder
    {
        return new UnitBuilder();
    }

    private function cheese(): DepartmentBuilder
    {
        return new DepartmentBuilder()->isCheese();
    }

    private function bakery(): DepartmentBuilder
    {
        return new DepartmentBuilder()->isBakery();
    }

    private function vegetable(): DepartmentBuilder
    {
        return new DepartmentBuilder()->withSlug('fruit-et-legume')->withName('Fruits et légumes');
    }

    private function frozen(): DepartmentBuilder
    {
        return new DepartmentBuilder()->withSlug('surgele')->withName('Surgelés');
    }

    private function personal(string $name, DepartmentBuilder $department, UnitBuilder $purchaseUnit, string $creatorId): Ingredient
    {
        return $this->builder($name, $department, $purchaseUnit)->withCreator(new UserBuilder()->withId($creatorId)->build(), true)->build();
    }

    private function common(string $name, DepartmentBuilder $department, UnitBuilder $purchaseUnit): Ingredient
    {
        return $this->builder($name, $department, $purchaseUnit)->build();
    }

    private function builder(string $name, DepartmentBuilder $department, UnitBuilder $purchaseUnit): IngredientBuilder
    {
        return new IngredientBuilder()->withName($name)->withDepartment($department)->withDefaultCookingUnit($purchaseUnit)->withDefaultPurchaseUnit($purchaseUnit);
    }

    private function alias(string $commonSlug, string $copySlug): IngredientAlias
    {
        return IngredientAlias::create(self::COPIER, $commonSlug, $copySlug, AliasKind::ADOPTION, 'first-use', new DateTimeImmutable('2026-10-20 10:00:00'));
    }

    /**
     * @param list<Ingredient> $ingredients une ligne par ingrédient
     */
    private function recipe(array $ingredients, string $authorId = self::AUTHOR, ?UnitBuilder $unit = null, int $quantity = 4): Recipe
    {
        $builder = new RecipeBuilder()->withId('recipe-id')->withAuthorId($authorId);
        foreach ($ingredients as $ingredient) {
            $builder->addRow(new RecipeRowBuilder()->withIngredient($ingredient)->withUnit($unit ?? $this->unit()->isUnite())->withQuantity($quantity));
        }

        return $builder->build();
    }

    /**
     * @return list<string> les slugs des ingrédients des lignes de la recette copiée
     */
    private function slugsOfCopy(string $copyId = 'copy-id'): array
    {
        return array_values($this->recipes->findOneById($copyId)->getRows()->map(static fn($row): string => $row->getIngredient()->getSlug())->toArray());
    }

    /**
     * @return list<string> les slugs annoncés aux statistiques
     */
    private function createdSlugs(): array
    {
        $created = [];
        foreach ($this->events->eventDispatched as $event) {
            if ($event instanceof RecipesOrIngredientsCreatedEvent) {
                array_push($created, ...$event->ingredientSlugs->toArray());
            }
        }

        return $created;
    }
}
