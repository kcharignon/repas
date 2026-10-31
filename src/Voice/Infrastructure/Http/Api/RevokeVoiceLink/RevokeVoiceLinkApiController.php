<?php

namespace Repas\Voice\Infrastructure\Http\Api\RevokeVoiceLink;


use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Voice\Infrastructure\Http\RequiresVoice;
use Repas\Voice\Application\Command\RevokeVoiceLink\RevokeVoiceLinkCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresVoice]
final class RevokeVoiceLinkApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/voice/links/{id}', name: 'api_revoke_voice_link', requirements: ['id' => '[A-Za-z0-9-]{1,36}'], methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted('VOICE_LINK_OWNER', 'id')]
    #[OA\Delete(summary: "Retire la liaison de l'utilisateur avec Alexa : la liaison et tous ses jetons. Le jeton d'accès qu'Alexa tenait ne résout plus rien. Idempotent. Propriétaire seul, administrateur compris.")]
    #[OA\Tag(name: 'Voice')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifiant de la liaison (celui de `GET /voice/links`)', schema: new OA\Schema(type: 'string', pattern: '^[A-Za-z0-9-]{1,36}$'), example: '4f1c0a5e-7d1b-4c6a-8e2f-0b9d7c5a3e1f')]
    #[OA\Response(response: 204, description: 'Liaison retirée, jetons effacés')]
    #[OA\Response(response: 403, description: "La liaison n'est pas à l'utilisateur (aucun passe-droit administrateur)")]
    #[OA\Response(response: 404, description: 'Commande vocale coupée (message `VOICE_DISABLED`), ou liaison inconnue (message `VOICE_LINK_NOT_FOUND`)')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, string $id): Response
    {
        $this->commandBus->dispatch(new RevokeVoiceLinkCommand($user->getId(), $id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
