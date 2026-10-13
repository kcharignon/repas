<?php

namespace Repas\Tests\Shared\Application\Service;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Application\Interface\QueryInterface;
use Repas\Shared\Application\Service\QueryBus;
use Repas\Shared\Domain\Exception\DomainException;
use Repas\Tests\Helper\MockMessageBus;
use RuntimeException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

class QueryBusTest extends TestCase
{
    private MockMessageBus $messageBus;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        $this->messageBus = new MockMessageBus();
        $this->queryBus = new QueryBus($this->messageBus);
    }

    public function testAskForwardsTheQueryToTheMessageBus(): void
    {
        // Given
        $query = $this->aQuery();

        // When
        $this->queryBus->ask($query);

        // Then
        $this->assertSame($query, $this->messageBus->getLastMessage());
    }

    public function testAskReturnsHandlerResult(): void
    {
        // Given
        $query = $this->aQuery();
        $this->messageBus->willReturn($query::class, ['result']);

        // When
        $result = $this->queryBus->ask($query);

        // Then
        $this->assertSame(['result'], $result);
    }

    public function testAskUnwrapsDomainException(): void
    {
        // Given
        $query = $this->aQuery();
        $domainException = new class('not found') extends DomainException {
        };
        $this->messageBus->willFailWith($query::class, $domainException);

        // Then
        $this->expectExceptionObject($domainException);

        // When
        $this->queryBus->ask($query);
    }

    public function testAskKeepsHandlerFailedExceptionForTechnicalError(): void
    {
        // Given
        $query = $this->aQuery();
        $this->messageBus->willFailWith($query::class, new RuntimeException('boom'));

        // Then
        $this->expectException(HandlerFailedException::class);

        // When
        $this->queryBus->ask($query);
    }

    public function testAskFailsWhenNobodyHandledTheQuery(): void
    {
        // Given
        $query = $this->aQuery();
        $this->messageBus->willLeaveUnhandled($query::class);

        // Then
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The query was not handled correctly.');

        // When
        $this->queryBus->ask($query);
    }

    private function aQuery(): QueryInterface
    {
        return new class implements QueryInterface {
        };
    }
}
