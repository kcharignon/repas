<?php

namespace Repas\Drive\Application\Command\ForgetChoice;


use Repas\Drive\Domain\Model\Retailer;

readonly class ForgetChoiceCommand
{
    public function __construct(
        public string $userId,
        public Retailer $retailer,
        public string $storeId,
        public string $ingredientSlug,
    ) {
    }
}
