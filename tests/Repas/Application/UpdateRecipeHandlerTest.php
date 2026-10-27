<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeCommand;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeHandler;
use Repas\Repas\Application\Command\UpdateRecipe\UpdateRecipeRowSubCommand;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateRecipeHandlerTest extends TestCase
{
    /** L'auteur des recettes de l'essai : le propriétaire par défaut des ingrédients de test. */
    private const string AUTHOR = IngredientBuilder::DEFAULT_CREATOR_ID;

    private readonly UpdateRecipeHandler $handler;
    private readonly RecipeRepository $recipeRepository;
    private readonly RecipeTypeRepository $recipeTypeRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly IngredientAliasInMemoryRepository $aliasRepository;
    private readonly UnitRepository $unitRepository;

    protected function setUp(): void
    {
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository([
            new RecipeTypeBuilder()->isMeal()->build(),
        ]);
        $this->ingredientRepository = new IngredientInMemoryRepository([
            new IngredientBuilder()->isPasta()->build(),
            new IngredientBuilder()->isParmesan()->build(),
        ]);
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isUnite()->build(),
        ]);
        $this->aliasRepository = new IngredientAliasInMemoryRepository();

        $this->handler = new UpdateRecipeHandler(
            $this->recipeRepository,
            $this->recipeTypeRepository,
            $this->ingredientRepository,
            $this->unitRepository,
            new ConversionService(new ConversionInMemoryRepository(), $this->unitRepository),
            new IngredientSlugResolver($this->aliasRepository),
        );
    }

    public function testSuccessfullyHandleUpdateRecipe(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            8,
            Tab::fromArray(
                new UpdateRecipeRowSubCommand(
                    'pate',
                    'gramme',
                    5000,
                ),
                new UpdateRecipeRowSubCommand(
                    'parmesan',
                    'gramme',
                    400,
                ),
            ),
            'plat'
        );


        // Act
        ($this->handler)($command);

        // Assert
        $expectedRecipe = new RecipeBuilder()
            ->withId($recipe->getId())
            ->withName('Pates carbo. épurées')
            ->withServing(8)
            ->withAuthorId($recipe->getAuthorId())
            ->withRecipeType(new RecipeTypeBuilder()->isMeal())
            ->addRow(new RecipeRowBuilder()
                ->withRecipeId($recipe->getId())
                ->withIngredient(new IngredientBuilder()->isPasta())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(5000)
            )
            ->addRow(new RecipeRowBuilder()
                ->withRecipeId($recipe->getId())
                ->withIngredient(new IngredientBuilder()->isParmesan())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(400)
            )
            ->build();
        $actual = $this->recipeRepository->findOneById($recipe->getId());
        RepasAssert::assertRecipe($actual, $expectedRecipe, ["RecipeRow" => ["id"]]);
    }


    public function testAnOldSlugOfACommonTheAuthorReceivedACopyOfLandsOnHisIngredient(): void
    {
        // Arrange : l'ancien commun « pate » est maintenant celui du propriétaire du catalogue ; l'auteur de la recette en a reçu une copie, et la
        // mise à jour est envoyée avec l'ancien slug (onglet resté ouvert)
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $catalogPasta = new IngredientBuilder()->isPasta()->withCreator(new UserBuilder()->withId('owner-id')->build())->build();
        $copy = new IngredientBuilder()->isPasta()->withCreator(new UserBuilder()->withId(self::AUTHOR)->build(), true)->withAdoptedFrom('pate')->build();
        $ingredients = new IngredientInMemoryRepository([$catalogPasta, $copy, new IngredientBuilder()->isParmesan()->build()]);
        $this->aliasRepository->save(IngredientAlias::create(self::AUTHOR, 'pate', $copy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));
        $handler = $this->handlerWith($ingredients);

        // Act
        $handler(new UpdateRecipeCommand($recipe->getId(), 'Pates', 4, Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'gramme', 500), new UpdateRecipeRowSubCommand('parmesan', 'gramme', 50)), 'plat'));

        // Assert : la copie pour les pâtes, l'ingrédient de l'auteur tel quel pour le parmesan ; rien n'est adopté à la volée
        $slugs = $this->slugsOf($recipe->getId());
        $this->assertSame([$copy->getSlug(), 'parmesan'], $slugs);
        $this->assertCount(1, $this->aliasRepository->all());
        $this->assertCount(3, $ingredients->findAll());
    }

    public function testTheSlugsAreReadForTheAuthorOfTheRecipeNotForWhoeverSendsThem(): void
    {
        // Arrange : un autre utilisateur a une copie des pâtes, pas l'auteur (un administrateur peut modifier la recette de n'importe qui)
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $this->aliasRepository->save(IngredientAlias::create('someone-else', 'pate', 'pate' . 'someone-else', AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));

        // Act
        ($this->handler)(new UpdateRecipeCommand($recipe->getId(), 'Pates', 4, Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'gramme', 500)), 'plat'));

        // Assert : l'auteur n'a pas cet alias, sa recette garde le slug de son propre ingrédient
        $this->assertSame(['pate'], $this->slugsOf($recipe->getId()));
    }

    public function testWithoutAnyAliasTheRowsKeepTheirSlugsAndNothingIsAdopted(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);

        // Act
        ($this->handler)(new UpdateRecipeCommand($recipe->getId(), 'Pates', 4, Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'gramme', 500), new UpdateRecipeRowSubCommand('parmesan', 'gramme', 50)), 'plat'));

        // Assert
        $this->assertEqualsCanonicalizing(['pate', 'parmesan'], $this->slugsOf($recipe->getId()));
        $this->assertSame([], $this->aliasRepository->all());
        $this->assertCount(2, $this->ingredientRepository->findAll());
    }

    public function testAnIngredientOfTheCatalogOwnerWhoseCopyTheAuthorNeverReceivedIsUnknown(): void
    {
        // Arrange : « pate » n'est qu'à un autre compte, l'auteur n'en a aucune copie ni alias
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId('another-author')->build();
        $this->recipeRepository->save($recipe);

        // Assert : un ingrédient d'un autre est inconnu, il n'est adopté nulle part
        $this->expectExceptionObject(IngredientException::notFound('pate'));

        // Act
        ($this->handler)(new UpdateRecipeCommand($recipe->getId(), 'Pates', 4, Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'gramme', 500)), 'plat'));
    }

    private function handlerWith(IngredientRepository $ingredients): UpdateRecipeHandler
    {
        return new UpdateRecipeHandler(
            $this->recipeRepository,
            $this->recipeTypeRepository,
            $ingredients,
            $this->unitRepository,
            new ConversionService(new ConversionInMemoryRepository(), $this->unitRepository),
            new IngredientSlugResolver($this->aliasRepository),
        );
    }
    /**
     * @return list<string> les slugs d'ingrédient des lignes de la recette enregistrée
     */
    private function slugsOf(string $recipeId): array
    {
        return array_values(array_map(
            static fn($row): string => $row->getIngredient()->getSlug(),
            $this->recipeRepository->findOneById($recipeId)->getRows()->toArray(),
        ));
    }

    public function testFailedHandleUpdateRecipeUnknownRecipe(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            8,
            Tab::fromArray(
                new UpdateRecipeRowSubCommand(
                    'pate',
                    'gramme',
                    5000,
                ),
                new UpdateRecipeRowSubCommand(
                    'parmesan',
                    'gramme',
                    400,
                ),
            ),
            'plat'
        );

        // Assert
        $this->expectExceptionObject(RecipeException::notFound($recipe->getId()));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnIngredientCustomisedByAnotherUser(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $private = new IngredientBuilder()->isParmesan()->withCreator(new UserBuilder()->withId('other-id')->build(), true)->build();
        $this->ingredientRepository->save($private);
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            4,
            Tab::fromArray(new UpdateRecipeRowSubCommand($private->getSlug(), 'gramme', 400)),
            'plat',
        );

        // Assert : l'ingrédient d'un autre que l'auteur de la recette est traité comme inconnu
        $this->expectExceptionObject(IngredientException::notFound($private->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testFailedHandleUpdateRecipeUnknownRecipeType(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo. épurées',
            8,
            Tab::fromArray(
                new UpdateRecipeRowSubCommand(
                    'pate',
                    'gramme',
                    5000,
                ),
                new UpdateRecipeRowSubCommand(
                    'parmesan',
                    'gramme',
                    400,
                ),
            ),
            'non-existant'
        );

        // Assert
        $this->expectExceptionObject(RecipeException::typeNotFound('non-existant'));

        // Act
        ($this->handler)($command);

    }

    public function testFailedHandleUpdateRecipeUnknownUnit(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo.',
            4,
            Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'inconnue', 3)),
            'plat'
        );

        // Assert
        $this->expectExceptionObject(UnitException::notFound('inconnue'));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAUnitWithoutAConversionToThePurchaseUnit(): void
    {
        // Arrange : les pâtes s'achètent au gramme, aucune conversion ne mène de l'unité au gramme
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->build();
        $this->recipeRepository->save($recipe);
        $pasta = $this->ingredientRepository->findOneBySlug('pate');
        $command = new UpdateRecipeCommand(
            $recipe->getId(),
            'Pates carbo.',
            4,
            Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'unite', 3)),
            'plat'
        );

        // Assert
        $this->expectExceptionObject(IngredientException::cannotConvertToUnit($pasta, new UnitBuilder()->isUnite()->build(), $pasta->getDefaultPurchaseUnit()));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnOutdatedVersionAndChangesNothing(): void
    {
        // Arrange : la recette est à la version 4, le client a lu la 3
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->withVersion(4)->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand($recipe->getId(), 'Nouveau nom', 4, Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'gramme', 3)), 'plat', expectedVersion: 3);

        // Assert
        $this->expectExceptionObject(RecipeException::outdated($recipe->getId()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertNotSame('Nouveau nom', $this->recipeRepository->findOneById($recipe->getId())->getName());
        }
    }

    public function testAcceptsTheCurrentVersion(): void
    {
        // Arrange
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withAuthorId(self::AUTHOR)->withVersion(4)->build();
        $this->recipeRepository->save($recipe);
        $command = new UpdateRecipeCommand($recipe->getId(), 'Nouveau nom', 4, Tab::fromArray(new UpdateRecipeRowSubCommand('pate', 'gramme', 3)), 'plat', expectedVersion: 4);

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertSame('Nouveau nom', $this->recipeRepository->findOneById($recipe->getId())->getName());
    }
}
