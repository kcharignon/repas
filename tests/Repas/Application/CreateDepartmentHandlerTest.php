<?php

namespace Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\CreateDepartment\CreateDepartmentCommand;
use Repas\Repas\Application\Command\CreateDepartment\CreateDepartmentHandler;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Model\DepartmentIcon;
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

    public function testReturnsTheSlugOfTheCreatedDepartment(): void
    {
        // Act
        $slug = ($this->handler)(new CreateDepartmentCommand('Surgelés', 'images/frozen.svg'));

        // Assert
        $this->assertSame('surgeles', $slug);
    }

    public function testACreatedDepartmentHasNoIconUnlessItIsGivenOne(): void
    {
        // Act
        ($this->handler)(new CreateDepartmentCommand('Surgelés', 'images/frozen.svg'));
        ($this->handler)(new CreateDepartmentCommand('Conserves', 'images/can.svg', 'can'));

        // Assert
        $this->assertNull($this->departmentRepository->findOneBySlug('surgeles')->getIcon());
        $this->assertSame(DepartmentIcon::CAN, $this->departmentRepository->findOneBySlug('conserves')->getIcon());
    }

    public function testAnUnknownIconIsRefusedAndNothingIsWritten(): void
    {
        // Assert
        $this->expectExceptionObject(DepartmentException::unknownIcon());

        // Act
        try {
            ($this->handler)(new CreateDepartmentCommand('Surgelés', 'images/frozen.svg', 'sparkles'));
        } finally {
            $this->assertFalse($this->departmentRepository->exists('surgeles'));
        }
    }

    public function testRefusesADepartmentWhoseSlugAlreadyExists(): void
    {
        // Arrange
        ($this->handler)(new CreateDepartmentCommand('Surgelés', 'images/frozen.svg'));

        // Assert
        $this->expectExceptionObject(DepartmentException::alreadyExists('surgeles'));

        // Act
        ($this->handler)(new CreateDepartmentCommand('surgeles', 'images/autre.svg'));
    }
}
