<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeHandler;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Application\Service\RecipeCopier;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\IngredientNameMatcher;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\Builder\ConversionBuilder;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientMatchCandidatesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientPartLinkInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;

/**
 * Un administrateur modifie DIRECTEMENT une recette SANS PROPRIÉTAIRE, la neutre d'une recette Marmiton (ADR 0037, amendement du 2026-11-08,
 * MAR-005), en unitaire : les lignes qu'il garde restent telles quelles, celles qu'il choisit parmi SES ingrédients deviennent des ingrédients
 * du propriétaire réservé (réutilisés ou copiés, jamais en double d'une modification à l'autre), aucun événement, et tout autre utilisateur
 * la trouve figée. La même chose sur la vraie base, avec les versions et les prochains copieurs : `ModifyRecipeWithoutOwnerTest`.
 */
class UpdateRecipeWithoutOwnerHandlerTest extends TestCase
{
    private const string RESERVED = Recipe::MARMITON_OWNER_ID;
    private const string ADMIN = 'admin-id';
    private const string NEUTRAL = 'neutral-id';

    private UpdateRecipeHandler $handler;
    private RecipeInMemoryRepository $recipes;
    private IngredientInMemoryRepository $ingredients;
    private ConversionInMemoryRepository $conversions;
    private IngredientAliasInMemoryRepository $aliases;
    private IngredientPartLinkInMemoryRepository $partLinks;
    private SpyEventDispatcher $events;
    private int $ingredientsAtStart;

    private Ingredient $adminFlour;
    private Ingredient $adminEgg;
    private Ingredient $reservedFlour;

    protected function setUp(): void
    {
        // L'administrateur a sa farine et ses œufs ; le propriétaire réservé a déjà une farine, copiée de celle de l'administrateur
        $this->adminFlour = new IngredientBuilder()->isFlour()->withCreatorId(self::ADMIN)->build();
        $this->adminEgg = new IngredientBuilder()->isEgg()->withCreatorId(self::ADMIN)->build();
        $this->reservedFlour = new IngredientBuilder()->isFlour()->withCreatorId(self::RESERVED)->withAdoptedFrom($this->adminFlour->getSlug())->build();

        $this->assemble([$this->adminFlour, $this->adminEgg, $this->reservedFlour], [$this->neutral()]);
    }

    private function neutral(): Recipe
    {
        return new RecipeBuilder()->withId(self::NEUTRAL)->asMarmitonNeutral('22082')->withName('Tarte')->withServing(4)->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient($this->reservedFlour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(250))->build();
    }

    /**
     * @param list<Ingredient> $ingredients
     * @param list<Recipe> $recipes
     * @param list<Conversion> $conversions
     * @param list<IngredientAlias> $aliases
     */
    private function assemble(array $ingredients, array $recipes, array $conversions = [], array $aliases = []): void
    {
        $this->ingredients = new IngredientInMemoryRepository($ingredients);
        $this->ingredientsAtStart = count($ingredients);
        $this->recipes = new RecipeInMemoryRepository($recipes);
        $this->conversions = new ConversionInMemoryRepository([new ConversionBuilder()->isKiloToGramme()->build(), ...$conversions]);
        $this->aliases = new IngredientAliasInMemoryRepository($aliases);
        $this->partLinks = new IngredientPartLinkInMemoryRepository();
        $this->events = new SpyEventDispatcher();
        $units = new UnitInMemoryRepository(array_map(static fn(UnitBuilder $unit) => $unit->build(), [
            new UnitBuilder()->isUnite(), new UnitBuilder()->isGramme(), new UnitBuilder()->isKilo(), new UnitBuilder()->isSoupSpoon(), new UnitBuilder()->isBlock(),
        ]));
        $conversionService = new ConversionService($this->conversions, $units);
        $slugResolver = new IngredientSlugResolver($this->aliases);
        $this->handler = new UpdateRecipeHandler(
            $this->recipes,
            new RecipeTypeInMemoryRepository([new RecipeTypeBuilder()->isMeal()->build(), new RecipeTypeBuilder()->isDessert()->build()]),
            $this->ingredients,
            $units,
            $conversionService,
            $slugResolver,
            new RecipeCopier(
                $this->recipes,
                $this->ingredients,
                $this->conversions,
                $slugResolver,
                new IngredientMatchCandidatesInMemoryRepository($this->ingredients),
                new IngredientNameMatcher(),
                new IngredientSlugPolicy(),
                $conversionService,
                $this->partLinks,
                new UuidGenerator(),
                $this->events,
            ),
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: float}> $rows `[ingrédient, unité, quantité]`
     */
    private function edit(array $rows, string $name = 'tarte corrigée', string $userId = self::ADMIN, bool $byAdministrator = true, ?bool $secret = null, string $recipeId = self::NEUTRAL, string $type = 'dessert', int $serving = 6): void
    {
        ($this->handler)(new UpdateRecipeCommand(
            $recipeId,
            $name,
            $serving,
            Tab::fromArray(...array_map(static fn(array $row): UpdateRecipeRowSubCommand => new UpdateRecipeRowSubCommand($row[0], $row[1], $row[2]), $rows)),
            $type,
            secret: $secret,
            userId: $userId,
            byAdministrator: $byAdministrator,
        ));
    }

    /**
     * @return list<string> `ingrédient|quantité|unité|créateur` de chaque ligne de la recette
     */
    private function rowsOf(string $recipeId = self::NEUTRAL): array
    {
        return array_values(array_map(
            static fn(RecipeRow $row): string => sprintf('%s|%s|%s|%s', $row->getIngredient()->getName(), $row->getQuantity(), $row->getUnit()->getSlug(), $row->getIngredient()->getCreatorId()),
            $this->recipes->findOneById($recipeId)->getRows()->toArray(),
        ));
    }

    /**
     * @return list<Ingredient> les ingrédients du propriétaire réservé
     */
    private function reservedIngredients(): array
    {
        return array_values($this->ingredients->findAll()->filter(static fn(Ingredient $ingredient): bool => $ingredient->getCreatorId() === self::RESERVED)->toArray());
    }

    // --- L'administrateur modifie la recette sans propriétaire -------------------------------------------------------------------------------

    public function testAnAdministratorChangesTheNameTheServingTheTypeAndTheRowsAndTheTagAndTheLinkOfOriginStay(): void
    {
        // When : il garde la farine réservée telle quelle (c'est ce que l'éditeur renvoie) et ajoute un de SES ingrédients
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 300.0], [$this->adminEgg->getSlug(), 'unite', 5.0]]);

        // Then : le nom prend sa majuscule en silence (ADR 0030), le reste est ce qu'il a envoyé ; l'œuf est celui du propriétaire réservé
        $neutral = $this->recipes->findOneById(self::NEUTRAL);
        $this->assertSame(['Tarte corrigée', 6, 'dessert'], [$neutral->getName(), $neutral->getServing(), $neutral->getType()->getSlug()]);
        $this->assertSame(['Farine|300|gramme|' . self::RESERVED, 'Œuf|5|unite|' . self::RESERVED], $this->rowsOf());
        $this->assertSame(['22082', 'https://www.marmiton.org/recettes/recette_tarte-au-citron_22082.aspx', self::RESERVED, null, false, false], [
            $neutral->getMarmitonId(), $neutral->getSourceUrl(), $neutral->getAuthorId(), $neutral->getOriginalId(), $neutral->isSecret(), $neutral->isFavorite(),
        ]);
    }

    public function testTheRowsTheEditorSendsBackAsTheyAreCostNoIngredientAtAll(): void
    {
        // When : un simple changement de quantité, tout le reste renvoyé tel quel
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 275.0]], name: 'Tarte', type: 'plat', serving: 4);

        // Then : aucun ingrédient copié ni créé, aucun événement
        $this->assertSame(['Farine|275|gramme|' . self::RESERVED], $this->rowsOf());
        $this->assertSame($this->ingredientsAtStart, $this->ingredients->findAll()->count());
        $this->assertSame([], $this->events->eventDispatched);
    }

    public function testAnIngredientOfTheAdministratorIsTranslatedIntoACopyForTheReservedOwnerWithItsOriginItsUnitsAndItsOwnConversions(): void
    {
        // Given : l'œuf de l'administrateur a le marqueur « de base » et une conversion propre
        $egg = new IngredientBuilder()->isEgg()->withCreatorId(self::ADMIN)->withBase()->build();
        $conversion = new ConversionBuilder()->withIngredient($egg)->withStartUnit(new UnitBuilder()->isUnite())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(60)->build();
        $this->assemble([$this->adminFlour, $egg, $this->reservedFlour], [$this->neutral()], [$conversion]);

        // When
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 250.0], [$egg->getSlug(), 'unite', 2.0]]);

        // Then : une copie réservée, d'une autre identité, qui retient l'origine, jamais le marqueur ; l'œuf de l'administrateur est intact
        $copied = array_values(array_filter($this->reservedIngredients(), static fn(Ingredient $ingredient): bool => $ingredient->getName() === 'Œuf'));
        $this->assertCount(1, $copied);
        $this->assertNotSame($egg->getSlug(), $copied[0]->getSlug());
        $this->assertSame([self::RESERVED, $egg->getSlug(), false, 'divers', 'unite', 'unite'], [
            $copied[0]->getCreatorId(), $copied[0]->getAdoptedFrom(), $copied[0]->isBase(),
            $copied[0]->getDepartment()->getSlug(), $copied[0]->getDefaultCookingUnit()->getSlug(), $copied[0]->getDefaultPurchaseUnit()->getSlug(),
        ]);
        $own = $this->conversions->findByIngredient($copied[0]);
        $this->assertCount(1, $own);
        $this->assertSame(60.0, $own->reset()->getCoefficient());
        $this->assertSame(self::ADMIN, $this->ingredients->findOneBySlug($egg->getSlug())->getCreatorId());
        $this->assertTrue($this->ingredients->findOneBySlug($egg->getSlug())->isBase());
    }

    public function testAnIngredientOfTheAdministratorWhoseReservedCopyExistsIsReusedAndNothingIsCopied(): void
    {
        // When : la farine de l'administrateur, dont le propriétaire réservé a déjà une copie (même origine)
        $this->edit([[$this->adminFlour->getSlug(), 'gramme', 300.0]]);

        // Then
        $this->assertSame(['Farine|300|gramme|' . self::RESERVED], $this->rowsOf());
        $this->assertSame([$this->reservedFlour->getSlug()], array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $this->recipes->findOneById(self::NEUTRAL)->getRows()->toArray()));
        $this->assertSame($this->ingredientsAtStart, $this->ingredients->findAll()->count());
    }

    public function testTheOriginAloneRetrievesTheReservedIngredientWhateverItsNameIs(): void
    {
        // Given : le propriétaire réservé a un « Oeufs entiers de poule » copié de l'œuf de l'administrateur (un nom que celui-ci ne retrouverait pas)
        $reservedEgg = new IngredientBuilder()->withName('Oeufs entiers de poule')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withDefaultCookingUnit(new UnitBuilder()->isUnite())
            ->withDefaultPurchaseUnit(new UnitBuilder()->isUnite())->withCreatorId(self::RESERVED)->withAdoptedFrom($this->adminEgg->getSlug())->build();
        $this->assemble([$this->adminFlour, $this->adminEgg, $this->reservedFlour, $reservedEgg], [$this->neutral()]);

        // When
        $this->edit([[$this->adminEgg->getSlug(), 'unite', 2.0]]);

        // Then : la provenance suffit, rien n'est copié
        $this->assertSame(['Oeufs entiers de poule|2|unite|' . self::RESERVED], $this->rowsOf());
        $this->assertSame($this->ingredientsAtStart, $this->ingredients->findAll()->count());
    }

    public function testSavingTwiceWithTheSameIngredientOfTheAdministratorNeverCopiesItTwice(): void
    {
        // When : « Œuf » est copié à la première modification, puis l'administrateur en renvoie le même ingrédient (le sien) une seconde fois
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 250.0], [$this->adminEgg->getSlug(), 'unite', 2.0]]);
        $afterFirst = $this->ingredients->findAll()->count();
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 250.0], [$this->adminEgg->getSlug(), 'unite', 3.0]]);
        $this->edit([[$this->adminFlour->getSlug(), 'gramme', 250.0], [$this->adminEgg->getSlug(), 'unite', 4.0]]);

        // Then : un seul « Œuf » réservé, le même pour les trois versions
        $this->assertSame($this->ingredientsAtStart + 1, $afterFirst);
        $this->assertSame($afterFirst, $this->ingredients->findAll()->count());
        $this->assertCount(1, array_filter($this->reservedIngredients(), static fn(Ingredient $ingredient): bool => $ingredient->getName() === 'Œuf'));
        $this->assertSame(['Farine|250|gramme|' . self::RESERVED, 'Œuf|4|unite|' . self::RESERVED], $this->rowsOf());
    }

    public function testTheSlugsAreReadForTheAdministratorAnOldSlugLandsOnHisIngredient(): void
    {
        // Given : un ancien slug que les alias de l'administrateur désignent
        $this->assemble(
            [$this->adminFlour, $this->adminEgg, $this->reservedFlour],
            [$this->neutral()],
            aliases: [IngredientAlias::create(self::ADMIN, 'ancien-oeuf', $this->adminEgg->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable())],
        );

        // When
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 250.0], ['ancien-oeuf', 'unite', 2.0]]);

        // Then
        $this->assertSame(['Farine|250|gramme|' . self::RESERVED, 'Œuf|2|unite|' . self::RESERVED], $this->rowsOf());
    }

    public function testTwoRowsOfTheSameIngredientOfTheAdministratorShareOneIngredientOfTheReservedOwner(): void
    {
        // When
        $this->edit([[$this->adminEgg->getSlug(), 'unite', 2.0], [$this->reservedFlour->getSlug(), 'gramme', 100.0], [$this->adminEgg->getSlug(), 'unite', 3.0]]);

        // Then
        $rows = $this->recipes->findOneById(self::NEUTRAL)->getRows()->toArray();
        $this->assertCount(3, $rows);
        $this->assertSame($rows[0]->getIngredient()->getSlug(), $rows[2]->getIngredient()->getSlug());
        $this->assertSame($this->ingredientsAtStart + 1, $this->ingredients->findAll()->count());
    }

    public function testASubIngredientOfTheAdministratorIsCopiedWithoutAParentAndNothingIsLinked(): void
    {
        // Given : « Jaune d'œuf » est une partie de l'œuf de l'administrateur
        $yolk = new IngredientBuilder()->withName('Jaune d\'œuf')->withDepartment(new DepartmentBuilder()->isMiscellaneous())->withCreatorId(self::ADMIN)->withParent($this->adminEgg->getSlug(), 3.0)->build();
        $this->assemble([$this->adminFlour, $this->adminEgg, $yolk, $this->reservedFlour], [$this->neutral()]);

        // When
        $this->edit([[$yolk->getSlug(), 'unite', 2.0]]);

        // Then : un ingrédient réservé ordinaire, sans parent (comme à l'import) ; la liaison des sous-ingrédients n'est pas lancée
        $copied = array_values(array_filter($this->reservedIngredients(), static fn(Ingredient $ingredient): bool => $ingredient->getName() === 'Jaune d\'œuf'));
        $this->assertCount(1, $copied);
        $this->assertFalse($copied[0]->isPart());
        $this->assertNull($copied[0]->getParentSlug());
        $this->assertSame([], $this->partLinks->linkedUsers);
        $this->assertSame($this->adminEgg->getSlug(), $this->ingredients->findOneBySlug($yolk->getSlug())->getParentSlug());
    }

    public function testAnExistingReservedIngredientThatCannotConvertTheUnitsIsNotUsedAndTheNextOneWhichCanIsPreferred(): void
    {
        // Given : deux « Farine » réservées de la même origine ; la première ne convertit pas la cuillère à soupe, la seconde oui
        $first = new IngredientBuilder()->isFlour()->withCreatorId(self::RESERVED)->withAdoptedFrom($this->adminFlour->getSlug())->withSlug('farine-a-premiere')->build();
        $second = new IngredientBuilder()->isFlour()->withCreatorId(self::RESERVED)->withAdoptedFrom($this->adminFlour->getSlug())->withSlug('farine-b-seconde')->build();
        $spoonOfTheAdmin = new ConversionBuilder()->withIngredient($this->adminFlour)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(12)->build();
        $spoonOfTheSecond = new ConversionBuilder()->withIngredient($second)->withStartUnit(new UnitBuilder()->isSoupSpoon())->withEndUnit(new UnitBuilder()->isGramme())->withCoefficient(12)->build();
        $this->assemble([$this->adminFlour, $first, $second], [$this->neutral()], [$spoonOfTheAdmin, $spoonOfTheSecond]);

        // When
        $this->edit([[$this->adminFlour->getSlug(), 'cuillere-a-soupe', 2.0]]);

        // Then : la seconde sert, rien n'est copié
        $this->assertSame(['farine-b-seconde'], array_map(static fn(RecipeRow $row): string => $row->getIngredient()->getSlug(), $this->recipes->findOneById(self::NEUTRAL)->getRows()->toArray()));
        $this->assertSame($this->ingredientsAtStart, $this->ingredients->findAll()->count());
    }

    // --- Les droits et les refus : rien n'est écrit ------------------------------------------------------------------------------------------

    public function testAnOrdinaryUserFindsTheRecipeWithoutAnOwnerFrozenAndNothingIsWritten(): void
    {
        // When & Then : sans `byAdministrator`, la garde du modèle refuse (403), même si le contrôleur avait oublié le droit
        try {
            $this->edit([[$this->adminEgg->getSlug(), 'unite', 2.0]], userId: 'someone-id', byAdministrator: false);
            $this->fail('Un utilisateur ordinaire ne modifie pas la recette sans propriétaire.');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::MARMITON_FROZEN, 403], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertNothingWritten();
    }

    public function testTheRecipeCannotBeMadeSecretNotEvenByAnAdministratorButTheCurrentChoiceMayBeSentBack(): void
    {
        // When & Then : rendre la neutre secrète est le choix d'un auteur, et elle n'en a pas
        try {
            $this->edit([[$this->adminEgg->getSlug(), 'unite', 2.0]], secret: true);
            $this->fail('La neutre ne devient pas secrète.');
        } catch (RecipeException $exception) {
            $this->assertSame([RecipeException::SECRET_FORBIDDEN, 403], [$exception->getMessage(), $exception->getCode()]);
        }
        $this->assertNothingWritten();

        // When : l'éditeur renvoie le choix actuel (`false`)
        $this->edit([[$this->reservedFlour->getSlug(), 'gramme', 260.0]], secret: false);

        // Then : sans effet et sans erreur
        $this->assertFalse($this->recipes->findOneById(self::NEUTRAL)->isSecret());
        $this->assertSame(['Farine|260|gramme|' . self::RESERVED], $this->rowsOf());
    }

    public function testAnIngredientOfAnotherAccountIsUnknownToTheAdministratorAndNothingIsWritten(): void
    {
        // Given
        $someoneElses = new IngredientBuilder()->isButter()->withCreatorId('another-id')->build();
        $this->assemble([$this->adminFlour, $this->adminEgg, $this->reservedFlour, $someoneElses], [$this->neutral()]);

        // When & Then
        $this->expectExceptionObject(IngredientException::notFound($someoneElses->getSlug()));
        try {
            $this->edit([[$this->adminEgg->getSlug(), 'unite', 2.0], [$someoneElses->getSlug(), 'gramme', 100.0]]);
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testAnIngredientOfTheAdministratorWhoseUnitDoesNotConvertIsRefusedBeforeAnythingIsCopied(): void
    {
        // When & Then : une cuillère à soupe de l'œuf de l'administrateur (acheté à l'unité, aucune conversion) est refusée, comme dans son éditeur
        $this->expectException(IngredientException::class);
        try {
            $this->edit([[$this->adminEgg->getSlug(), 'unite', 2.0], [$this->adminFlour->getSlug(), 'cuillere-a-soupe', 1.0]]);
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testAReservedLineThatDoesNotConvertKeepsItsPlaceButANewPairIsChecked(): void
    {
        // Given : une ligne « à régler » (une cuillère à soupe de la farine réservée, sans conversion) est déjà dans la neutre
        $neutral = new RecipeBuilder()->withId(self::NEUTRAL)->asMarmitonNeutral('22082')->withName('Tarte')->withServing(4)->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()->withIngredient($this->reservedFlour)->withUnit(new UnitBuilder()->isSoupSpoon())->withQuantity(1))->build();
        $this->assemble([$this->adminFlour, $this->adminEgg, $this->reservedFlour], [$neutral]);

        // When : l'éditeur la renvoie telle quelle, avec une autre quantité
        $this->edit([[$this->reservedFlour->getSlug(), 'cuillere-a-soupe', 2.0]]);

        // Then : tolérée (ADR 0037 décision 8, correctif #449)
        $this->assertSame(['Farine|2|cuillere-a-soupe|' . self::RESERVED], $this->rowsOf());

        // When & Then : un couple NOUVEAU qui ne se convertit pas reste refusé
        $this->expectException(IngredientException::class);
        $this->edit([[$this->reservedFlour->getSlug(), 'cuillere-a-soupe', 2.0], [$this->reservedFlour->getSlug(), 'unite', 1.0]]);
    }

    public function testAnOutdatedVersionIsRefusedAndNothingIsWritten(): void
    {
        // When & Then
        $this->expectExceptionObject(RecipeException::outdated(self::NEUTRAL));
        ($this->handler)(new UpdateRecipeCommand(
            self::NEUTRAL, 'Tarte', 4, Tab::fromArray(new UpdateRecipeRowSubCommand($this->reservedFlour->getSlug(), 'gramme', 250)), 'plat',
            expectedVersion: 7, userId: self::ADMIN, byAdministrator: true,
        ));
    }

    public function testAnAdministratorEditingTheOrdinaryRecipeOfSomeoneElseStillReadsTheSlugsForItsAuthor(): void
    {
        // Given : la recette ordinaire d'Alice (ses ingrédients), modifiée par l'administrateur
        $alicesFlour = new IngredientBuilder()->isFlour()->withCreatorId('alice-id')->build();
        $alices = new RecipeBuilder()->withId('alices')->withAuthorId('alice-id')->withName('Crêpes')
            ->addRow(new RecipeRowBuilder()->withIngredient($alicesFlour)->withUnit(new UnitBuilder()->isGramme())->withQuantity(100))->build();
        $this->assemble([$this->adminFlour, $alicesFlour, $this->reservedFlour], [$this->neutral(), $alices]);

        // When
        $this->edit([[$alicesFlour->getSlug(), 'gramme', 150.0]], name: 'Crêpes', recipeId: 'alices', type: 'plat');

        // Then : rien n'est traduit pour le propriétaire réservé, la recette reste à Alice avec SON ingrédient
        $this->assertSame(['Farine|150|gramme|alice-id'], $this->rowsOf('alices'));
        $this->assertSame($this->ingredientsAtStart, $this->ingredients->findAll()->count());
    }

    /**
     * Les doubles en mémoire rendent LA recette enregistrée, pas une copie : le nom, posé avant le refus, y reste. Que rien ne soit écrit en
     * base quand un refus survient en cours de route (transaction du bus) se prouve sur la vraie base (`ModifyRecipeWithoutOwnerTest`) ; ici, les
     * lignes, les ingrédients et les événements.
     */
    private function assertNothingWritten(): void
    {
        $this->assertSame(['Farine|250|gramme|' . self::RESERVED], $this->rowsOf());
        $this->assertSame($this->ingredientsAtStart, $this->ingredients->findAll()->count());
        $this->assertSame([], $this->events->eventDispatched);
    }
}
