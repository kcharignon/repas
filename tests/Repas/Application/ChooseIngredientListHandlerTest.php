<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\ChooseIngredientList\ChooseIngredientListCommand;
use Repas\Repas\Application\Command\ChooseIngredientList\ChooseIngredientListHandler;
use Repas\Repas\Application\Service\IngredientSlugResolver;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\ShoppingListType;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\InMemoryRepository\IngredientAliasInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ListRoutingInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\ShoppingListTypeInMemoryRepository;

class ChooseIngredientListHandlerTest extends TestCase
{
    private IngredientInMemoryRepository $ingredientRepository;
    private ShoppingListTypeInMemoryRepository $listTypeRepository;
    private IngredientAliasInMemoryRepository $aliasRepository;
    private ChooseIngredientListHandler $handler;
    private string $beurre;

    protected function setUp(): void
    {
        $owner = new UserBuilder()->withId('user-1')->build();
        $other = new UserBuilder()->withId('user-2')->build();
        $butter = new IngredientBuilder()->isButter()->withSlug('beurre-1')->withCreator($owner)->build();
        $this->beurre = $butter->getSlug();
        $this->ingredientRepository = new IngredientInMemoryRepository([
            $butter,
            new IngredientBuilder()->isFlour()->withSlug('farine-2')->withCreator($other)->build(),
        ]);
        $this->listTypeRepository = new ShoppingListTypeInMemoryRepository([
            ShoppingListType::create('butcher', 'user-1', 'Boucherie', new DateTimeImmutable('2026-10-31 10:00:00')),
            ShoppingListType::create('other-type', 'user-2', 'Marché', new DateTimeImmutable('2026-10-31 10:00:00')),
        ]);
        $this->aliasRepository = new IngredientAliasInMemoryRepository();
        $this->handler = new ChooseIngredientListHandler($this->ingredientRepository, $this->listTypeRepository, new IngredientSlugResolver($this->aliasRepository));
    }

    public function testChoosesATypeOfTheUser(): void
    {
        // When
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'butcher', 'user-1'));

        // Then
        $this->assertSame('butcher', $this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
    }

    public function testChoosesTheMainListWithTheReservedValue(): void
    {
        // When
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'main', 'user-1'));

        // Then
        $this->assertSame('main', $this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
    }

    public function testDefaultErasesTheChoice(): void
    {
        // Given
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'butcher', 'user-1'));

        // When
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'default', 'user-1'));

        // Then
        $this->assertNull($this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
    }

    public function testTheChoiceCanBeChangedFromOneTypeToTheMainList(): void
    {
        // Given
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'butcher', 'user-1'));

        // When
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'main', 'user-1'));

        // Then
        $this->assertSame('main', $this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
    }

    public function testAnUnknownTypeIsNotFoundAndNothingIsWritten(): void
    {
        // Given
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        try {
            // When
            ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'unknown-type', 'user-1'));
        } finally {
            // Then
            $this->assertNull($this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
        }
    }

    public function testTheTypeOfAnotherUserIsNotFoundAndNothingIsWritten(): void
    {
        // Given
        $this->expectExceptionObject(ShoppingListTypeException::notFound());

        try {
            // When
            ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'other-type', 'user-1'));
        } finally {
            // Then
            $this->assertNull($this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
        }
    }

    public function testTheIngredientOfAnotherUserIsNotFoundWhoeverAsks(): void
    {
        // Given
        $this->expectExceptionObject(IngredientException::notFound('farine-2'));

        try {
            // When
            ($this->handler)(new ChooseIngredientListCommand('farine-2', 'main', 'user-1'));
        } finally {
            // Then
            $this->assertNull($this->ingredientRepository->findOneBySlug('farine-2')->getListDestination());
        }
    }

    public function testAnUnknownIngredientIsNotFound(): void
    {
        // Given
        $this->expectException(IngredientException::class);

        // When
        ($this->handler)(new ChooseIngredientListCommand('inconnu', 'main', 'user-1'));
    }

    public function testAnOldSlugIsTranslatedByTheAliasesOfTheUser(): void
    {
        // Given : l'ancien slug d'un commun dont l'utilisateur a reçu une copie
        $this->aliasRepository->save(IngredientAlias::create('user-1', 'beurre-commun', $this->beurre, AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-10-31 10:00:00')));

        // When
        ($this->handler)(new ChooseIngredientListCommand('beurre-commun', 'butcher', 'user-1'));

        // Then
        $this->assertSame('butcher', $this->ingredientRepository->findOneBySlug($this->beurre)->getListDestination());
    }

    public function testTheDestinationIsThenReadByTheRouting(): void
    {
        // Given : le rayon du beurre n'a aucun type
        ($this->handler)(new ChooseIngredientListCommand($this->beurre, 'butcher', 'user-1'));
        $routing = new ListRoutingInMemoryRepository($this->ingredientRepository, $this->listTypeRepository);

        // When
        $destinations = $routing->destinationsFor('user-1', [$this->beurre]);

        // Then
        $this->assertSame([$this->beurre => 'butcher'], $destinations);
    }
}
