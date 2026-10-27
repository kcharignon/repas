<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListOtherPeopleRecipesQuery implements QueryInterface
{
    /** Recettes rendues par page : sans paramètre, la première page de ce nombre, ce qui est tout pour un usage ordinaire. */
    public const int MAX_LIMIT = 200;

    /**
     * @param int $limit le nombre de recettes de la page, de 1 à `MAX_LIMIT`
     * @param int $offset le nombre de recettes sautées (triées par slug)
     */
    public function __construct(
        public string $userId,
        public int $limit = self::MAX_LIMIT,
        public int $offset = 0,
    ) {
    }
}
