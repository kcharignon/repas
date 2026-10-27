<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class RecipeInMemoryLoader implements RecipeLoader
{
    /** @var list<string> */
    public array $getCalls = [];

    /** @var list<array{string, string}> */
    public array $listByAuthorAndTypeCalls = [];

    /** @var list<array{string, int, int}> */
    public array $listOfOtherPeopleCalls = [];

    public function __construct(
        private readonly ?RecipeDTO $recipe = null,
        private readonly ?RecipeTypeRecipesDTO $recipesByType = null,
        private readonly OtherPeopleRecipesDTO $otherPeopleRecipes = new OtherPeopleRecipesDTO([], 0),
    ) {
    }

    public function get(string $id): RecipeDTO
    {
        $this->getCalls[] = $id;

        return $this->recipe ?? throw RecipeException::notFound($id);
    }

    public function listByAuthorAndType(string $authorId, string $typeSlug): RecipeTypeRecipesDTO
    {
        $this->listByAuthorAndTypeCalls[] = [$authorId, $typeSlug];

        return $this->recipesByType ?? throw RecipeException::typeNotFound($typeSlug);
    }

    public function listOfOtherPeople(string $userId, int $limit, int $offset): OtherPeopleRecipesDTO
    {
        $this->listOfOtherPeopleCalls[] = [$userId, $limit, $offset];

        return $this->otherPeopleRecipes;
    }
}
