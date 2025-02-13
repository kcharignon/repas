<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\CreateDepartment\CreateDepartmentCommand;
use Repas\Repas\Application\CreateDepartment\CreateDepartmentHandler;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\InMemoryRepository\DepartmentInMemoryRepository;
use Repas\Tests\Helper\RepasAssert;

class CreateDepartmentHandlerTest extends TestCase
{
    private readonly CreateDepartmentHandler $handler;
    private readonly DepartmentRepository $departmentRepository;

    protected function setUp(): void
    {
        $this->departmentRepository = new DepartmentInMemoryRepository();
        $this->handler = new CreateDepartmentHandler(
            $this->departmentRepository,
        );
    }

    public function testHandleSuccessfullyCreateDepartment(): void
    {
        // Arrange
        $expected = new DepartmentBuilder()->withName("Nouveau rayon")->build();
        $command = new CreateDepartmentCommand(
            $expected->getName(),
            $expected->getImage(),
        );

        // Act
        ($this->handler)($command);

        // Assert
        $actual = $this->departmentRepository->findOneBySlug($expected->getSlug());
        RepasAssert::assertDepartment($expected, $actual);
    }
}
