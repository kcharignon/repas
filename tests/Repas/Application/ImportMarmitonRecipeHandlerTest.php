<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportedMarmitonRecipe;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeCommand;
use Repas\Repas\Application\Command\ImportMarmitonRecipe\ImportMarmitonRecipeHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\MarmitonNeutralRecipeCreator;
use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\MarmitonRecipePage;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Domain\Service\Marmiton\MarmitonIngredientMatcher;
use Repas\Repas\Infrastructure\Acl\CatalogOwnerCommandAdapter;
use Repas\Shared\Domain\Event\RecipesOrIngredientsCreatedEvent;
use Repas\Shared\Domain\Exception\RateLimitedException;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPartLinkInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMatchCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UserInMemoryRepository;
use Repas\Tests\Helper\MarmitonImportLimiterInMemory;
use Repas\Tests\Helper\MarmitonImportLockSpy;
use Repas\Tests\Helper\MarmitonInMemoryPageReader;
use Repas\Tests\Helper\MarmitonPages;
use Repas\User\Application\OpenHostService\CatalogOwnerCommandOHS;

/**
 * L'import d'une recette Marmiton (ADR 0037, MAR-003) : la recette neutre créée une fois pour tous, la copie de la personne, et tout ce qui évite
 * un appel sortant (neutre connue, copie déjà faite, plafonds). Unitaire : le lecteur de page est le double de `MarmitonInMemoryPageReader`
 * (aucun test automatique n'appelle Marmiton), le catalogue vient du vrai Open Host Service de `User` branché sur des comptes en mémoire.
 * Les ingrédients sur la vraie base : `MarmitonIngredientResolutionTest`.
 */
class ImportMarmitonRecipeHandlerTest extends TestCase
{
    private const string URL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';
    private const string CANONICAL = 'https://www.marmiton.org/recettes/recette_tarte-au-citron-meringuee_22082.aspx';
    private const string OWNER = Recipe::MARMITON_OWNER_ID;
    private const string CATALOG = 'catalog-owner-id';
    private const string USER = 'user-id';

    private ImportMarmitonRecipeHandler $handler;
    private RecipeInMemoryRepository $recipes;
    private IngredientInMemoryRepository $ingredients;
    private ConversionInMemoryRepository $conversions;
    private IngredientAliasInMemoryRepository $aliases;
    private IngredientPartLinkInMemoryRepository $partLinks;
    private MarmitonInMemoryPageReader $reader;
    private MarmitonImportLimiterInMemory $limiter;
    private MarmitonImportLockSpy $lock;
    private SpyEventDispatcher $events;

    protected function setUp(): void
    {
        $this->assemble();
    }

    /**
     * @param list<Ingredient> $ingredients
     * @param list<Recipe> $recipes
     * @param list<IngredientAlias> $aliases
     * @param list<\Repas\Repas\Domain\Model\Conversion> $conversions
     */
    private function assemble(
        array $ingredients = [],
        array $recipes = [],
        array $aliases = [],
        array $conversions = [],
        ?MarmitonImportLimiterInMemory $limiter = null,
        ?MarmitonImportLockSpy $lock = null,
        bool $withCatalogOwner = true,
    ): void {
        $this->ingredients = new IngredientInMemoryRepository($ingredients);
        $this->recipes = new RecipeInMemoryRepository($recipes);
        $this->aliases = new IngredientAliasInMemoryRepository($aliases);
        $this->partLinks = new IngredientPartLinkInMemoryRepository();
        $this->conversions = new ConversionInMemoryRepository([new ConversionBuilder()->isKiloToGramme()->build(), ...$conversions]);
        $this->events = new SpyEventDispatcher();
        $this->reader = new MarmitonInMemoryPageReader()->willRead('22082', $this->page());
        $this->limiter = $limiter ?? new MarmitonImportLimiterInMemory();
        $this->lock = $lock ?? new MarmitonImportLockSpy();

        $candidates = new IngredientMatchCandidatesInMemoryRepository($this->ingredients);
        $units = new UnitInMemoryRepository(array_map(static fn(UnitBuilder $unit) => $unit->build(), [
            new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isCentiliter(),
            new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isCoffeeSpoon(), new UnitBuilder()->withSlug('pincee')->withName('pincée'),
        ]));
        $conversionService = new ConversionService($this->conversions, $units);
        $copier = new RecipeCopier(
            $this->recipes,
            $this->ingredients,
            $this->conversions,
            new IngredientSlugResolver($this->aliases),
            $candidates,
            new IngredientNameMatcher(),
            new IngredientSlugPolicy(),
            $conversionService,
            $this->partLinks,
            new UuidGenerator(),
            $this->events,
        );
        $accounts = new UserInMemoryRepository($withCatalogOwner ? [new UserBuilder()->withId(self::CATALOG)->isCatalogOwner()->build()] : []);
        $creator = new MarmitonNeutralRecipeCreator(
            $this->recipes,
            new RecipeTypeInMemoryRepository([new RecipeTypeBuilder()->isMeal()->build(), new RecipeTypeBuilder()->isDessert()->build()]),
            $this->ingredients,
            $candidates,
            new DepartmentInMemoryRepository([new DepartmentBuilder()->isMiscellaneous()->build(), new DepartmentBuilder()->isBakery()->build(), new DepartmentBuilder()->isCheese()->build()]),
            $units,
            new CatalogOwnerCommandAdapter(new CatalogOwnerCommandOHS($accounts, UserBuilder::CATALOG_OWNER_EMAIL)),
            new MarmitonIngredientMatcher(),
            $copier,
            new IngredientSlugPolicy(),
            $conversionService,
            new UuidGenerator(),
        );
        $this->handler = new ImportMarmitonRecipeHandler($this->recipes, $this->lock, $this->limiter, $this->reader, $creator, $copier);
    }

    /**
     * @param list<string> $lines
     */
    private function page(array $lines = MarmitonPages::EXAMPLE_LINES, string $title = MarmitonPages::EXAMPLE_TITLE, ?string $category = 'Dessert', ?string $yield = '6 personnes'): MarmitonRecipePage
    {
        return new MarmitonRecipePage(self::CANONICAL, $title, $yield, $category, $lines);
    }

    private function import(string $userId = self::USER, string $url = self::URL, string $copyId = 'copy-id'): ImportedMarmitonRecipe
    {
        return ($this->handler)(new ImportMarmitonRecipeCommand($url, $userId, $copyId));
    }

    private function neutral(): Recipe
    {
        $neutralId = $this->recipes->findMarmitonNeutralId('22082');
        $this->assertNotNull($neutralId, 'La neutre aurait dû être créée.');

        return $this->recipes->findOneById($neutralId);
    }

    // --- Une recette jamais importée : la neutre et la copie -----------------------------------------------------------------------------

    public function testAFirstImportReadsThePageOnceAndCreatesTheNeutralAndTheCopy(): void
    {
        // When
        $result = $this->import();

        // Then : la page est lue une fois, par l'adresse reconstruite
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame(self::URL, $this->reader->calls()[0]->fetchUrl());
        $this->assertSame([self::USER], $this->limiter->consumed);
        $this->assertSame(['22082'], $this->lock->acquired);
        $this->assertEquals(new ImportedMarmitonRecipe('copy-id', true), $result);

        // Then : la neutre n'appartient à personne, est ordinaire et originale, et garde le lien de la page
        $neutral = $this->neutral();
        $this->assertSame(self::OWNER, $neutral->getAuthorId());
        $this->assertTrue($neutral->isMarmitonNeutral());
        $this->assertFalse($neutral->isSecret());
        $this->assertTrue($neutral->isOriginal());
        $this->assertSame('22082', $neutral->getMarmitonId());
        $this->assertSame(self::CANONICAL, $neutral->getSourceUrl());
        $this->assertSame('Tarte au citron meringuée', $neutral->getName());
        $this->assertSame(6, $neutral->getServing());
        $this->assertSame('dessert', $neutral->getType()->getSlug());
        $this->assertCount(13, $neutral->getRows());

        // Then : la copie est à la personne, rattachée à la neutre, avec le lien d'origine, sans le tag
        $copy = $this->recipes->findOneById('copy-id');
        $this->assertSame(self::USER, $copy->getAuthorId());
        $this->assertSame($neutral->getId(), $copy->getOriginalId());
        $this->assertSame(self::CANONICAL, $copy->getSourceUrl());
        $this->assertNull($copy->getMarmitonId());
        $this->assertSame('Tarte au citron meringuée', $copy->getName());
        $this->assertCount(13, $copy->getRows());
    }

    public function testTheRowsAreTheLinesOfThePageWithTheirQuantityUnitAndName(): void
    {
        // When
        $this->import();

        // Then
        $rows = array_map(
            static fn(RecipeRow $row): string => sprintf('%s|%s|%s', rtrim(rtrim(number_format($row->getQuantity(), 2, '.', ''), '0'), '.'), $row->getUnit()->getSlug(), $row->getIngredient()->getName()),
            $this->neutral()->getRows()->toArray(),
        );
        $this->assertSame([
            '250|gramme|Farine',
            '125|gramme|Beurre doux',
            '70|gramme|Sucre semoule',
            "2|unite|Jaunes d'oeuf",
            '5|centilitre|Eau',
            '1|pincee|Sel',
            '4|unite|Citrons',
            '150|gramme|Sucre semoule',
            '3|unite|Oeufs',
            '1|cuillere-a-soupe|Maïzena',
            "2|unite|Blancs d'oeuf",
            '100|gramme|Sucre glace',
            '0.5|cuillere-a-cafe|Levure chimique',
        ], $rows);
    }

    public function testTwoLinesOfTheSameNameShareOneIngredientAndEveryIngredientBelongsToNobody(): void
    {
        // When
        $this->import();

        // Then : « sucre semoule » (deux lignes) est un seul ingrédient, et tous sont au propriétaire réservé (jamais à un compte)
        $neutral = $this->neutral();
        $sugars = array_values(array_filter($neutral->getRows()->toArray(), static fn(RecipeRow $row): bool => $row->getIngredient()->getName() === 'Sucre semoule'));
        $this->assertCount(2, $sugars);
        $this->assertSame($sugars[0]->getIngredient()->getSlug(), $sugars[1]->getIngredient()->getSlug());
        foreach ($neutral->getRows() as $row) {
            $this->assertSame(self::OWNER, $row->getIngredient()->getCreatorId());
        }
        $this->assertCount(12, array_filter($this->ingredients->findAll()->toArray(), static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::OWNER));
    }

    public function testTheNewIngredientsAreInTheMiscellaneousDepartmentBoughtAndCookedInTheUnitOfTheLine(): void
    {
        // When
        $this->import();

        // Then : sans catalogue, tout est créé en rayon « divers », l'unité d'achat est celle de la ligne
        foreach ($this->neutral()->getRows() as $row) {
            $ingredient = $row->getIngredient();
            $this->assertSame('divers', $ingredient->getDepartment()->getSlug(), $ingredient->getName());
            $this->assertSame($row->getUnit()->getSlug(), $ingredient->getDefaultCookingUnit()->getSlug(), $ingredient->getName());
            $this->assertSame($row->getUnit()->getSlug(), $ingredient->getDefaultPurchaseUnit()->getSlug(), $ingredient->getName());
            $this->assertNull($ingredient->getAdoptedFrom(), 'Un ingrédient créé n\'est la copie de rien.');
            $this->assertFalse($ingredient->isBase());
        }
    }

    public function testTheCopyEarnsOneStatisticsEventForTheUserAndTheNeutralNone(): void
    {
        // When
        $this->import();

        // Then : seule la copie est annoncée (au chemin normal d'une copie), jamais la neutre ni ses ingrédients
        $this->assertCount(1, $this->events->eventDispatched);
        $event = $this->events->eventDispatched[0];
        $this->assertInstanceOf(RecipesOrIngredientsCreatedEvent::class, $event);
        $this->assertSame(self::USER, $event->userId);
        $this->assertCount(0, $event->ingredientSlugs);
        $this->assertSame(['copy-id'], $event->recipeIds->toArray());
    }

    public function testEveryNameStartsWithACapitalLetter(): void
    {
        // Given : un titre et des lignes en minuscules
        $this->reader->willRead('22082', $this->page(['3 pommes de terre'], 'tarte aux pommes : recette facile'));

        // When
        $this->import();

        // Then : la neutre, son ingrédient et la copie (ADR 0030)
        $this->assertSame('Tarte aux pommes', $this->neutral()->getName());
        $this->assertSame('Pommes de terre', $this->neutral()->getRows()->reset()->getIngredient()->getName());
        $this->assertSame('Tarte aux pommes', $this->recipes->findOneById('copy-id')->getName());
    }

    public function testTheTypeServingsAndLinkComeFromThePageEvenAfterARedirection(): void
    {
        // Given : l'adresse collée a un faux nom ; la page rend l'adresse canonique lue après la redirection
        $this->reader->willRead('22082', $this->page(['1 oeuf'], 'Omelette', 'Plat principal', '4 à 6 personnes'));

        // When
        $this->import('user-id', 'http://marmiton.org/recettes/recette_x_22082.aspx?utm=1#a');

        // Then : l'identifiant suffit, le type vient de la catégorie, les personnes du premier nombre, le lien de la page lue
        $neutral = $this->neutral();
        $this->assertSame('plat', $neutral->getType()->getSlug());
        $this->assertSame(4, $neutral->getServing());
        $this->assertSame(self::CANONICAL, $neutral->getSourceUrl());
        $this->assertSame('https://www.marmiton.org/recettes/recette_x_22082.aspx', $this->reader->calls()[0]->fetchUrl());
    }

    public function testAnUnknownCategoryGivesTheMainCourseAndAMissingTypeTheFirstByOrder(): void
    {
        // Given : une catégorie que la table ne connaît pas
        $this->reader->willRead('22082', $this->page(['1 oeuf'], 'Omelette', 'Cuisine fusion', null));

        // When
        $this->import();

        // Then : « plat », 4 personnes à défaut
        $this->assertSame('plat', $this->neutral()->getType()->getSlug());
        $this->assertSame(4, $this->neutral()->getServing());
    }

    public function testALineThatCannotBeConvertedDoesNotPreventTheCopy(): void
    {
        // Given : une cuillère à soupe de maïzena, dont l'ingrédient du catalogue s'achète au kilo sans conversion
        $starch = new IngredientBuilder()->withName('Maïzena')->withDepartment(new DepartmentBuilder()->isBakery())->withDefaultCookingUnit(new UnitBuilder()->isGramme())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isKilo())->withCreatorId(self::CATALOG)->withBase()->build();
        $this->assemble([$starch]);
        $this->reader->willRead('22082', $this->page(['1 cuillères à soupe de maïzena']));

        // When
        $result = $this->import();

        // Then : la neutre et la copie sont créées (la fiche dira « à régler »)
        $this->assertTrue($result->created);
        $this->assertSame('cuillere-a-soupe', $this->neutral()->getRows()->reset()->getUnit()->getSlug());
        $this->assertCount(1, $this->recipes->findOneById('copy-id')->getRows());
    }

    // --- Une neutre connue : Marmiton n'est pas appelé -------------------------------------------------------------------------------------

    public function testAKnownNeutralIsCopiedWithoutAnyCallToMarmitonNorTheLimiter(): void
    {
        // Given : la neutre existe déjà (un autre compte l'a importée)
        $this->import('first-user', self::URL, 'first-copy');
        $this->assertSame(1, $this->reader->callCount());
        $this->events->eventDispatched = [];

        // When
        $result = $this->import('second-user', self::URL, 'second-copy');

        // Then : aucun appel sortant de plus, aucun jeton de plus, une copie de plus, la même neutre
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame(['first-user'], $this->limiter->consumed);
        $this->assertEquals(new ImportedMarmitonRecipe('second-copy', true), $result);
        $this->assertSame('second-user', $this->recipes->findOneById('second-copy')->getAuthorId());
        $this->assertSame($this->neutral()->getId(), $this->recipes->findOneById('second-copy')->getOriginalId());
        $this->assertCount(1, array_filter($this->recipes->findAll(), static fn(Recipe $recipe): bool => $recipe->isMarmitonNeutral()), 'Une seule neutre.');
        $this->assertCount(1, $this->events->eventDispatched);
    }

    public function testTheSecondCopierGetsItsOwnIngredientsAndNeverTheOnesOfTheNeutral(): void
    {
        // Given
        $this->import('first-user', self::URL, 'first-copy');

        // When
        $this->import('second-user', self::URL, 'second-copy');

        // Then : chaque copie utilise des ingrédients à son auteur (une copie de ceux de la neutre), pas ceux du propriétaire réservé
        foreach (['first-copy' => 'first-user', 'second-copy' => 'second-user'] as $copyId => $userId) {
            foreach ($this->recipes->findOneById($copyId)->getRows() as $row) {
                $this->assertSame($userId, $row->getIngredient()->getCreatorId(), $row->getIngredient()->getName());
            }
        }
    }

    public function testACopyAlreadyMadeIsReturnedAndNothingIsWrittenNorReadNorCounted(): void
    {
        // Given : la personne a déjà importé ce lien
        $this->import();
        $recipes = $this->recipes->findAll();
        $ingredients = $this->ingredients->findAll()->count();
        $this->events->eventDispatched = [];

        // When : elle recolle le même lien (avec un autre nom de recette, l'identifiant suffit)
        $result = $this->import(self::USER, 'https://marmiton.org/recettes/recette_autre-nom_22082.aspx', 'second-copy-id');

        // Then : la copie existante, pas de nouvelle recette ni d'ingrédient, pas d'appel, pas de jeton, pas d'événement
        $this->assertEquals(new ImportedMarmitonRecipe('copy-id', false), $result);
        $this->assertCount(count($recipes), $this->recipes->findAll());
        $this->assertSame($ingredients, $this->ingredients->findAll()->count());
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame([self::USER], $this->limiter->consumed);
        $this->assertSame([], $this->events->eventDispatched);
        $this->assertNull($this->tryFind('second-copy-id'));
    }

    public function testTwoImportsOfTheSameLinkOneAfterTheOtherGiveOneNeutralOneCopyAndOneCall(): void
    {
        // When
        $first = $this->import();
        $second = $this->import();

        // Then
        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->recipeId, $second->recipeId);
        $this->assertSame(1, $this->reader->callCount());
        $this->assertCount(2, $this->recipes->findAll(), 'La neutre et la copie, pas davantage.');
    }

    public function testACopyOfTheCopyKeepsTheLinkAndDoesNotCountAsTheCopyOfTheNeutral(): void
    {
        // Given : un autre compte copie la copie de la personne par la route ordinaire (même service)
        $this->import();

        // When : la personne qui a déjà une copie de la neutre n'en reçoit pas de deuxième, même si d'autres ont copié sa copie
        $copyOfCopy = $this->recipes->findOneById('copy-id');

        // Then
        $this->assertSame(self::CANONICAL, $copyOfCopy->getSourceUrl());
        $this->assertFalse($this->import()->created);
    }

    // --- Ce qui arrête l'import avant tout appel -------------------------------------------------------------------------------------------

    public function testAnAddressThatIsNotAMarmitonRecipeIsRefusedBeforeAnyLockLookupOrCall(): void
    {
        foreach (['', 'https://example.org/recettes/recette_a_1.aspx', 'https://www.marmiton.org/recettes/recherche.aspx', 'javascript:alert(1)'] as $url) {
            // When & Then
            try {
                $this->import(self::USER, $url);
                $this->fail("« $url » aurait dû être refusée.");
            } catch (MarmitonImportException $exception) {
                $this->assertSame(MarmitonImportException::URL_INVALID, $exception->getMessage());
                $this->assertSame(422, $exception->getCode());
            }
        }
        $this->assertSame([], $this->lock->acquired);
        $this->assertSame(0, $this->reader->callCount());
        $this->assertSame([], $this->limiter->consumed);
    }

    public function testAnAccountAtItsRecipeLimitCostsNothing(): void
    {
        // Given : un compte qui a déjà toutes ses recettes
        $full = [];
        for ($index = 0; $index < Recipe::MAX_PER_AUTHOR_WITH_COPIES; $index++) {
            $full[] = new RecipeBuilder()->withId("full-$index")->withAuthorId(self::USER)->build();
        }
        $this->assemble(recipes: $full);

        // When & Then : le plafond est jugé avant l'appel sortant et avant le jeton
        try {
            $this->import();
            $this->fail('Le plafond aurait dû refuser l\'import.');
        } catch (RecipeException $exception) {
            $this->assertSame(RecipeException::LIMIT_REACHED, $exception->getMessage());
        }
        $this->assertSame(0, $this->reader->callCount());
        $this->assertSame([], $this->limiter->consumed);
        $this->assertNull($this->recipes->findMarmitonNeutralId('22082'));
        $this->assertCount(Recipe::MAX_PER_AUTHOR_WITH_COPIES, $this->recipes->findAll());
    }

    public function testAnAccountAtItsRecipeLimitStillGetsTheCopyItAlreadyHas(): void
    {
        // Given : la personne a déjà sa copie, puis atteint le plafond
        $this->import();
        $full = $this->recipes->findAll();
        for ($index = 0; $index < Recipe::MAX_PER_AUTHOR_WITH_COPIES; $index++) {
            $full[] = new RecipeBuilder()->withId("full-$index")->withAuthorId(self::USER)->build();
        }
        $this->assemble(recipes: $full);

        // When & Then : rendre une copie existante n'écrit rien, le plafond n'a rien à refuser
        $this->assertSame(['copy-id'], [$this->import()->recipeId]);
    }

    public function testTheRateLimitStopsTheImportBeforeAnyCallAndWritesNothing(): void
    {
        // Given : l'utilisateur a épuisé ses lectures de page
        $this->assemble(limiter: new MarmitonImportLimiterInMemory(0, 1234));

        // When & Then
        try {
            $this->import();
            $this->fail('Le plafond d\'imports aurait dû refuser.');
        } catch (RateLimitedException $exception) {
            $this->assertSame(MarmitonImportException::RATE_LIMITED, $exception->getMessage());
            $this->assertSame(1234, $exception->retryAfter());
        }
        $this->assertSame(0, $this->reader->callCount());
        $this->assertSame([], $this->recipes->findAll());
        $this->assertSame([], $this->events->eventDispatched);
    }

    public function testTheRateLimitDoesNotStopACopyOfAKnownNeutral(): void
    {
        // Given : la neutre existe, l'utilisateur n'a plus de lecture de page
        $this->assemble(limiter: new MarmitonImportLimiterInMemory(1));
        $this->import('first-user', self::URL, 'first-copy');

        // When : le premier a consommé la seule lecture de SON plafond ; un autre n'en a pas besoin pour copier la neutre
        $result = $this->import('second-user', self::URL, 'second-copy');

        // Then
        $this->assertTrue($result->created);
        $this->assertSame(['first-user'], $this->limiter->consumed);
    }

    // --- Ce qui échoue : rien n'est écrit --------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{MarmitonImportException}>
     */
    public static function failures(): iterable
    {
        yield 'recette introuvable' => [MarmitonImportException::recipeNotFound()];
        yield 'page illisible' => [MarmitonImportException::recipeUnreadable()];
        yield 'Marmiton indisponible' => [MarmitonImportException::unavailable()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAFailedReadWritesNothingAndTheCallWasCounted(MarmitonImportException $failure): void
    {
        // Given
        $this->reader->willFail('22082', $failure);

        // When
        try {
            $this->import();
            $this->fail('L\'échec de la lecture aurait dû remonter.');
        } catch (MarmitonImportException $exception) {
            // Then : la même exception, ni neutre, ni copie, ni ingrédient, ni événement
            $this->assertSame($failure->getMessage(), $exception->getMessage());
            $this->assertSame($failure->getCode(), $exception->getCode());
        }
        $this->assertSame(1, $this->reader->callCount());
        $this->assertSame([], $this->recipes->findAll());
        $this->assertSame(0, $this->ingredients->findAll()->count());
        $this->assertSame([], $this->events->eventDispatched);
    }

    public function testAPageWithoutAnyReadableLineWritesNothing(): void
    {
        // Given : des lignes que l'analyse ne comprend pas
        $this->reader->willRead('22082', $this->page(['.', '—', '250 g']));

        // When & Then
        $this->expectExceptionObject(MarmitonImportException::recipeUnreadable());
        try {
            $this->import();
        } finally {
            $this->assertSame([], $this->recipes->findAll());
            $this->assertSame(0, $this->ingredients->findAll()->count());
            $this->assertSame([], $this->events->eventDispatched);
        }
    }

    public function testAnUnreadableLineIsIgnoredAndTheOthersAreKept(): void
    {
        // Given
        $this->reader->willRead('22082', $this->page(['250 g de farine', '.', '2 oeufs']));

        // When
        $this->import();

        // Then
        $this->assertCount(2, $this->neutral()->getRows());
    }

    public function testAPageWhoseAddressIsAnotherRecipeIsNeverStoredUnderThisTag(): void
    {
        // Given : un lecteur défaillant rend la page d'une autre recette
        $this->reader->willRead('22082', new MarmitonRecipePage('https://www.marmiton.org/recettes/recette_autre_999.aspx', 'Autre', null, null, ['1 oeuf']));

        // When & Then
        $this->expectExceptionObject(MarmitonImportException::unavailable());
        $this->import();
    }

    // --- Le verrou : deux imports du même lien neuf ----------------------------------------------------------------------------------------

    public function testTheLockIsTakenBeforeLookingForTheNeutralSoALateComerSeesWhatTheOtherCreated(): void
    {
        // Given : pendant que le verrou est attendu, l'autre transaction crée la neutre (et rend la main)
        $other = null;
        $this->assemble(lock: new MarmitonImportLockSpy(function () use (&$other): void {
            $other ??= true;
            $this->recipes->save(new RecipeBuilder()->withId('neutral-by-other')->asMarmitonNeutral('22082')->build());
        }));

        // When
        $result = $this->import();

        // Then : le retardataire n'a ni lu la page ni créé de deuxième neutre, ni consommé de jeton ; il a copié celle de l'autre
        $this->assertTrue($result->created);
        $this->assertSame(0, $this->reader->callCount());
        $this->assertSame([], $this->limiter->consumed);
        $this->assertSame('neutral-by-other', $this->recipes->findOneById('copy-id')->getOriginalId());
        $this->assertCount(1, array_filter($this->recipes->findAll(), static fn(Recipe $recipe): bool => $recipe->isMarmitonNeutral()));
        $this->assertSame(0, $this->ingredients->findAll()->filter(static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::OWNER)->count(), 'Aucun ingrédient orphelin.');
    }

    public function testAnImportRepeatedByTheSamePersonWhileTheOtherRunsReturnsTheCopyTheOtherMade(): void
    {
        // Given : pendant l'attente, l'autre requête DE LA MÊME PERSONNE a tout créé (double clic)
        $this->assemble(lock: new MarmitonImportLockSpy(function (): void {
            $this->recipes->save(new RecipeBuilder()->withId('neutral-by-other')->asMarmitonNeutral('22082')->build());
            $this->recipes->save(new RecipeBuilder()->withId('copy-by-other')->withAuthorId(self::USER)->withOriginalId('neutral-by-other')->build());
        }));

        // When
        $result = $this->import();

        // Then : sa copie, pas une deuxième
        $this->assertEquals(new ImportedMarmitonRecipe('copy-by-other', false), $result);
        $this->assertSame(0, $this->reader->callCount());
        $this->assertNull($this->tryFind('copy-id'));
    }

    public function testTheLockIsPerRecipeIdentifier(): void
    {
        // When
        $this->import(self::USER, self::URL, 'a');
        $this->reader->willRead('7', new MarmitonRecipePage('https://www.marmiton.org/recettes/recette_autre_7.aspx', 'Autre', null, null, ['1 oeuf']));
        $this->import(self::USER, 'https://www.marmiton.org/recettes/recette_autre_7.aspx', 'b');

        // Then
        $this->assertSame(['22082', '7'], $this->lock->acquired);
    }

    private function tryFind(string $id): ?Recipe
    {
        try {
            return $this->recipes->findOneById($id);
        } catch (RecipeException) {
            return null;
        }
    }

    // --- Les ingrédients : réutilisation, copie du catalogue, création ---------------------------------------------------------------------

    public function testAnIngredientAlreadyCreatedForMarmitonIsReusedByTheNextRecipe(): void
    {
        // Given : un premier import crée « Farine » ; un second lien parle de farine aussi
        $this->reader->willRead('22082', $this->page(['250 g de farine']));
        $this->import('first-user', self::URL, 'first-copy');
        $this->reader->willRead('7', new MarmitonRecipePage('https://www.marmiton.org/recettes/recette_autre_7.aspx', 'Autre', null, null, ['100 g de farines']));
        $created = $this->ingredients->findAll()->filter(static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::OWNER);

        // When
        $this->import('second-user', 'https://www.marmiton.org/recettes/recette_autre_7.aspx', 'second-copy');

        // Then : le pluriel se replie, c'est le même ingrédient du propriétaire réservé
        $owned = $this->ingredients->findAll()->filter(static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::OWNER);
        $this->assertCount(1, $created);
        $this->assertCount(1, $owned);
    }

    public function testACatalogIngredientIsCopiedForMarmitonWithItsOriginAndItsOwnConversions(): void
    {
        // Given : le catalogue a « Farine » (de base), en rayon boulangerie, acheté au kilo, avec une conversion propre
        $flour = new IngredientBuilder()->isFlour()->withCreatorId(self::CATALOG)->withBase()->build();
        $this->assemble([$flour], conversions: [new ConversionBuilder()->withIngredient($flour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(12)->build()]);
        $this->reader->willRead('22082', $this->page(['250 g de farine']));

        // When
        $this->import();

        // Then : une copie pour le propriétaire réservé, avec l'origine, le rayon, les unités et la conversion propre ; l'original n'est pas touché
        $copy = $this->neutral()->getRows()->reset()->getIngredient();
        $this->assertSame(self::OWNER, $copy->getCreatorId());
        $this->assertSame($flour->getSlug(), $copy->getAdoptedFrom());
        $this->assertSame('boulangerie', $copy->getDepartment()->getSlug());
        $this->assertSame('kilo', $copy->getDefaultPurchaseUnit()->getSlug());
        $this->assertFalse($copy->isBase());
        $this->assertNotSame($flour->getSlug(), $copy->getSlug());
        $this->assertCount(1, $this->conversions->findByIngredient($copy));
        $this->assertSame(self::CATALOG, $this->ingredients->findOneBySlug($flour->getSlug())->getCreatorId());
        $this->assertTrue($this->ingredients->findOneBySlug($flour->getSlug())->isBase());
    }

    public function testAnIngredientThatIsNotMarkedBaseIsNeverCopiedFromTheCatalog(): void
    {
        // Given : « Farine » est au propriétaire du catalogue mais n'est pas « de base »
        $flour = new IngredientBuilder()->isFlour()->withCreatorId(self::CATALOG)->build();
        $this->assemble([$flour]);
        $this->reader->willRead('22082', $this->page(['250 g de farine']));

        // When
        $this->import();

        // Then : un nouvel ingrédient en « divers »
        $ingredient = $this->neutral()->getRows()->reset()->getIngredient();
        $this->assertNull($ingredient->getAdoptedFrom());
        $this->assertSame('divers', $ingredient->getDepartment()->getSlug());
    }

    public function testACatalogIngredientIsCopiedOnlyOnceEvenWhenTwoLinesAreWrittenDifferently(): void
    {
        // Given
        $flour = new IngredientBuilder()->isFlour()->withCreatorId(self::CATALOG)->withBase()->build();
        $this->assemble([$flour]);
        $this->reader->willRead('22082', $this->page(['250 g de farine', '2 cuillères à soupe de Farines']));

        // When
        $this->import();

        // Then
        $slugs = array_unique(array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $this->neutral()->getRows()->toArray()));
        $this->assertCount(1, $slugs);
    }

    public function testWithoutACatalogOwnerEverythingIsCreatedInMiscellaneous(): void
    {
        // Given : le compte propriétaire du catalogue n'existe pas
        $this->assemble(withCatalogOwner: false);

        // When
        $this->import();

        // Then : l'import réussit, rien ne vient du catalogue
        $this->assertCount(13, $this->neutral()->getRows());
        foreach ($this->neutral()->getRows() as $row) {
            $this->assertNull($row->getIngredient()->getAdoptedFrom());
        }
    }

    public function testAUserWhoReceivedTheCatalogFindsHisOwnIngredientsByOriginInsteadOfNewOnes(): void
    {
        // Given : le catalogue a « Farine » et « Sucre semoule » de base ; l'utilisateur les a reçus à son activation (une copie et un alias chacun)
        $flour = new IngredientBuilder()->isFlour()->withCreatorId(self::CATALOG)->withBase()->build();
        $sugar = new IngredientBuilder()->isSugar()->withName('Sucre semoule')->withCreatorId(self::CATALOG)->withBase()->build();
        $usersFlour = Ingredient::copyFromOriginal($flour, self::USER, $flour->getSlug() . self::USER);
        $usersSugar = Ingredient::copyFromOriginal($sugar, self::USER, $sugar->getSlug() . self::USER);
        $now = new \DateTimeImmutable();
        $this->assemble(
            [$flour, $sugar, $usersFlour, $usersSugar],
            aliases: [
                IngredientAlias::create(self::USER, $flour->getSlug(), $usersFlour->getSlug(), AliasKind::ADOPTION, 'activation', $now),
                IngredientAlias::create(self::USER, $sugar->getSlug(), $usersSugar->getSlug(), AliasKind::ADOPTION, 'activation', $now),
            ],
        );
        $this->reader->willRead('22082', $this->page(['250 g de farine', '70 g de sucre semoule', '2 oeufs']));
        $before = $this->ingredients->findAll()->filter(static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::USER)->count();

        // When
        $this->import();

        // Then : « Farine » et « Sucre semoule » sont ceux de l'utilisateur ; seul « Oeufs » (inconnu du catalogue) lui est copié
        $copy = $this->recipes->findOneById('copy-id');
        $slugs = array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $copy->getRows()->toArray());
        $this->assertSame($usersFlour->getSlug(), $slugs[0]);
        $this->assertSame($usersSugar->getSlug(), $slugs[1]);
        $after = $this->ingredients->findAll()->filter(static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::USER)->count();
        $this->assertSame($before + 1, $after, 'Un seul ingrédient copié pour l\'utilisateur : « Oeufs ».');
    }

    public function testAnIngredientOfTheNeutralNeverHasAParentAndTheUsersCopyFindsHisOwnPartByOrigin(): void
    {
        // Given : le catalogue a « Œuf » et sa partie « Jaune d'œuf » (de base, ratio 1) ; l'utilisateur a reçu les deux à son activation, reliés
        $egg = new IngredientBuilder()->isEgg()->withCreatorId(self::CATALOG)->withBase()->build();
        $yolk = new IngredientBuilder()->isEgg()->withName("Jaune d'œuf")->withSlug('jaune-d-oeuf')->withCreatorId(self::CATALOG)->withBase()->withParent($egg->getSlug(), 1.0)->build();
        $usersEgg = Ingredient::copyFromOriginal($egg, self::USER, $egg->getSlug() . self::USER);
        $usersYolk = Ingredient::copyFromOriginal($yolk, self::USER, $yolk->getSlug() . self::USER)->makePartOf($usersEgg->getSlug(), 1.0);
        $now = new \DateTimeImmutable();
        $this->assemble(
            [$egg, $yolk, $usersEgg, $usersYolk],
            aliases: [
                IngredientAlias::create(self::USER, $egg->getSlug(), $usersEgg->getSlug(), AliasKind::ADOPTION, 'activation', $now),
                IngredientAlias::create(self::USER, $yolk->getSlug(), $usersYolk->getSlug(), AliasKind::ADOPTION, 'activation', $now),
            ],
        );
        $this->reader->willRead('22082', $this->page(["2 jaunes d'oeuf", '3 oeufs']));

        // When
        $this->import();

        // Then : les ingrédients de la neutre sont des copies ordinaires du propriétaire réservé, sans parent (la copie du catalogue ne recopie jamais le lien)
        foreach ($this->neutral()->getRows() as $row) {
            $this->assertSame(self::OWNER, $row->getIngredient()->getCreatorId());
            $this->assertNull($row->getIngredient()->getParentSlug(), $row->getIngredient()->getName());
        }
        $this->assertSame(
            [$yolk->getSlug(), $egg->getSlug()],
            array_map(static fn(RecipeRow $row): ?string => $row->getIngredient()->getAdoptedFrom(), $this->neutral()->getRows()->toArray()),
            'les deux ingrédients de la neutre sont bien des copies du catalogue, dont le jaune est une partie là-bas',
        );
        $this->assertNotContains(self::OWNER, $this->partLinks->linkedUsers, 'le propriétaire réservé des recettes Marmiton n\'est jamais relié');

        // And : la copie de l'utilisateur retrouve SES ingrédients par provenance (il garde son lien), rien n'est copié, rien à relier
        $slugs = array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $this->recipes->findOneById('copy-id')->getRows()->toArray());
        $this->assertSame([$usersYolk->getSlug(), $usersEgg->getSlug()], $slugs);
        $this->assertSame($usersEgg->getSlug(), $this->ingredients->findOneBySlug($usersYolk->getSlug())->getParentSlug());
        $this->assertSame([], $this->partLinks->linkedUsers);
    }

    public function testTheNeutralIsNeverRewrittenByAnImportOfAKnownLink(): void
    {
        // Given
        $this->import('first-user', self::URL, 'first-copy');
        $before = $this->neutral();
        $rowIds = array_map(static fn(RecipeRow $row): string => $row->getId(), $before->getRows()->toArray());

        // When : la page a changé entre-temps (jamais relue)
        $this->reader->willRead('22082', $this->page(['1 oeuf'], 'Tout autre titre'));
        $this->import('second-user', self::URL, 'second-copy');

        // Then
        $after = $this->neutral();
        $this->assertSame('Tarte au citron meringuée', $after->getName());
        $this->assertSame($rowIds, array_map(static fn(RecipeRow $row): string => $row->getId(), $after->getRows()->toArray()));
        $this->assertSame(1, $this->reader->callCount());
    }

    public function testTheCopyIsAnOrdinaryRecipeOfTheUserThatHeCanChange(): void
    {
        // Given
        $this->import();

        // When : la personne modifie sa copie
        $copy = $this->recipes->findOneById('copy-id');
        $copy->update('Ma tarte', new RecipeTypeBuilder()->isMeal()->build(), 2);
        $copy->changeFavorite(true, self::USER);
        $copy->changeSecret(true, self::USER);

        // Then : rien ne l'en empêche, la neutre reste telle quelle
        $this->assertSame('Ma tarte', $copy->getName());
        $this->assertTrue($copy->isFavorite());
        $this->assertTrue($copy->isSecret());
        $this->assertSame('Tarte au citron meringuée', $this->neutral()->getName());
    }
}
