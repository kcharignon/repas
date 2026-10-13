<?php

namespace Repas\User\Infrastructure\Http\Api\GetCurrentUser;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\User\Application\Query\GetCurrentUser\CurrentUserDTO;
use Repas\User\Application\Query\GetCurrentUser\GetCurrentUserQuery;
use Repas\User\Domain\Model\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetCurrentUserApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/me', name: 'api_get_current_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Utilisateur authentifié par le token JWT")]
    #[OA\Tag(name: 'Current user')]
    #[OA\Response(
        response: 200,
        description: "Identité et préférences de l'utilisateur connecté",
        content: new OA\JsonContent(ref: new Model(type: CurrentUserDTO::class)),
    )]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        /** @var CurrentUserDTO $currentUser */
        $currentUser = $this->queryBus->ask(new GetCurrentUserQuery($user->getId()));

        return new JsonResponse($currentUser);
    }
}
