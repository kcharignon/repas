<?php

namespace Repas\Tests\Shared\Application\Service;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Application\Service\CommandBus;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\MockMessageBus;
use RuntimeException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

class CommandBusTest extends TestCase
{
    private MockMessageBus $messageBus;
    private CommandBus $commandBus;

    protected function setUp(): void
    {
        $this->messageBus = new MockMessageBus();
        $this->commandBus = new CommandBus($this->messageBus);
    }

    public function testDispatchForwardsTheCommandToTheMessageBus(): void
    {
        // Given
        $command = $this->aCommand();

        // When
        $this->commandBus->dispatch($command);

        // Then
        $this->assertSame($command, $this->messageBus->getLastMessage());
    }

    public function testDispatchReturnsHandlerResult(): void
    {
        // Given
        $command = $this->aCommand();
        $this->messageBus->willReturn($command::class, 'created');

        // When
        $result = $this->commandBus->dispatch($command);

        // Then
        $this->assertSame('created', $result);
    }

    public function testDispatchUnwrapsDomainException(): void
    {
        // Given
        $command = $this->aCommand();
        $domainException = new class('already exists') extends DomainException {
        };
        $this->messageBus->willFailWith($command::class, $domainException);

        // Then
        $this->expectExceptionObject($domainException);

        // When
        $this->commandBus->dispatch($command);
    }

    public function testDispatchKeepsHandlerFailedExceptionForTechnicalError(): void
    {
        // Given
        $command = $this->aCommand();
        $this->messageBus->willFailWith($command::class, new RuntimeException('boom'));

        // Then
        $this->expectException(HandlerFailedException::class);

        // When
        $this->commandBus->dispatch($command);
    }

    public function testDispatchFailsWhenNobodyHandledTheCommand(): void
    {
        // Given
        $command = $this->aCommand();
        $this->messageBus->willLeaveUnhandled($command::class);

        // Then
        $this->expectException(RuntimeException::class);

        // When
        $this->commandBus->dispatch($command);
    }

    private function aCommand(): object
    {
        return new class {
        };
    }
}
