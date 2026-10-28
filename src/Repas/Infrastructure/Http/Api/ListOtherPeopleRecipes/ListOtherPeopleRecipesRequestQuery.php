<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListOtherPeopleRecipes;


use Repas\Repas\Application\Query\ListOtherPeopleRecipes\ListOtherPeopleRecipesQuery;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListOtherPeopleRecipesRequestQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: ListOtherPeopleRecipesQuery::MAX_LIMIT)]
        public int $limit = ListOtherPeopleRecipesQuery::MAX_LIMIT,
        #[Assert\Range(min: 0, max: 1_000_000)]
        public int $offset = 0,
        #[Assert\Length(max: 100)]
        public ?string $q = null,
    ) {
    }
}
