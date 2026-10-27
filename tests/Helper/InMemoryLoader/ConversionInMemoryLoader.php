<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionsDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class ConversionInMemoryLoader implements ConversionLoader
{
    public int $listCalls = 0;

    /** @var list<string> les identifiants de lecteur de chaque appel de `list()` */
    public array $listViewers = [];

    /** @var list<array{string, string}> */
    public array $getCalls = [];

    /** @var list<array{string, string}> */
    public array $listOfIngredientCalls = [];

    /**
     * @param list<ConversionDTO> $conversions
     */
    public function __construct(
        private readonly array $conversions = [],
        private readonly ?ConversionDTO $conversion = null,
        private readonly ?IngredientConversionsDTO $ingredientConversions = null,
    ) {
    }

    public function list(string $viewerId): array
    {
        $this->listCalls++;
        $this->listViewers[] = $viewerId;

        return $this->conversions;
    }

    public function get(string $id, string $viewerId): ConversionDTO
    {
        $this->getCalls[] = [$id, $viewerId];

        return $this->conversion ?? throw ConversionException::notFound($id);
    }

    public function listOfIngredient(string $slug, string $viewerId): IngredientConversionsDTO
    {
        $this->listOfIngredientCalls[] = [$slug, $viewerId];

        return $this->ingredientConversions ?? throw IngredientException::notFound($slug);
    }
}
