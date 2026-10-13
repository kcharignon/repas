<?php

namespace Repas\Repas\Application\Query\GetConversion;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class GetConversionQuery implements QueryInterface
{
    public function __construct(
        public string $conversionId,
    ) {
    }
}
