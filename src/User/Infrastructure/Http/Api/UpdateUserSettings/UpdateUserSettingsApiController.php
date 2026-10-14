<?php

namespace Repas\User\Infrastructure\Http\Api\UpdateUserSettings;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\User\Application\UpdateUserSettings\UpdateUserSettingsCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UpdateUserSettingsApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/me/settings', name: 'api_update_user_settings', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: "Remplace les réglages de l'utilisateur connecté (nombre de personnes, mot de passe)")]
    #[OA\Tag(name: 'Current user')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UserSettingsPayload::class)))]
    #[OA\Response(response: 204, description: 'Modifié')]
    #[OA\Response(response: 422, description: 'Corps invalide, ou mot de passe actuel absent ou erroné avec un nouveau mot de passe')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload] UserSettingsPayload $payload): Response
    {
        $this->commandBus->dispatch(new UpdateUserSettingsCommand(
            userId: $user->getId(),
            newPassword: $payload->newPassword,
            defaultServing: $payload->defaultServing,
            actualPassword: $payload->actualPassword,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
