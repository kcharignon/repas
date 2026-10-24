<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListIngredientsByDepartment;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\DepartmentIngredientsDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\ListIngredientsByDepartmentQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListIngredientsByDepartmentApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/departments/{slug}/ingredients', name: 'api_list_ingredients_by_department', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Ingrédients de l'utilisateur authentifié dans un rayon, triés par nom : les siens seulement, jamais ceux d'un autre (un administrateur compris)")]
    #[OA\Tag(name: 'Ingredients')]
    #[OA\Response(
        response: 200,
        description: 'Le rayon et ses ingrédients (collection `member`) ; `canEdit` est toujours vrai (l\'ingrédient est à l\'utilisateur) ; le champ reste pour les clients existants',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'department', ref: new Model(type: DepartmentDTO::class)),
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: IngredientRowDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 8),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Rayon inconnu')]
    public function __invoke(string $slug, #[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var DepartmentIngredientsDTO $departmentIngredients */
        $departmentIngredients = $this->queryBus->ask(new ListIngredientsByDepartmentQuery($slug, $user->getId()));

        return new JsonResponse([
            'department' => $departmentIngredients->department,
            'member' => $departmentIngredients->ingredients,
            'totalItems' => count($departmentIngredients->ingredients),
        ]);
    }
}
