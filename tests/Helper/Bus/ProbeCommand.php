<?php

namespace Repas\Tests\Helper\Bus;


/**
 * Commande factice, traitée par ProbeCommandHandler sur `command.bus` uniquement (voir BusWiringTest).
 */
final readonly class ProbeCommand
{
    public const string OK = 'ok';
    public const string DOMAIN_ERROR = 'domain-error';
    public const string TECHNICAL_ERROR = 'technical-error';

    public function __construct(
        public string $mode = self::OK,
    ) {
    }
}
