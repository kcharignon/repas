<?php

namespace Repas\User\Infrastructure\Http\Api\GetUser;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetUserApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/users/{id}', name: 'api_get_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('HIMSELF', 'id')]
    #[OA\Get(summary: "Un utilisateur (lui-même, ou un administrateur) ; c'est le `Location` de l'inscription")]
    #[OA\Tag(name: 'Users')]
    #[OA\Response(response: 200, description: "Identité et préférences de l'utilisateur", content: new OA\JsonContent(ref: new Model(type: CurrentUserDTO::class)))]
    #[OA\Response(response: 403, description: "Ni lui-même ni administrateur")]
    #[OA\Response(response: 404, description: 'Inconnu')]
    public function __invoke(string $id): JsonResponse
    {
        /** @var CurrentUserDTO $user */
        $user = $this->queryBus->ask(new GetCurrentUserQuery($id));

        return new JsonResponse($user);
    }
}
