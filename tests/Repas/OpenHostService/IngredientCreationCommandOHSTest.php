<?php

namespace Repas\Tests\Repas\OpenHostService;

use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\OpenHostService\IngredientCreationCommandOHS;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Model\CreatedIngredient;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Unit;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\UnitInMemoryRepository;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'Open Host Service seulement (dépôts en mémoire et bus simulé) : les catalogues sont rendus tels quels, triés par slug, la création passe par
 * la commande existante `CreateIngredient` (une seule unité, aucun coefficient, image vide). Le parcours avec les vrais bus est dans
 * `IngredientCreationCommandOHSFlowTest`.
 */
class IngredientCreationCommandOHSTest extends TestCase
{
    private MockCommandBus $bus;
    private DepartmentInMemoryRepository $departments;
    private UnitInMemoryRepository $units;

    protected function setUp(): void
    {
        $this->bus = new MockCommandBus();
        $this->departments = new DepartmentInMemoryRepository([
            new DepartmentBuilder()->withSlug('boucherie')->withName('boucherie')->build(),
            new DepartmentBuilder()->withSlug('fruit-et-legume')->withName('fruit & légume')->build(),
        ]);
        $this->units = new UnitInMemoryRepository([new UnitBuilder()->isKilo()->build(), new UnitBuilder()->isUnite()->build()]);
    }

    private function ohs(): IngredientCreationCommandOHS
    {
        return new IngredientCreationCommandOHS($this->departments, $this->units, $this->bus);
    }

    public function testTheCataloguesAreGivenAsModelsInTheOrderOfTheRepositories(): void
    {
        // When / Then
        $this->assertSame(['boucherie', 'fruit-et-legume'], array_map(static fn(Department $department): string => $department->getSlug(), $this->ohs()->departments()));
        $this->assertSame(['kilo', 'unite'], array_map(static fn(Unit $unit): string => $unit->getSlug(), $this->ohs()->units()));
    }

    public function testItCreatesTheIngredientWithOneUnitForPurchaseAndCookingAndNoCoefficient(): void
    {
        // Given
        $this->bus->willReturn(CreateIngredientCommand::class, 'ciboulette-user-id');

        // When
        $created = $this->ohs()->create('user-id', 'ciboulette', 'fruit-et-legume', 'unite');

        // Then : le slug rendu par le handler, le nom du rayon lu avant l'écriture
        $this->assertEquals(new CreatedIngredient('ciboulette-user-id', 'ciboulette', 'fruit & légume'), $created);
        $command = $this->bus->dispatched()[0];
        $this->assertInstanceOf(CreateIngredientCommand::class, $command);
        $this->assertSame(
            ['ciboulette', '', 'fruit-et-legume', 'unite', 'unite', 'user-id', null, false],
            [$command->name, $command->image, $command->departmentSlug, $command->defaultCookingUnitSlug, $command->defaultPurchaseUnitSlug, $command->userId, $command->coefficient, $command->isBase],
        );
    }

    public function testAnUnknownDepartmentIsRefusedBeforeAnythingIsDispatched(): void
    {
        // Then
        $this->expectExceptionObject(DepartmentException::notFound());
        try {
            $this->ohs()->create('user-id', 'ciboulette', 'inconnu', 'unite');
        } finally {
            $this->assertSame([], $this->bus->dispatched());
        }
    }

    public function testADomainErrorOfTheCommandIsPropagated(): void
    {
        // Given : l'utilisateur a déjà cet ingrédient
        $this->bus->willThrow(CreateIngredientCommand::class, IngredientException::nameAlreadyUsedInDepartment());

        // Then
        $this->expectExceptionObject(IngredientException::nameAlreadyUsedInDepartment());
        $this->ohs()->create('user-id', 'ciboulette', 'fruit-et-legume', 'unite');
    }

    public function testAHandlerThatDoesNotReturnTheSlugIsABug(): void
    {
        // Given : le bus ne renvoie rien
        $this->expectException(LogicException::class);

        // When
        $this->ohs()->create('user-id', 'ciboulette', 'fruit-et-legume', 'unite');
    }
}
