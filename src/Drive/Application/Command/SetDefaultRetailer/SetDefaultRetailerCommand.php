<?php

namespace Repas\Drive\Application\Command\SetDefaultRetailer;


use Repas\Drive\Domain\Model\Retailer;

readonly class SetDefaultRetailerCommand
{
    public function __construct(
        public string $userId,
        /** `null` : la personne n'a plus de drive habituel */
        public ?Retailer $retailer,
    ) {
    }
}
