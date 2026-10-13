<?php

namespace Repas\Tests\Helper\Bus;


use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class ProbeQueryHandler
{
    public const string RESULT = 'probe-query-result';

    public function __invoke(ProbeQuery $query): string
    {
        return match ($query->mode) {
            ProbeQuery::DOMAIN_ERROR => throw new ProbeDomainException('probe domain error'),
            ProbeQuery::TECHNICAL_ERROR => throw new RuntimeException('probe technical error'),
            default => self::RESULT,
        };
    }
}
