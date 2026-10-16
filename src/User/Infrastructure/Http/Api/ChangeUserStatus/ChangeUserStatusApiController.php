<?php

namespace Repas\User\Infrastructure\Http\Api\ChangeUserStatus;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\ChangeUserStatus\ChangeUserStatusCommand;
use Repas\User\Domain\Model\UserStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ChangeUserStatusApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/users/{id}/status', name: 'api_change_user_status', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Put(
        summary: "Fixe le statut d'un compte (administrateur), quel que soit l'ancien",
        description: "Un compte désactivé ne peut plus se connecter ni rien obtenir ; un compte désactivé ou en attente perd ses connexions ouvertes. Fixer le statut qu'a déjà le compte est sans effet. Seul le compte protégé ne peut pas être désactivé (ADR 0021).",
    )]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ChangeUserStatusPayload::class)))]
    #[OA\Response(response: 204, description: 'Statut modifié')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Response(response: 404, description: 'Utilisateur inconnu')]
    #[OA\Response(response: 422, description: 'Corps invalide, ou compte protégé qu\'on désactiverait (`PROTECTED_ACCOUNT`)')]
    public function __invoke(string $id, #[MapRequestPayload(validationFailedStatusCode: 422)] ChangeUserStatusPayload $payload): Response
    {
        $this->commandBus->dispatch(new ChangeUserStatusCommand($id, UserStatus::from($payload->status)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
