<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Application\Command\UpdateDepartment\UpdateDepartmentHandler;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\DepartmentIcon;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class UpdateDepartmentHandlerTest extends TestCase
{
    private UpdateDepartmentHandler $handler;
    private departmentRepository $departmentRepository;

    protected function setUp(): void
    {
        $this->departmentRepository = new DepartmentInMemoryRepository([
            new DepartmentBuilder()->isBaby()->build(),
        ]);

        $this->handler = new UpdateDepartmentHandler($this->departmentRepository);
    }


    public function testSuccessfullyHandleUpdateDepartment(): void
    {
        // Arrange
        $command = new UpdateDepartmentCommand(
            slug: 'bebe',
            name: 'gros bebe',
            image: 'nouvelle/image.png',
        );

        // Act
        ($this->handler)($command);

        // Assert
        $expected = new DepartmentBuilder()
            ->withSlug('bebe')
            ->withName('gros bebe')
            ->withImage('nouvelle/image.png')
            ->build();
        $actual = $this->departmentRepository->findOneBySlug('bebe');
        RepasAssert::assertDepartment($expected, $actual);
    }



    public function testTheIconIsSetChangedAndErasedOnlyWhenTheCommandAsksFor(): void
    {
        // Act : une icône
        ($this->handler)(new UpdateDepartmentCommand('bebe', 'bebe', 'a.png', 'snowflake', changeIcon: true));
        // Assert
        $this->assertSame(DepartmentIcon::SNOWFLAKE, $this->departmentRepository->findOneBySlug('bebe')->getIcon());

        // Act : le nom et l'image changent, la commande ne parle pas de l'icône (un ancien client) : elle reste
        ($this->handler)(new UpdateDepartmentCommand('bebe', 'gros bebe', 'b.png'));
        // Assert
        $department = $this->departmentRepository->findOneBySlug('bebe');
        $this->assertSame(['gros bebe', 'b.png', DepartmentIcon::SNOWFLAKE], [$department->getName(), $department->getImage(), $department->getIcon()]);

        // Act : une autre icône, puis `null`
        ($this->handler)(new UpdateDepartmentCommand('bebe', 'gros bebe', 'b.png', 'can', changeIcon: true));
        $this->assertSame(DepartmentIcon::CAN, $this->departmentRepository->findOneBySlug('bebe')->getIcon());
        ($this->handler)(new UpdateDepartmentCommand('bebe', 'gros bebe', 'b.png', null, changeIcon: true));

        // Assert : `null` efface
        $this->assertNull($this->departmentRepository->findOneBySlug('bebe')->getIcon());
    }

    public function testAnUnknownIconIsRefusedAndNothingIsWritten(): void
    {
        // Arrange
        ($this->handler)(new UpdateDepartmentCommand('bebe', 'bebe', 'a.png', 'can', changeIcon: true));

        // Act et Assert : le nom, l'image et l'icône ne changent pas
        try {
            ($this->handler)(new UpdateDepartmentCommand('bebe', 'nouveau nom', 'z.png', 'sparkles', changeIcon: true));
            $this->fail("L'icône inconnue aurait dû être refusée.");
        } catch (DepartmentException $exception) {
            $this->assertEquals(DepartmentException::unknownIcon(), $exception);
            $this->assertSame(422, $exception->getCode());
        }
        $department = $this->departmentRepository->findOneBySlug('bebe');
        $this->assertSame(['bebe', 'a.png', DepartmentIcon::CAN], [$department->getName(), $department->getImage(), $department->getIcon()]);
    }

    public function testAnIconIsNotJudgedWhenTheCommandDoesNotTouchIt(): void
    {
        // Act : une valeur d'icône sans `changeIcon` n'est ni lue ni contrôlée
        ($this->handler)(new UpdateDepartmentCommand('bebe', 'gros bebe', 'b.png', 'sparkles'));

        // Assert
        $this->assertNull($this->departmentRepository->findOneBySlug('bebe')->getIcon());
    }

    public function testHandleFailedUpdateDepartmentUnknownDepartment(): void
    {
        // Arrange
        $command = new UpdateDepartmentCommand(
            slug: 'n-existe-pas',
            name: 'gros bebe',
            image: 'nouvelle/image.png',
        );

        // Assert
        $this->expectExceptionObject(DepartmentException::notFound());

        // Act
        ($this->handler)($command);
    }
}
