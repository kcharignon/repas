<?php

namespace Repas\Tests\Shared\Application\Service;


use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Tests\Helper\Bus\ProbeCommand;
use Repas\Tests\Helper\Bus\ProbeCommandHandler;
use Repas\Tests\Helper\Bus\ProbeDomainException;
use Repas\Tests\Helper\Bus\ProbeQuery;
use Repas\Tests\Helper\Bus\ProbeQueryHandler;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Câblage réel (kernel, vrais buses Messenger, handlers factices de tests/Helper/Bus) : ce que les tests unitaires de
 * QueryBus / CommandBus ne peuvent pas prouver (ADR 0006). Aucune base de données.
 *
 * ProbeQueryHandler n'est déclaré que sur `query.bus`, ProbeCommandHandler que sur `command.bus` : si QueryBus ou
 * CommandBus était câblé sur le mauvais bus (piège de Compliance, ADR 0012), le message ne trouverait pas son handler.
 */
class BusWiringTest extends KernelTestCase
{
    private QueryBusInterface $queryBus;
    private CommandBusInterface $commandBus;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->queryBus = static::getContainer()->get(QueryBusInterface::class);
        $this->commandBus = static::getContainer()->get(CommandBusInterface::class);
    }

    public function testQueryBusReachesTheHandlerDeclaredOnTheQueryBus(): void
    {
        // When
        $result = $this->queryBus->ask(new ProbeQuery());

        // Then
        $this->assertSame(ProbeQueryHandler::RESULT, $result);
    }

    public function testCommandBusReachesTheHandlerDeclaredOnTheCommandBus(): void
    {
        // When
        $result = $this->commandBus->dispatch(new ProbeCommand());

        // Then
        $this->assertSame(ProbeCommandHandler::RESULT, $result);
    }

    public function testQueryHandlerIsNotRegisteredOnTheCommandBus(): void
    {
        // Given
        $rawCommandBus = static::getContainer()->get('command.bus');

        // Then
        $this->expectException(NoHandlerForMessageException::class);

        // When
        $this->assertInstanceOf(MessageBusInterface::class, $rawCommandBus);
        $rawCommandBus->dispatch(new ProbeQuery());
    }

    public function testCommandHandlerDeclaredOnTheCommandBusIsNotRegisteredOnTheQueryBus(): void
    {
        // Given
        $rawQueryBus = static::getContainer()->get('query.bus');

        // Then
        $this->expectException(NoHandlerForMessageException::class);

        // When
        $this->assertInstanceOf(MessageBusInterface::class, $rawQueryBus);
        $rawQueryBus->dispatch(new ProbeCommand());
    }

    public function testQueryBusRethrowsTheDomainExceptionOfARealHandler(): void
    {
        // Then
        $this->expectException(ProbeDomainException::class);
        $this->expectExceptionMessage('probe domain error');

        // When
        $this->queryBus->ask(new ProbeQuery(ProbeQuery::DOMAIN_ERROR));
    }

    public function testCommandBusRethrowsTheDomainExceptionOfARealHandler(): void
    {
        // Then
        $this->expectException(ProbeDomainException::class);
        $this->expectExceptionMessage('probe domain error');

        // When
        $this->commandBus->dispatch(new ProbeCommand(ProbeCommand::DOMAIN_ERROR));
    }

    public function testQueryBusKeepsTechnicalErrorsWrapped(): void
    {
        // When
        try {
            $this->queryBus->ask(new ProbeQuery(ProbeQuery::TECHNICAL_ERROR));
            $this->fail('HandlerFailedException attendue.');
        } catch (HandlerFailedException $exception) {
            // Then
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame('probe technical error', $exception->getPrevious()->getMessage());
        }
    }

    public function testCommandBusKeepsTechnicalErrorsWrapped(): void
    {
        // When
        try {
            $this->commandBus->dispatch(new ProbeCommand(ProbeCommand::TECHNICAL_ERROR));
            $this->fail('HandlerFailedException attendue.');
        } catch (HandlerFailedException $exception) {
            // Then
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame('probe technical error', $exception->getPrevious()->getMessage());
        }
    }
}
