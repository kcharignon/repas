<?php

namespace Repas\Tests\Helper\Bus;


use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Query factice, traitée par ProbeQueryHandler sur `query.bus` uniquement (voir BusWiringTest).
 */
final readonly class ProbeQuery implements QueryInterface
{
    public const string OK = 'ok';
    public const string DOMAIN_ERROR = 'domain-error';
    public const string TECHNICAL_ERROR = 'technical-error';

    public function __construct(
        public string $mode = self::OK,
    ) {
    }
}
