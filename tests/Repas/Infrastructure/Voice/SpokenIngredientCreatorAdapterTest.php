<?php

namespace Repas\Tests\Repas\Infrastructure\Voice;

use LogicException;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateIngredient\CreateIngredientCommand;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Infrastructure\Voice\SpokenIngredientCreatorAdapter;
use Repas\Shared\Application\Dto\CreatedIngredient;
use Repas\Shared\Application\Dto\SpokenChoice;
use Repas\Tests\Helper\InMemoryLoader\DepartmentInMemoryLoader;
use Repas\Tests\Helper\InMemoryLoader\UnitInMemoryLoader;
use Repas\Tests\Helper\MockCommandBus;

/**
 * L'adaptateur seulement (Loaders et bus simulés) : les catalogues sont rendus tels quels en choix de `Shared`, la création passe par la
 * commande existante `CreateIngredient` (une seule unité, aucun coefficient, image vide). Le parcours avec les vrais bus est dans
 * `SpokenIngredientCreatorFlowTest`.
 */
class SpokenIngredientCreatorAdapterTest extends TestCase
{
    private MockCommandBus $bus;
    private DepartmentInMemoryLoader $departments;
    private UnitInMemoryLoader $units;

    protected function setUp(): void
    {
        $this->bus = new MockCommandBus();
        $this->departments = new DepartmentInMemoryLoader(
            [new DepartmentDTO('boucherie', 'boucherie', 'b.png'), new DepartmentDTO('fruit-et-legume', 'fruit & légume', 'f.png')],
            new DepartmentDTO('fruit-et-legume', 'fruit & légume', 'f.png'),
        );
        $this->units = new UnitInMemoryLoader([new UnitDTO('kilo', 'kilo', 'kg', 'WEIGHT', 1000.0), new UnitDTO('unite', 'unité', '', 'COUNT', 1.0)]);
    }

    private function adapter(): SpokenIngredientCreatorAdapter
    {
        return new SpokenIngredientCreatorAdapter($this->departments, $this->units, $this->bus);
    }

    public function testTheCataloguesAreGivenAsSlugAndNameInTheOrderOfTheLoaders(): void
    {
        // When / Then
        $this->assertEquals([new SpokenChoice('boucherie', 'boucherie'), new SpokenChoice('fruit-et-legume', 'fruit & légume')], $this->adapter()->departments());
        $this->assertEquals([new SpokenChoice('kilo', 'kilo'), new SpokenChoice('unite', 'unité')], $this->adapter()->units());
    }

    public function testItCreatesTheIngredientWithOneUnitForPurchaseAndCookingAndNoCoefficient(): void
    {
        // Given
        $this->bus->willReturn(CreateIngredientCommand::class, 'ciboulette-user-id');

        // When
        $created = $this->adapter()->create('user-id', 'ciboulette', 'fruit-et-legume', 'unite');

        // Then : le slug rendu par le handler, le nom du rayon lu avant l'écriture
        $this->assertEquals(new CreatedIngredient('ciboulette-user-id', 'ciboulette', 'fruit & légume'), $created);
        $this->assertSame(['fruit-et-legume'], $this->departments->getCalls);
        $command = $this->bus->dispatched()[0];
        $this->assertInstanceOf(CreateIngredientCommand::class, $command);
        $this->assertSame(
            ['ciboulette', '', 'fruit-et-legume', 'unite', 'unite', 'user-id', null, false],
            [$command->name, $command->image, $command->departmentSlug, $command->defaultCookingUnitSlug, $command->defaultPurchaseUnitSlug, $command->userId, $command->coefficient, $command->isBase],
        );
    }

    public function testAnUnknownDepartmentIsRefusedBeforeAnythingIsDispatched(): void
    {
        // Given
        $this->departments = new DepartmentInMemoryLoader();

        // Then
        $this->expectExceptionObject(DepartmentException::notFound());
        try {
            $this->adapter()->create('user-id', 'ciboulette', 'inconnu', 'unite');
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
        $this->adapter()->create('user-id', 'ciboulette', 'fruit-et-legume', 'unite');
    }

    public function testAHandlerThatDoesNotReturnTheSlugIsABug(): void
    {
        // Given : le bus ne renvoie rien
        $this->expectException(LogicException::class);

        // When
        $this->adapter()->create('user-id', 'ciboulette', 'fruit-et-legume', 'unite');
    }
}
