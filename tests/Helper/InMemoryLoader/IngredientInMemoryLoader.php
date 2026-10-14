<?php

namespace Repas\Tests\Helper\InMemoryLoader;


use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Application\Query\GetIngredient\IngredientDTO;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListCompatibleUnits\CompatibleUnitDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;

/**
 * Double idiot : renvoie les DTO programmés tels quels (ni filtre, ni tri) et enregistre ses appels.
 */
class IngredientInMemoryLoader implements IngredientLoader
{
    /** @var list<array{string, string, bool}> */
    public array $listByDepartmentCalls = [];

    /** @var list<array{string, string, bool}> */
    public array $getCalls = [];

    /** @var list<string> */
    public array $listCompatibleUnitsCalls = [];

    /** @var list<string> */
    public array $listAvailableCalls = [];

    /**
     * @param list<CompatibleUnitDTO> $compatibleUnits
     * @param list<IngredientOptionDTO> $availableIngredients
     */
    public function __construct(
        private readonly ?DepartmentIngredientsDTO $departmentIngredients = null,
        private readonly ?IngredientDTO $ingredient = null,
        private readonly array $compatibleUnits = [],
        private readonly array $availableIngredients = [],
    ) {
    }

    public function listByDepartment(string $departmentSlug, string $viewerId, bool $viewerIsAdmin): DepartmentIngredientsDTO
    {
        $this->listByDepartmentCalls[] = [$departmentSlug, $viewerId, $viewerIsAdmin];

        return $this->departmentIngredients ?? throw DepartmentException::notFound();
    }

    public function get(string $slug, string $viewerId, bool $viewerIsAdmin): IngredientDTO
    {
        $this->getCalls[] = [$slug, $viewerId, $viewerIsAdmin];

        return $this->ingredient ?? throw IngredientException::notFound($slug);
    }

    public function listCompatibleUnits(string $ingredientSlug): array
    {
        $this->listCompatibleUnitsCalls[] = $ingredientSlug;

        return $this->compatibleUnits;
    }
    public function listAvailable(string $userId, ?string $search = null): array
    {
        $this->listAvailableCalls[] = $search === null ? $userId : [$userId, $search];

        return $this->availableIngredients;
    }
}
