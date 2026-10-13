<?php

namespace Repas\Repas\Application\Query\ListIngredientsByDepartment;

use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Domain\Exception\DepartmentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListIngredientsByDepartmentQueryHandler
{
    public function __construct(
        private IngredientLoader $ingredientLoader,
    ) {
    }

    /**
     * @throws DepartmentException
     */
    public function __invoke(ListIngredientsByDepartmentQuery $query): DepartmentIngredientsDTO
    {
        return $this->ingredientLoader->listByDepartment($query->departmentSlug, $query->viewerId, $query->viewerIsAdmin);
    }
}
