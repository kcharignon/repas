<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\UpdateDepartment\UpdateDepartmentCommand;
use Repas\Repas\Application\UpdateDepartment\UpdateDepartmentHandler;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
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
