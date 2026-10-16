<?php

namespace Repas\User\Infrastructure\Http\Api\DeleteUser;


use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DeleteUserApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/users/{id}', name: 'api_delete_user', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(summary: "Supprime un compte comme s'il n'avait jamais existé (administrateur) : ses listes de courses, recettes et ingrédients personnalisés sont effacés, les recettes qu'un autre en a copiées restent comme des originales. Irréversible.")]
    #[OA\Tag(name: 'Users')]
    #[OA\Response(response: 204, description: 'Compte et données supprimés')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Utilisateur inconnu')]
    #[OA\Response(response: 422, description: 'Le compte protégé ne se supprime pas (`PROTECTED_ACCOUNT`)')]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new DeleteUserCommand($id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
