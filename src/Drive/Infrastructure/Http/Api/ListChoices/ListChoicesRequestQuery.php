<?php

namespace Repas\Drive\Infrastructure\Http\Api\ListChoices;


use Repas\Drive\Domain\Model\Retailer;

final readonly class ListChoicesRequestQuery
{
    public function __construct(
        public Retailer $retailer,
    ) {
    }
}
