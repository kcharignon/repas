<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Drive\Application\Loader\ChoiceLoader;
use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Double idiot : renvoie les DTO programmés quel que soit l'utilisateur demandé et enregistre ses appels.
 */
class ChoiceInMemoryLoader implements ChoiceLoader
{
    /** @var list<array{string, Retailer}> utilisateur et enseigne reçus, dans l'ordre des appels */
    public array $listCalls = [];

    /**
     * @param list<ChoiceDTO> $choices
     */
    public function __construct(
        private readonly array $choices = [],
    ) {
    }

    public function list(string $userId, Retailer $retailer): array
    {
        $this->listCalls[] = [$userId, $retailer];

        return $this->choices;
    }
}
