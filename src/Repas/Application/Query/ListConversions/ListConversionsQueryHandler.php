<?php

namespace Repas\Repas\Application\Query\ListConversions;

use Repas\Repas\Application\Loader\ConversionLoader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListConversionsQueryHandler
{
    public function __construct(
        private ConversionLoader $conversionLoader,
    ) {
    }

    /**
     * @return list<ConversionDTO>
     */
    public function __invoke(ListConversionsQuery $query): array
    {
        return $this->conversionLoader->list();
    }
}
