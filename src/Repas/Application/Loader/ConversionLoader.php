<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;

interface ConversionLoader
{
    /**
     * Toutes les conversions, triées par ingrédient (les conversions communes à la fin) puis par unité de départ.
     *
     * @return list<ConversionDTO>
     */
    public function list(): array;

    /**
     * @throws ConversionException
     */
    public function get(string $id): ConversionDTO;
}
