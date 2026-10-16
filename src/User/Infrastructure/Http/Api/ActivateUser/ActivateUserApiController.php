<?php

namespace Repas\User\Infrastructure\Http\Api\ActivateUser;


use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\ActivateUser\ActivateUserCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ActivateUserApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/users/{id}/activate', name: 'api_activate_user', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(summary: "Active un compte en attente de confirmation d'adresse email (administrateur)")]
    #[OA\Tag(name: 'Users')]
    #[OA\Response(response: 204, description: 'Compte activé')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Utilisateur inconnu')]
    #[OA\Response(response: 409, description: "Le compte n'est pas en attente")]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new ActivateUserCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
