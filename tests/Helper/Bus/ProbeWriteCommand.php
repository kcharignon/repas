<?php

namespace Repas\Tests\Helper\Bus;


/**
 * Commande factice qui écrit en base puis peut échouer, pour prouver l'atomicité de `command.bus` (voir
 * CommandBusTransactionTest).
 */
final readonly class ProbeWriteCommand
{
    public function __construct(
        public string $unitName,
        public bool $failAfterWriting,
    ) {
    }
}
