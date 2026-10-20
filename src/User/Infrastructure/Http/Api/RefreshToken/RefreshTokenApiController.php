<?php

namespace Repas\User\Infrastructure\Http\Api\RefreshToken;


use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\RotateRefreshToken\RotateRefreshTokenCommand;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Domain\Service\UserProvider;
use Repas\User\Infrastructure\Security\RefreshTokenCookie;
use Repas\User\Infrastructure\Security\UserLoginTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RefreshTokenApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly UserProvider $userProvider,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenCookie $cookie,
        private readonly UserLoginTracker $loginTracker,
    ) {
    }

    #[Route(path: '/api/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    #[OA\Post(
        summary: 'Obtenir un nouveau JWT avec le cookie de rafraîchissement',
        description: "Public : le JWT est expiré, l'identité vient du seul cookie `repas_refresh` (HttpOnly, SameSite=Strict, `Path=/api/auth`), posé à la connexion. Le jeton est à usage unique : la réponse en pose un nouveau (rotation). Présenter un jeton déjà utilisé révoque toute la connexion (ADR 0015). Un échange réussi compte comme une connexion : il fait avancer la dernière connexion du compte que voit l'administrateur (`lastLoginAt` de `GET /api/v1/users`), au plus une fois toutes les 10 minutes ; un refus n'écrit rien.",
        security: [],
    )]
    #[OA\Tag(name: 'Auth')]
    #[OA\Response(
        response: 200,
        description: 'Nouveau JWT (durée de vie 3600 s) ; le cookie `repas_refresh` est remplacé',
        headers: [new OA\Header(header: 'Set-Cookie', description: 'Nouveau refresh token, `HttpOnly; Secure; SameSite=Strict; Path=/api/auth`', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(properties: [new OA\Property(property: 'token', type: 'string')]),
    )]
    #[OA\Response(
        response: 401,
        description: "Cookie absent, inconnu, expiré, déjà utilisé, compte désactivé ou mot de passe changé depuis : sans précision. Le cookie est effacé ; le client réessaie une fois (course entre onglets) avant de renvoyer à la connexion",
    )]
    #[OA\Response(response: 429, description: 'Trop de rafraîchissements depuis cette adresse (60 par 10 minutes)')]
    public function __invoke(Request $request): Response
    {
        $secret = $this->cookie->read($request);
        if ($secret === null) {
            return $this->refused();
        }

        $nextSecret = RefreshTokenCookie::generateSecret();
        $identifier = $this->commandBus->dispatch(new RotateRefreshTokenCommand(RefreshToken::hash($secret), RefreshToken::hash($nextSecret)));
        if (!is_string($identifier)) {
            return $this->refused();
        }

        $user = $this->userProvider->loadUserByIdentifier($identifier);
        $response = new JsonResponse(['token' => $this->jwtManager->create($user)]);
        $response->headers->setCookie($this->cookie->create($nextSecret));
        $response->headers->set('Cache-Control', 'no-store');

        // Renouveler la session est une authentification réussie : la dernière connexion avance (au plus toutes les 10 minutes,
        // jamais pour un refus, et un échec du suivi ne casse pas l'échange)
        $this->loginTracker->authenticated($user);

        return $response;
    }

    private function refused(): JsonResponse
    {
        $response = new JsonResponse(['error' => ['message' => 'Invalid refresh token.']], Response::HTTP_UNAUTHORIZED);
        $response->headers->setCookie($this->cookie->clear());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
