<?php

namespace Repas\Repas\Application\Query\GetConversion;

use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetConversionQueryHandler
{
    public function __construct(
        private ConversionLoader $conversionLoader,
    ) {
    }

    /**
     * @throws ConversionException
     */
    public function __invoke(GetConversionQuery $query): ConversionDTO
    {
        return $this->conversionLoader->get($query->conversionId);
    }
}
