<?php

namespace Repas\User\Infrastructure\Http\Api\Logout;


use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\RevokeRefreshToken\RevokeRefreshTokenCommand;
use Repas\User\Domain\Model\RefreshToken;
use Repas\User\Infrastructure\Security\RefreshTokenCookie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LogoutApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly RefreshTokenCookie $cookie,
    ) {
    }

    #[Route(path: '/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    #[OA\Post(
        summary: 'Se déconnecter : révoquer la connexion du cookie de rafraîchissement',
        description: "Supprime toute la famille du refresh token présenté et efface le cookie. Idempotent : sans cookie ou avec un cookie inconnu, la réponse est la même. Les JWT déjà émis restent valables jusqu'à leur expiration (1 h) : le client doit les oublier (ADR 0015).",
        security: [],
    )]
    #[OA\Tag(name: 'Auth')]
    #[OA\Response(
        response: 204,
        description: 'Connexion révoquée, cookie `repas_refresh` effacé',
        headers: [new OA\Header(header: 'Set-Cookie', description: 'Cookie vide expiré', schema: new OA\Schema(type: 'string'))],
    )]
    public function __invoke(Request $request): Response
    {
        $secret = $this->cookie->read($request);
        if ($secret !== null) {
            $this->commandBus->dispatch(new RevokeRefreshTokenCommand(RefreshToken::hash($secret)));
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($this->cookie->clear());
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
