<?php

namespace Repas\Drive\Application\Command\RememberLastRetailer;


use Repas\Drive\Domain\Model\Retailer;

readonly class RememberLastRetailerCommand
{
    public function __construct(
        public string $userId,
        public Retailer $retailer,
    ) {
    }
}
