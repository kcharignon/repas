<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeCommand;
use Repas\Repas\Application\Command\CopyRecipe\CopyRecipeHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Tool\StringTool;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMatchCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

/**
 * La copie d'une recette et le rapprochement de ses ingrédients (ADR 0028 décision 8, ING-004, simplifiée par l'amendement du 2026-10-20) :
 * tout ingrédient est à quelqu'un, il n'y a ni commun ni adoption à la volée. Un ingrédient du copieur n'est JAMAIS écrasé ; on se branche
 * sur ce qu'il a déjà quand c'est certain (l'alias de la provenance, puis le nom), sinon on lui crée une copie qui retient son origine.
 */
class CopyRecipeHandlerTest extends TestCase
{
    private const string AUTHOR = 'author-id';
    private const string COPIER = 'copier-id';
    /** Le propriétaire du catalogue : ses ingrédients sont ceux dont les comptes reçoivent une copie à leur activation. */
    private const string OWNER = 'owner-id';

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
        $this->candidates = new IngredientMatchCandidatesInMemoryRepository($this->ingredients);
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
            new IngredientSlugResolver($this->aliases),
            $this->candidates,
            new IngredientNameMatcher(),
            new IngredientSlugPolicy(),
            new ConversionService($this->conversions, $units),
            new UuidGenerator(),
            $this->events,
        );
    }

    // --- La recette secrète (ADR 0033) ----------------------------------------------------------------------------------------------

    public function testTheSecretRecipeOfAnotherIsNotFoundAndNothingIsWritten(): void
    {
        // Given : la recette secrète de l'auteur, avec un ingrédient que le copieur n'a pas (il en recevrait une copie)
        $private = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $recipe = new RecipeBuilder()->withId('recipe-id')->withAuthorId(self::AUTHOR)->asSecret()
            ->addRow(new RecipeRowBuilder()->withIngredient($private)->withUnit($this->unit()->isBox())->withQuantity(4))->build();
        $this->assemble([$private], recipes: [$recipe]);

        // When
        try {
            ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));
            $this->fail('La recette secrète d\'un autre ne doit pas se copier.');
        } catch (RecipeException $exception) {
            // Then : la même exception qu'une recette inconnue (404), rien n'est copié, ni recette, ni ingrédient, ni événement
            $this->assertEquals(RecipeException::notFound('recipe-id'), $exception);
        }
        $this->assertCount(1, $this->recipes->findByAuthor(self::AUTHOR));
        $this->assertCount(0, $this->recipes->findByAuthor(self::COPIER));
        $this->assertFalse($this->ingredients->exists('ingredient-secret' . self::COPIER));
        $this->assertSame([], $this->events->eventDispatched);
    }

    public function testTheAuthorCopiesHisOwnSecretRecipeAndTheCopyIsAnOrdinaryRecipe(): void
    {
        // Given
        $private = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $recipe = new RecipeBuilder()->withId('recipe-id')->withAuthorId(self::AUTHOR)->asSecret()
            ->addRow(new RecipeRowBuilder()->withIngredient($private)->withUnit($this->unit()->isBox())->withQuantity(4))->build();
        $this->assemble([$private], recipes: [$recipe]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::AUTHOR, 'copy-id'));

        // Then : copiée, et la copie n'hérite pas du choix de l'original (le drapeau est celui de l'auteur de chaque recette)
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertSame(['recipe-id', self::AUTHOR, false], [$copy->getOriginalId(), $copy->getAuthorId(), $copy->isSecret()]);
        $this->assertTrue($this->recipes->findOneById('recipe-id')->isSecret());
    }

    public function testTheCopyOfAnOrdinaryRecipeIsOrdinary(): void
    {
        // Given
        $private = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $this->assemble([$private], recipes: [$this->recipe([$private], unit: $this->unit()->isBox())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertFalse($this->recipes->findOneById('copy-id')->isSecret());
    }

    // --- Ce que le copieur n'a pas : une copie qui retient son origine ------------------------------------------------------------

    public function testAnIngredientTheCopierDoesNotHaveIsCopiedWithItsOwnConversionsAndRetainsItsOrigin(): void
    {
        // Given
        $secret = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $conversion = new ConversionBuilder()->withIngredient($secret)->withStartUnit(new UnitBuilder()->isBox())->withEndUnit(new UnitBuilder()->isUnite())->withCoefficient(10)->build();
        $this->assemble([$secret], conversions: [$conversion], recipes: [$this->recipe([$secret], unit: $this->unit()->isBox())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : la recette copiée référence la copie, au nom du copieur, qui retient le slug de l'ingrédient copié (D7)
        $copy = $this->ingredients->findOneBySlug('ingredient-secret' . self::COPIER);
        RepasAssert::assertIngredient(
            new IngredientBuilder()->withName('Ingredient secret')->withDepartment($this->cheese())->withDefaultCookingUnit($this->unit()->isBox())->withDefaultPurchaseUnit($this->unit()->isBox())
                ->withImage($secret->getImage())->withCreator(new UserBuilder()->withId(self::COPIER)->build(), true)->withAdoptedFrom($secret->getSlug())->build(),
            $copy,
        );
        $this->assertSame([$copy->getSlug()], $this->slugsOfCopy());
        // ses conversions propres sont copiées (nouveaux identifiants), celles de l'auteur restent intactes
        $copiedConversions = $this->conversions->findByIngredient($copy);
        $this->assertCount(1, $copiedConversions);
        $this->assertSame(10.0, $copiedConversions->reset()->getCoefficient());
        $this->assertNotSame($conversion->getId(), $copiedConversions->reset()->getId());
        $this->assertCount(1, $this->conversions->findByIngredient($secret));
        // une copie n'est jamais une création : rien n'est annoncé aux statistiques
        $this->assertSame([], $this->createdSlugs());
        // et aucun alias n'est écrit
        $this->assertSame([], $this->aliases->all());
    }

    // --- La première lettre des noms copiés est en majuscule (ADR 0030) --------------------------------------------------------------

    public function testTheNamesOfTheCopiedRecipeAndOfTheCopiedIngredientsStartWithACapitalAndKeepTheRest(): void
    {
        // Given : une recette et un ingrédient enregistrés avant la règle, en minuscules ; un autre ingrédient déjà bien écrit
        $lowercase = $this->personal('lait de COCO', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $capitalized = $this->personal('Œufs fermiers', $this->cheese(), $this->unit()->isBox(), self::AUTHOR);
        $original = new RecipeBuilder()->withId('recipe-id')->withName('éclair au café')->withAuthorId(self::AUTHOR)
            ->addRow(new RecipeRowBuilder()->withIngredient($lowercase)->withUnit($this->unit()->isBox())->withQuantity(2))
            ->addRow(new RecipeRowBuilder()->withIngredient($capitalized)->withUnit($this->unit()->isBox())->withQuantity(1))
            ->build();
        $this->assemble([$lowercase, $capitalized], recipes: [$original]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : le nom copié est corrigé en silence, la suite est conservée, un nom déjà bon est inchangé ; les slugs ne bougent pas
        $this->assertSame('Éclair au café', $this->recipes->findOneById('copy-id')->getName());
        $this->assertSame('éclair au café', $this->recipes->findOneById('recipe-id')->getName(), "l'original n'est pas touché");
        $this->assertSame('Lait de COCO', $this->ingredients->findOneBySlug(StringTool::slugify('lait de COCO' . self::COPIER))->getName());
        $this->assertSame('Œufs fermiers', $this->ingredients->findOneBySlug(StringTool::slugify('Œufs fermiers' . self::COPIER))->getName());
        $this->assertSame('lait de COCO', $this->ingredients->findOneBySlug($lowercase->getSlug())->getName(), "l'ingrédient de l'auteur n'est pas touché");
    }

    public function testACopyOfARecipeWhoseNameIsAlreadyCapitalizedKeepsItCharacterForCharacter(): void
    {
        // Given
        $original = new RecipeBuilder()->withId('recipe-id')->withName('Bœuf bourguignon')->withAuthorId(self::AUTHOR)->build();
        $this->assemble(recipes: [$original]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame('Bœuf bourguignon', $this->recipes->findOneById('copy-id')->getName());
    }

    public function testACopyOfACopyRetainsTheOriginOfTheOriginalNotTheSlugOfTheCopy(): void
    {
        // Given : l'ingrédient de l'auteur est lui-même la copie de « tomate » du propriétaire du catalogue ; le copieur n'a rien
        $catalog = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authors = Ingredient::copyFromOriginal($catalog, self::AUTHOR, 'tomate' . self::AUTHOR);
        $this->assemble([$catalog, $authors], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : la nouvelle copie remonte à l'origine commune, comme celles des autres comptes
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame('tomate', $this->ingredients->findOneBySlug('tomate' . self::COPIER)->getAdoptedFrom());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testNothingOfTheAuthorIsEverUsedByTheCopier(): void
    {
        // Given : le copieur n'a rien, l'auteur a un ingrédient
        $butter = $this->catalog('beurre', $this->cheese(), $this->unit()->isBox());
        $this->assemble([$butter], recipes: [$this->recipe([$butter], authorId: self::OWNER)]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : la ligne copiée ne référence pas l'ingrédient de l'auteur (un autre compte), mais une copie à lui
        $this->assertSame(['beurre' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame(self::COPIER, $this->ingredients->findOneBySlug('beurre' . self::COPIER)->getCreatorId());
        $this->assertSame(self::OWNER, $this->ingredients->findOneBySlug('beurre')->getCreatorId(), 'celui du propriétaire est intact');
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
        $this->assertSame([], $this->createdSlugs());
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
        $this->assertSame([], $this->createdSlugs());
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
        $this->assertSame([], $this->createdSlugs());
    }

    public function testTheIngredientOfTheCatalogOwnerWithTheSameNameIsNotACandidateEither(): void
    {
        // Given : le propriétaire du catalogue (qui a un « sucre ») n'est ni l'auteur ni le copieur ; le copieur n'a pas de « sucre »
        $catalog = $this->catalog('sucre', $this->bakery(), $this->unit()->isKilo());
        $authors = $this->personal('Sucre', $this->bakery(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$catalog, $authors], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : il n'y a plus d'ingrédient partagé, le copieur reçoit sa copie
        $this->assertSame(['sucre' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame([], $this->aliases->all());
    }

    // --- La provenance ------------------------------------------------------------------------------------------------------------

    public function testTheCopiersAliasOfTheOriginOfTheAuthorsIngredientIsUsedWithoutComparingNames(): void
    {
        // Given : le propriétaire du catalogue a « tomate » ; l'auteur et le copieur en ont reçu une copie (tomate<A>, tomate<C>)
        $tomato = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::copyFromOriginal($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $copiersCopy = Ingredient::copyFromOriginal($tomato, self::COPIER, 'tomate' . self::COPIER);
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

    public function testTheSlugOfAnIngredientWithoutOriginIsTheKeyOfTheProvenance(): void
    {
        // Given : la recette du propriétaire du catalogue est faite de SES ingrédients (sans origine) ; le copieur en a reçu les copies
        $tomato = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $copiersCopy = Ingredient::copyFromOriginal($tomato, self::COPIER, 'tomate' . self::COPIER);
        $this->assemble(
            [$tomato, $copiersCopy],
            [$this->alias('tomate', $copiersCopy->getSlug())],
            recipes: [$this->recipe([$tomato], authorId: self::OWNER, unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : la clé d'alias est le slug de l'ingrédient copié
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
        $this->assertSame(0, $this->candidates->reads);
    }

    public function testTheProvenanceWinsOverTheNameWhenTheCopiersIngredientWasRenamed(): void
    {
        // Given : le copieur a renommé sa copie de « tomate » en « Fruit rouge » ; l'auteur a gardé « tomate »
        $tomato = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::copyFromOriginal($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $copiersCopy = Ingredient::copyFromOriginal($tomato, self::COPIER, 'tomate' . self::COPIER);
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

    public function testAMergeAliasAlsoLeadsToTheCopiersIngredient(): void
    {
        // Given : le copieur a fusionné « tomates » dans « tomate » (alias de fusion) ; la recette de l'auteur utilise encore « tomates »
        $kept = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::COPIER);
        $authors = $this->personal('tomates', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $merge = IngredientAlias::create(self::COPIER, $authors->getSlug(), $kept->getSlug(), AliasKind::MERGE, 'merge', new DateTimeImmutable('2026-10-20 10:00:00'));
        $this->assemble([$kept, $authors], [$merge], recipes: [$this->recipe([$authors], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertSame([$kept->getSlug()], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testACopierWithoutAnAliasForTheOriginGetsACopyAndNothingIsAdoptedOnTheFly(): void
    {
        // Given : le copieur a reçu la farine (alias) mais pas « tomate » que l'auteur a reçue ; il n'a rien du même nom
        $flour = $this->catalog('farine', $this->bakery(), $this->unit()->isKilo());
        $flourCopy = Ingredient::copyFromOriginal($flour, self::COPIER, 'farine' . self::COPIER);
        $tomato = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::copyFromOriginal($tomato, self::AUTHOR, 'tomate' . self::AUTHOR);
        $this->assemble(
            [$flour, $flourCopy, $tomato, $authorsCopy],
            [$this->alias('farine', $flourCopy->getSlug())],
            recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : une copie qui retient l'origine, sans alias écrit ni création annoncée
        $this->assertSame(['tomate' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame('tomate', $this->ingredients->findOneBySlug('tomate' . self::COPIER)->getAdoptedFrom());
        $this->assertNull($this->aliases->find(self::COPIER, 'tomate'));
        $this->assertCount(1, $this->aliases->all());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testCopyingTheRecipeOfTheCatalogOwnerLinksTheCopiesAndCopiesTheRest(): void
    {
        // Given : le propriétaire du catalogue a une recette (tomate, farine) ; le copieur n'a reçu que la farine
        $tomato = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $flour = $this->catalog('farine', $this->bakery(), $this->unit()->isKilo());
        $flourCopy = Ingredient::copyFromOriginal($flour, self::COPIER, 'farine' . self::COPIER);
        $this->assemble(
            [$tomato, $flour, $flourCopy],
            [$this->alias('farine', $flourCopy->getSlug())],
            recipes: [$this->recipe([$tomato, $flour], authorId: self::OWNER, unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : l'alias existant pour la farine, une copie pour la tomate ; jamais un ingrédient du propriétaire
        $this->assertSame(['tomate' . self::COPIER, 'farine' . self::COPIER], $this->slugsOfCopy());
        $this->assertSame([], $this->createdSlugs());
    }

    public function testWhenNoAliasExistsForTheOriginTheNamesAreCompared(): void
    {
        // Given : l'ingrédient de l'auteur vient de « tomate » ; le copieur a sa propre « tomate », sans alias
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

    public function testWhenTheCopiersIngredientCannotConvertTheLineACopyKeepsTheOriginAndIsNotCounted(): void
    {
        // Given : le copieur a reçu « beurre » puis changé son unité d'achat en boîte ; la ligne de l'auteur est en kilos
        $butter = $this->catalog('beurre', $this->cheese(), $this->unit()->isKilo());
        $authorsCopy = Ingredient::copyFromOriginal($butter, self::AUTHOR, 'beurre' . self::AUTHOR);
        $copiersCopy = Ingredient::copyFromOriginal($butter, self::COPIER, 'beurre' . self::COPIER);
        $copiersCopy->update('beurre', '', $this->cheese()->build(), $this->unit()->isBox()->build(), $this->unit()->isBox()->build(), $copiersCopy->getCompatibleUnits());
        $this->assemble(
            [$butter, $authorsCopy, $copiersCopy],
            [$this->alias('beurre', $copiersCopy->getSlug())],
            recipes: [$this->recipe([$authorsCopy], unit: $this->unit()->isKilo())],
        );

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then : on ne branche pas sur ce qui ne peut pas servir la ligne ; la copie garde l'origine (pas une création)
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

    public function testCopyingYourOwnRecipeMadeOfReceivedIngredientsKeepsThem(): void
    {
        // Given
        $tomato = $this->catalog('tomate', $this->vegetable(), $this->unit()->isKilo());
        $mine = Ingredient::copyFromOriginal($tomato, self::COPIER, 'tomate' . self::COPIER);
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
        $this->assertSame([], $this->createdSlugs());
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

    public function testTheStatisticsEventIsDispatchedOnceWithTheRecipeAndNoIngredientBecauseACopyIsNeverACreation(): void
    {
        // Given : un ingrédient copié, un lié par le nom (D7 : une copie retient son origine, elle n'est plus comptée)
        $theirs = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::COPIER);
        $linked = $this->personal('Tomates', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $copied = $this->personal('ingredient secret', $this->cheese(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$theirs, $linked, $copied], recipes: [$this->recipe([$linked, $copied], unit: $this->unit()->isKilo())]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertCount(1, $this->events->eventDispatched);
        $event = $this->events->eventDispatched[0];
        $this->assertInstanceOf(RecipesOrIngredientsCreatedEvent::class, $event);
        $this->assertSame(self::COPIER, $event->userId);
        $this->assertSame([], $event->ingredientSlugs->toArray());
        $this->assertSame(['copy-id'], $event->recipeIds->toArray());
        $this->assertTrue($this->ingredients->exists('ingredient-secret' . self::COPIER), 'la copie existe bien');
    }

    public function testAnUnknownRecipeIsRefused(): void
    {
        // Then
        $this->expectExceptionObject(RecipeException::NotFound('not-found'));

        // When
        ($this->handler)(new CopyRecipeCommand('not-found', self::COPIER, 'copy-id'));
    }

    // --- Plafond du compte --------------------------------------------------------------------------------------------------------

    public function testACopyIsRefusedToAnAccountThatHasTheMaximumOfRecipesWithItsCopies(): void
    {
        // Given : le copieur a déjà 2 000 recettes, l'original est à un autre
        $tomato = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$tomato], recipes: [$this->recipe([$tomato], unit: $this->unit()->isKilo()), ...$this->recipesOf(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES)]);

        // When
        try {
            ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));
            $this->fail('La copie doit être refusée.');
        } catch (RecipeException $exception) {
            // Then : refus de domaine (422), rien n'est écrit ni annoncé
            $this->assertEquals(RecipeException::limitReached(), $exception);
        }
        $this->assertFalse($this->recipes->authorHasAtLeast(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES + 1));
        $this->assertSame([], $this->events->eventDispatched);
        $this->assertFalse($this->ingredients->exists('tomate' . self::COPIER));
    }

    public function testTheLastCopyBelowTheLimitOfTheAccountIsMadeEvenBeyondTheLimitOfWrittenRecipes(): void
    {
        // Given : 1 999 recettes au copieur, au-delà du plafond de l'écriture (1 000) : il lui reste une copie
        $tomato = $this->personal('tomate', $this->vegetable(), $this->unit()->isKilo(), self::AUTHOR);
        $this->assemble([$tomato], recipes: [$this->recipe([$tomato], unit: $this->unit()->isKilo()), ...$this->recipesOf(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES - 1)]);

        // When
        ($this->handler)(new CopyRecipeCommand('recipe-id', self::COPIER, 'copy-id'));

        // Then
        $this->assertTrue($this->recipes->authorHasAtLeast(self::COPIER, Recipe::MAX_PER_AUTHOR_WITH_COPIES));
        $this->assertSame('recipe-id', $this->recipes->findOneById('copy-id')->getOriginalId());
    }

    /**
     * @return list<Recipe>
     */
    private function recipesOf(string $authorId, int $count): array
    {
        $recipes = [];
        for ($i = 0; $i < $count; $i++) {
            $recipes[] = new RecipeBuilder()->withId("existing-$i")->withAuthorId($authorId)->build();
        }

        return $recipes;
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

    /**
     * Un ingrédient du propriétaire du catalogue : un ancien commun, au slug du nom seul (sans l'identifiant du compte), créateur le
     * propriétaire.
     */
    private function catalog(string $name, DepartmentBuilder $department, UnitBuilder $purchaseUnit): Ingredient
    {
        return $this->builder($name, $department, $purchaseUnit)->withSlug(StringTool::slugify($name))->withCreator(new UserBuilder()->withId(self::OWNER)->build())->build();
    }

    private function builder(string $name, DepartmentBuilder $department, UnitBuilder $purchaseUnit): IngredientBuilder
    {
        return new IngredientBuilder()->withName($name)->withDepartment($department)->withDefaultCookingUnit($purchaseUnit)->withDefaultPurchaseUnit($purchaseUnit);
    }

    private function alias(string $originSlug, string $copySlug): IngredientAlias
    {
        return IngredientAlias::create(self::COPIER, $originSlug, $copySlug, AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-10-20 10:00:00'));
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
