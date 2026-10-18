<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use Repas\Drive\Domain\Model\Retailer;

readonly class SaveProductChoicesCommand
{
    /**
     * @param list<ChoiceIntent> $intents
     */
    public function __construct(
        public string $userId,
        public Retailer $retailer,
        public string $storeId,
        public array $intents,
    ) {
    }
}
