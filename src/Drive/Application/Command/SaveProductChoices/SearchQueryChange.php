<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\SearchQuery;

/**
 * Ce qu'une intention `ADD_PRODUCT` demande de la recherche mémorisée (DRV-650) : la remplacer par un texte, ou l'effacer. Ne pas
 * en parler du tout, c'est garder celle qui est déjà mémorisée : `ChoiceIntent::$searchQuery` vaut alors `null`, pas un changement.
 */
final readonly class SearchQueryChange
{
    private function __construct(
        public ?SearchQuery $searchQuery,
    ) {
    }

    public static function set(SearchQuery $searchQuery): self
    {
        return new self($searchQuery);
    }

    public static function clear(): self
    {
        return new self(null);
    }

    public function applyTo(Choice $choice): void
    {
        $this->searchQuery === null ? $choice->forgetSearch() : $choice->rememberSearch($this->searchQuery);
    }
}
