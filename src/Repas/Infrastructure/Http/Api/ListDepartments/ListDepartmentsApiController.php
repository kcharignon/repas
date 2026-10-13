<?php

namespace Repas\Repas\Infrastructure\Http\Api\ListDepartments;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\ListDepartmentsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListDepartmentsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/departments', name: 'api_list_departments', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: 'Liste des rayons, triés par slug')]
    #[OA\Tag(name: 'Departments')]
    #[OA\Response(
        response: 200,
        description: 'Liste des rayons',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: DepartmentDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 21),
        ]),
    )]
    public function __invoke(): JsonResponse
    {
        /** @var list<DepartmentDTO> $departments */
        $departments = $this->queryBus->ask(new ListDepartmentsQuery());

        return new JsonResponse(['member' => $departments, 'totalItems' => count($departments)]);
    }
}
