<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeCommand;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeHandler;
use Repas\Repas\Application\Command\CreateRecipe\CreateRecipeRowSubCommand;
use Repas\Shared\Domain\Event\RecipeCreatedEvent;
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
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\RecipeRowBuilder;
use Repas\Tests\Helper\Builder\RecipeTypeBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\ConversionInMemoryRepository;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\RecipeTypeInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;
use Repas\Tests\Helper\InMemoryRepository\SpyEventDispatcher;
use Repas\User\Domain\Model\User;

class CreateRecipeHandlerTest extends TestCase
{
    private readonly CreateRecipeHandler $handler;
    private readonly RecipeTypeRepository $recipeTypeRepository;
    private readonly IngredientRepository $ingredientRepository;
    private readonly IngredientAliasInMemoryRepository $aliasRepository;
    private readonly UnitRepository $unitRepository;
    private readonly RecipeRepository $recipeRepository;
    private readonly SpyEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $baby = new DepartmentBuilder()->isBaby()->build();

        $this->ingredientRepository = new IngredientInMemoryRepository();
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->unitRepository = new UnitInMemoryRepository([
            new UnitBuilder()->isUnite()->build(),
            new UnitBuilder()->isGramme()->build(),
        ]);
        $this->recipeTypeRepository = new RecipeTypeInMemoryRepository([
            new RecipeTypeBuilder()->isMeal()->build(),
            new RecipeTypeBuilder()->isDessert()->build(),
        ]);
        $this->recipeRepository = new RecipeInMemoryRepository();
        $this->eventDispatcher = new SpyEventDispatcher();

        $this->handler = new CreateRecipeHandler(
            $this->recipeTypeRepository,
            $this->ingredientRepository,
            $this->unitRepository,
            $this->recipeRepository,
            new ConversionService(new ConversionInMemoryRepository(), $this->unitRepository),
            $this->eventDispatcher,
            new IngredientSlugResolver($this->aliasRepository),
        );
    }

    public function testHandleSuccessfullyCreateRecipe(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertEquals([new RecipeCreatedEvent('user-id', 'unique_id')], $this->eventDispatcher->eventDispatched);
        $expected = new RecipeBuilder()
            ->withId("unique_id")
            ->withName("Gloubiboulga")
            ->withServing(25)
            ->withAuthor($user)
            ->addRow(
                new RecipeRowBuilder()
                ->withRecipeId("unique_id")
                ->withQuantity(10)
                ->withUnit(new UnitBuilder()->isGramme())
                ->withIngredient($pasta)
            )
            ->withRecipeType($recipeType)
            ->build();
        $actual = $this->recipeRepository->findOneById("unique_id");
        RepasAssert::assertRecipe($expected, $actual, ["RecipeRow" => ["id"]]);
    }


    public function testRefusesAnUnknownUnit(): void
    {
        // Arrange
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $command = new CreateRecipeCommand(
            id: 'unique_id',
            name: 'Gloubiboulga',
            serving: 4,
            authorId: 'user-id',
            rows: new Tab([new CreateRecipeRowSubCommand($pasta->getSlug(), 'inconnue', 10)], CreateRecipeRowSubCommand::class),
            typeSlug: 'plat',
        );

        // Assert
        $this->expectExceptionObject(UnitException::notFound('inconnue'));

        // Act
        ($this->handler)($command);
    }

    public function testRefusesAnIngredientCustomisedByAnotherUser(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $other = new UserBuilder()->withId('other-id')->build();
        $private = new IngredientBuilder()->isPasta()->withCreator($other, true)->build();
        $this->ingredientRepository->save($private);
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();
        $command = new CreateRecipeCommand(
            id: 'unique_id',
            name: 'Gloubiboulga',
            serving: 4,
            authorId: $user->getId(),
            rows: new Tab([new CreateRecipeRowSubCommand($private->getSlug(), 'gramme', 10)], CreateRecipeRowSubCommand::class),
            typeSlug: $recipeType->getSlug(),
        );

        // Assert : l'ingrédient d'un autre est traité comme inconnu
        $this->expectExceptionObject(IngredientException::notFound($private->getSlug()));

        // Act
        ($this->handler)($command);
    }

    public function testAcceptsAnIngredientCustomisedByTheAuthor(): void
    {
        // Arrange
        $user = new UserBuilder()->withId('user-id')->build();
        $own = new IngredientBuilder()->isPasta()->withCreator($user, true)->build();
        $this->ingredientRepository->save($own);
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();
        $command = new CreateRecipeCommand(
            id: 'unique_id',
            name: 'Gloubiboulga',
            serving: 4,
            authorId: $user->getId(),
            rows: new Tab([new CreateRecipeRowSubCommand($own->getSlug(), 'gramme', 10)], CreateRecipeRowSubCommand::class),
            typeSlug: $recipeType->getSlug(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $this->assertSame($own->getSlug(), $this->recipeRepository->findOneById('unique_id')->getRows()->reset()->getIngredient()->getSlug());
    }

    public function testHandleCreateRecipeFailedIngredientNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $recipeType = new RecipeTypeBuilder()->isMeal()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Assert
        $this->expectExceptionObject(IngredientException::NotFound($pasta->getSlug()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testHandleCreateRecipeFailedRecipeTypeNotFound(): void
    {
        // Arrange
        $user = new UserBuilder()->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $recipeType = new RecipeTypeBuilder()->isStarter()->build();

        $rows = new Tab([], CreateRecipeRowSubCommand::class);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: $rows,
            typeSlug: $recipeType->getSlug(),
        );

        $rows[] = new CreateRecipeRowSubCommand(
            ingredientSlug: $pasta->getSlug(),
            unitSlug: 'gramme',
            quantity: 10,
        );

        // Assert
        $this->expectExceptionObject(RecipeException::typeNotFound($recipeType->getSlug()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
        }
    }

    public function testRowsWithTheOldSlugOfACommonTheAuthorReceivedACopyOfLandOnHisCopy(): void
    {
        // Arrange : « pate » est maintenant l'ingrédient du propriétaire du catalogue ; la copie des pâtes de l'auteur, et l'alias qui la désigne
        // (ADR 0028) ; la recette est envoyée avec l'ancien slug
        $user = new UserBuilder()->withId('user-id')->build();
        $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->withCreator(new UserBuilder()->withId('owner-id')->build())->build());
        $copy = new IngredientBuilder()->isPasta()->withCreator($user, true)->withAdoptedFrom('pate')->build();
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'pate', $copy->getSlug(), AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable()));

        // Act
        ($this->handler)($this->commandWith('pate', $user));

        // Assert : la ligne porte la copie de l'auteur, jamais l'ingrédient du propriétaire du catalogue
        $this->assertSame([$copy->getSlug()], $this->slugsOfTheRecipe());
    }

    public function testAnIngredientOfTheCatalogOwnerTheAuthorNeverReceivedIsUnknownAndNothingIsAdopted(): void
    {
        // Arrange : l'auteur a reçu le parmesan, pas les pâtes du propriétaire du catalogue ; il n'y a plus d'adoption à la première utilisation
        $user = new UserBuilder()->withId('user-id')->build();
        $owner = new UserBuilder()->withId('owner-id')->build();
        $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->withCreator($owner)->build());
        $this->ingredientRepository->save(new IngredientBuilder()->isParmesan()->withCreator($owner)->build());
        $parmesanCopy = new IngredientBuilder()->isParmesan()->withCreator($user, true)->withAdoptedFrom('parmesan')->build();
        $this->ingredientRepository->save($parmesanCopy);
        $this->aliasRepository->save(IngredientAlias::create('user-id', 'parmesan', $parmesanCopy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable()));

        // Assert : l'ingrédient d'un autre est inconnu
        $this->expectExceptionObject(IngredientException::notFound('pate'));

        // Act
        try {
            ($this->handler)($this->commandWith('pate', $user));
        } finally {
            // aucune copie, aucun alias de plus
            $this->assertCount(3, $this->ingredientRepository->findAll());
            $this->assertCount(1, $this->aliasRepository->all());
        }
    }

    public function testWithoutAnyAliasTheRowsKeepTheirSlugsAndNothingIsAdopted(): void
    {
        // Arrange : l'utilisateur n'a aucun alias, « pate » est son ingrédient
        $user = new UserBuilder()->withId('user-id')->build();
        $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->build());

        // Act
        ($this->handler)($this->commandWith('pate', $user));

        // Assert
        $this->assertSame(['pate'], $this->slugsOfTheRecipe());
        $this->assertSame([], $this->aliasRepository->all());
        $this->assertCount(1, $this->ingredientRepository->findAll());
    }

    public function testTheAliasOfAnotherUserIsNeverAppliedToTheAuthor(): void
    {
        // Arrange : `other-id` a une copie des pâtes, pas l'auteur de la recette
        $other = new UserBuilder()->withId('other-id')->build();
        $this->ingredientRepository->save(new IngredientBuilder()->isPasta()->build());
        $copy = new IngredientBuilder()->isPasta()->withCreator($other, true)->withAdoptedFrom('pate')->build();
        $this->ingredientRepository->save($copy);
        $this->aliasRepository->save(IngredientAlias::create('other-id', 'pate', $copy->getSlug(), AliasKind::ADOPTION, 'migration-2026-10-21', new DateTimeImmutable()));

        // Act
        ($this->handler)($this->commandWith('pate', new UserBuilder()->withId('user-id')->build()));

        // Assert
        $this->assertSame(['pate'], $this->slugsOfTheRecipe());
    }

    public function testRefusesTheCopyOfAnotherUserEvenThroughTheResolution(): void
    {
        // Arrange : le slug de la copie d'un autre n'est ni un commun ni un alias de l'auteur : inconnu
        $other = new UserBuilder()->withId('other-id')->build();
        $copy = new IngredientBuilder()->isPasta()->withCreator($other, true)->withAdoptedFrom('pate')->build();
        $this->ingredientRepository->save($copy);

        // Assert
        $this->expectExceptionObject(IngredientException::notFound($copy->getSlug()));

        // Act
        ($this->handler)($this->commandWith($copy->getSlug(), new UserBuilder()->withId('user-id')->build()));
    }

    private function commandWith(string $ingredientSlug, User $author): CreateRecipeCommand
    {
        return new CreateRecipeCommand(
            id: 'unique_id',
            name: 'Gloubiboulga',
            serving: 4,
            authorId: $author->getId(),
            rows: Tab::fromArray(new CreateRecipeRowSubCommand($ingredientSlug, 'gramme', 10)),
            typeSlug: new RecipeTypeBuilder()->isMeal()->build()->getSlug(),
        );
    }

    /**
     * @return list<string> les slugs d'ingrédient des lignes de la recette enregistrée
     */
    private function slugsOfTheRecipe(): array
    {
        return array_values(array_map(
            static fn($row): string => $row->getIngredient()->getSlug(),
            $this->recipeRepository->findOneById('unique_id')->getRows()->toArray(),
        ));
    }

    public function testRefusesAUnitWithoutAConversionToThePurchaseUnit(): void
    {
        // Arrange : les pâtes s'achètent au gramme, aucune conversion ne mène de l'unité au gramme
        $user = new UserBuilder()->withId('user-id')->build();
        $pasta = new IngredientBuilder()->isPasta()->build();
        $this->ingredientRepository->save($pasta);
        $command = new CreateRecipeCommand(
            id: "unique_id",
            name: "Gloubiboulga",
            serving: 25,
            authorId: $user->getId(),
            rows: Tab::fromArray(new CreateRecipeRowSubCommand(ingredientSlug: $pasta->getSlug(), unitSlug: 'unite', quantity: 3)),
            typeSlug: new RecipeTypeBuilder()->isMeal()->build()->getSlug(),
        );

        // Assert : refusée à l'enregistrement, pas plus tard à la planification
        $this->expectExceptionObject(IngredientException::cannotConvertToUnit($pasta, new UnitBuilder()->isUnite()->build(), $pasta->getDefaultPurchaseUnit()));

        // Act
        try {
            ($this->handler)($command);
        } finally {
            $this->assertSame([], $this->eventDispatcher->eventDispatched);
            $this->assertSame(0, $this->recipeRepository->findByAuthor($user->getId())->count());
        }
    }
}
