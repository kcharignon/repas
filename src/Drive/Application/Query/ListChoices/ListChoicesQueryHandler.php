<?php

namespace Repas\Drive\Application\Query\ListChoices;

use Repas\Drive\Application\Loader\ChoiceLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListChoicesQueryHandler
{
    public function __construct(
        private ChoiceLoader $choiceLoader,
    ) {
    }

    /**
     * @return list<ChoiceDTO>
     */
    public function __invoke(ListChoicesQuery $query): array
    {
        return $this->choiceLoader->list($query->userId, $query->retailer);
    }
}
