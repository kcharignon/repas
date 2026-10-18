<?php

namespace Repas\Drive\Application\Query\ListChoices;

use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListChoicesQuery implements QueryInterface
{
    public function __construct(
        public string $userId,
        public Retailer $retailer,
    ) {
    }
}
