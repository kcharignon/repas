<?php

namespace Repas\User\Infrastructure\Http\Api\DisableUser;


use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\User\Application\DisableUser\DisableUserCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DisableUserApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/users/{id}/disable', name: 'api_disable_user', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(summary: "Désactive un compte, en attente ou actif (administrateur) : plus de connexion, plus de jeton, plus de lien par email")]
    #[OA\Tag(name: 'Users')]
    #[OA\Response(response: 204, description: 'Compte désactivé')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Utilisateur inconnu')]
    #[OA\Response(response: 409, description: 'Le compte est déjà désactivé')]
    #[OA\Response(response: 422, description: "L'administrateur ne peut pas désactiver son propre compte (`CANNOT_MANAGE_OWN_ACCOUNT`)")]
    public function __invoke(string $id, #[CurrentUser] AuthenticatedUser $admin): Response
    {
        $this->commandBus->dispatch(new DisableUserCommand($id, $admin->getId()));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
