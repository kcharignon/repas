<?php

namespace Repas\User\Infrastructure\Http\Api\ListUsers;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Application\Query\ListUsers\ListUsersQuery;
use Repas\User\Application\Query\ListUsers\UserSummaryDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ListUsersApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/users', name: 'api_list_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(summary: 'Utilisateurs et leurs statistiques (administrateur), triés par email')]
    #[OA\Tag(name: 'Users')]
    #[OA\Response(
        response: 200,
        description: 'Liste des utilisateurs',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: UserSummaryDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 3),
        ]),
    )]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    public function __invoke(): JsonResponse
    {
        /** @var list<UserSummaryDTO> $users */
        $users = $this->queryBus->ask(new ListUsersQuery());

        return new JsonResponse(['member' => $users, 'totalItems' => count($users)]);
    }
}
