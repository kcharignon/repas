<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class ConversionInMemoryLoader implements ConversionLoader
{
    public int $listCalls = 0;

    /** @var list<string> */
    public array $getCalls = [];

    /**
     * @param list<ConversionDTO> $conversions
     */
    public function __construct(
        private readonly array $conversions = [],
        private readonly ?ConversionDTO $conversion = null,
    ) {
    }

    public function list(): array
    {
        $this->listCalls++;

        return $this->conversions;
    }

    public function get(string $id): ConversionDTO
    {
        $this->getCalls[] = $id;

        return $this->conversion ?? throw ConversionException::notFound($id);
    }
}
