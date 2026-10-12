<?php

namespace Repas\Tests\Shared\Application\Service;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Application\Interface\QueryInterface;
use Repas\Shared\Application\Service\QueryBus;
use Repas\Shared\Domain\Exception\DomainException;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class QueryBusTest extends TestCase
{
    public function testAskReturnsHandlerResult(): void
    {
        // Given
        $query = $this->aQuery();
        $queryBus = new QueryBus($this->busReturning(
            fn (object $message): Envelope => new Envelope($message, [new HandledStamp(['result'], 'handler')])
        ));

        // When
        $result = $queryBus->ask($query);

        // Then
        $this->assertSame(['result'], $result);
    }

    public function testAskUnwrapsDomainException(): void
    {
        // Given
        $query = $this->aQuery();
        $domainException = new class('not found') extends DomainException {
        };
        $queryBus = new QueryBus($this->busReturning(
            fn (object $message): never => throw new HandlerFailedException(new Envelope($message), [$domainException])
        ));

        // Then
        $this->expectExceptionObject($domainException);

        // When
        $queryBus->ask($query);
    }

    public function testAskKeepsHandlerFailedExceptionForTechnicalError(): void
    {
        // Given
        $query = $this->aQuery();
        $queryBus = new QueryBus($this->busReturning(
            fn (object $message): never => throw new HandlerFailedException(
                new Envelope($message),
                [new RuntimeException('boom')]
            )
        ));

        // Then
        $this->expectException(HandlerFailedException::class);

        // When
        $queryBus->ask($query);
    }

    public function testAskFailsWhenNobodyHandledTheQuery(): void
    {
        // Given
        $query = $this->aQuery();
        $queryBus = new QueryBus($this->busReturning(
            fn (object $message): Envelope => new Envelope($message)
        ));

        // Then
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The query was not handled correctly.');

        // When
        $queryBus->ask($query);
    }

    private function aQuery(): QueryInterface
    {
        return new class implements QueryInterface {
        };
    }

    /**
     * @param callable(object): Envelope $dispatch
     */
    private function busReturning(callable $dispatch): MessageBusInterface
    {
        return new class($dispatch) implements MessageBusInterface {
            /** @param callable(object): Envelope $dispatch */
            public function __construct(private $dispatch)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return ($this->dispatch)($message);
            }
        };
    }
}
