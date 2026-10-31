<?php

namespace Repas\Voice\Infrastructure\Http\Api\IssueAuthorizationCode;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Voice\Infrastructure\Http\RequiresVoice;
use Repas\Voice\Application\Command\IssueAuthorizationCode\IssueAuthorizationCodeCommand;
use Repas\Voice\Infrastructure\Http\VoiceRateLimit;
use Repas\Voice\Infrastructure\Http\VoiceRateLimited;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresVoice]
#[VoiceRateLimited(VoiceRateLimit::AUTHORIZE)]
final class IssueAuthorizationCodeApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/voice/alexa/authorize', name: 'api_issue_voice_authorization_code', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(
        summary: "Autorise Alexa à agir sur les courses de l'utilisateur connecté : émet le code d'autorisation de la liaison de compte (OAuth 2.0, code + PKCE)",
        description: "Appelée par la page `/alexa/lier` du front quand l'utilisateur, connecté, touche « Autoriser ». Le corps reprend les paramètres de la demande d'Alexa. La réponse donne l'adresse où envoyer l'utilisateur : l'adresse de retour (`redirectUri`, choisie dans la configuration) suivie de `code` et `state`. Le code est opaque, valable 10 minutes, à usage unique ; Repas n'en garde que le haché. Refus sans dire pourquoi (client inconnu, adresse de retour qui n'est pas exactement l'une des adresses autorisées, méthode autre que `S256`, défi ou `state` mal formé) : `422 VOICE_AUTHORIZATION_REFUSED`, et le front ne redirige alors nulle part. 20 émissions par heure et par utilisateur.",
    )]
    #[OA\Tag(name: 'Voice')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: IssueAuthorizationCodePayload::class)))]
    #[OA\Response(
        response: 200,
        description: "Où renvoyer l'utilisateur : `redirectUri?state=…&code=…`. `Cache-Control: no-store`",
        content: new OA\JsonContent(properties: [new OA\Property(property: 'redirectUrl', type: 'string', example: 'https://pitangui.amazon.com/api/skill/link/M2AAAAAAAAAAAA?state=xyz123&code=3Qa5mVq1xT0Zb8nWc2YpLkR7sHdJ9eUfGhAoIiNtBvM')]),
    )]
    #[OA\Response(response: 404, description: 'Commande vocale coupée (message `VOICE_DISABLED`)')]
    #[OA\Response(response: 422, description: "Corps invalide, ou demande d'autorisation refusée (message `VOICE_AUTHORIZATION_REFUSED`)")]
    #[OA\Response(response: 429, ref: '#/components/responses/VoiceAuthorizeRateLimited')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload] IssueAuthorizationCodePayload $payload): JsonResponse
    {
        $redirectUrl = $this->commandBus->dispatch(new IssueAuthorizationCodeCommand(
            userId: $user->getId(),
            clientId: $payload->clientId,
            redirectUri: $payload->redirectUri,
            state: $payload->state,
            codeChallenge: $payload->codeChallenge,
            codeChallengeMethod: $payload->codeChallengeMethod,
        ));

        // La réponse porte un secret (le code) : jamais dans un cache
        $response = new JsonResponse(['redirectUrl' => $redirectUrl]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
