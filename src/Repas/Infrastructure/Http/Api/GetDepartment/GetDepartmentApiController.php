<?php

namespace Repas\Repas\Infrastructure\Http\Api\GetDepartment;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\GetDepartment\GetDepartmentQuery;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetDepartmentApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/departments/{slug}', name: 'api_get_department', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Détail d\'un rayon')]
    #[OA\Tag(name: 'Departments')]
    #[OA\Response(
        response: 200,
        description: 'Un rayon',
        content: new OA\JsonContent(ref: new Model(type: DepartmentDTO::class)),
    )]
    #[OA\Response(response: 404, description: 'Inconnu')]
    public function __invoke(string $slug): JsonResponse
    {
        /** @var DepartmentDTO $item */
        $item = $this->queryBus->ask(new GetDepartmentQuery($slug));

        return new JsonResponse($item);
    }
}
