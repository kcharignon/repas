<?php

namespace Repas\Tests\Shared\Application\Service;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Bus\ProbeDomainException;
use Repas\Tests\Helper\Bus\ProbeWriteCommand;
use Repas\Tests\Helper\DatabaseTestCase;

/**
 * Une commande est atomique : si son handler échoue après avoir écrit, rien n'est conservé (transaction Doctrine sur
 * `command.bus`, ADR 0006). Sans elle, une erreur au milieu d'un cas d'usage laisserait un état partiel.
 */
class CommandBusTransactionTest extends DatabaseTestCase
{
    public function testWhatTheHandlerWroteIsKeptWhenItSucceeds(): void
    {
        // When
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new ProbeWriteCommand('Pincée atomique', failAfterWriting: false));

        // Then
        $this->entityManager->clear();
        $this->assertSame('Pincée atomique', static::getContainer()->get(UnitRepository::class)->findOneBySlug('pincee-atomique')->getName());
    }

    public function testWhatTheHandlerWroteIsRolledBackWhenItFails(): void
    {
        // When
        try {
            static::getContainer()->get(CommandBusInterface::class)->dispatch(new ProbeWriteCommand('Pincée atomique', failAfterWriting: true));
            $this->fail('Le handler devait échouer.');
        } catch (ProbeDomainException) {
        }

        // Then
        $this->entityManager->clear();
        $this->expectExceptionObject(UnitException::notFound('pincee-atomique'));
        static::getContainer()->get(UnitRepository::class)->findOneBySlug('pincee-atomique');
    }
}
