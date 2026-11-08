<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipesDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipeTypes\OtherPeopleRecipeTypesDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Application\Query\ListRecipeUsages\RecipeUsagesDTO;
use Repas\Repas\Application\Query\ListUsableRecipes\UsableRecipesDTO;
use Repas\Repas\Domain\Exception\RecipeException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class RecipeInMemoryLoader implements RecipeLoader
{
    /** @var list<array{string, string, bool}> */
    public array $getCalls = [];

    /** @var list<array{string, string}> */
    public array $listByAuthorAndTypeCalls = [];

    /** @var list<string> */
    public array $listFavoritesOfAuthorCalls = [];

    /** @var list<array{string, int, int, string|null, bool, string|null}> */
    public array $listOfOtherPeopleCalls = [];

    /** @var list<array{string, bool}> */
    public array $listOtherPeopleTypesCalls = [];

    /** @var list<array{string, string|null, bool, string|null}> */
    public array $listUsableCalls = [];

    /** @var list<array{string, string}> */
    public array $listUsagesCalls = [];

    public function __construct(
        private readonly ?RecipeDTO $recipe = null,
        private readonly ?RecipeTypeRecipesDTO $recipesByType = null,
        private readonly OtherPeopleRecipesDTO $otherPeopleRecipes = new OtherPeopleRecipesDTO([], 0),
        /** @var list<RecipeSummaryDTO> */
        private readonly array $favorites = [],
        private readonly OtherPeopleRecipeTypesDTO $otherPeopleTypes = new OtherPeopleRecipeTypesDTO([], 0),
        private readonly UsableRecipesDTO $usable = new UsableRecipesDTO([], 0, true),
        private readonly RecipeUsagesDTO $usages = new RecipeUsagesDTO([], 0),
    ) {
    }

    public function get(string $id, string $userId, bool $isAdministrator = false): RecipeDTO
    {
        $this->getCalls[] = [$id, $userId, $isAdministrator];

        return $this->recipe ?? throw RecipeException::notFound($id);
    }

    public function listByAuthorAndType(string $authorId, string $typeSlug): RecipeTypeRecipesDTO
    {
        $this->listByAuthorAndTypeCalls[] = [$authorId, $typeSlug];

        return $this->recipesByType ?? throw RecipeException::typeNotFound($typeSlug);
    }

    public function listFavoritesOfAuthor(string $authorId): array
    {
        $this->listFavoritesOfAuthorCalls[] = $authorId;

        return $this->favorites;
    }

    public function listOfOtherPeople(string $userId, int $limit, int $offset, ?string $search = null, bool $isAdministrator = false, ?string $typeSlug = null): OtherPeopleRecipesDTO
    {
        $this->listOfOtherPeopleCalls[] = [$userId, $limit, $offset, $search, $isAdministrator, $typeSlug];

        return $this->otherPeopleRecipes;
    }

    public function listOtherPeopleTypes(string $userId, bool $isAdministrator = false): OtherPeopleRecipeTypesDTO
    {
        $this->listOtherPeopleTypesCalls[] = [$userId, $isAdministrator];

        return $this->otherPeopleTypes;
    }

    public function listUsable(string $userId, ?string $forRecipeId, bool $secret, ?string $search): UsableRecipesDTO
    {
        $this->listUsableCalls[] = [$userId, $forRecipeId, $secret, $search];

        return $this->usable;
    }

    public function listUsages(string $recipeId, string $userId): RecipeUsagesDTO
    {
        $this->listUsagesCalls[] = [$recipeId, $userId];

        return $this->usages;
    }
}
