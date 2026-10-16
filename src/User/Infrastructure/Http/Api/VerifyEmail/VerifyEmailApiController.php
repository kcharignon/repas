<?php

namespace Repas\User\Infrastructure\Http\Api\VerifyEmail;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\VerifyEmail\VerifyEmailCommand;
use Repas\User\Domain\Model\EmailVerificationToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class VerifyEmailApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    // Route publique (`PUBLIC_ACCESS` dans `access_control`, config/packages/security.yaml) : l'utilisateur en attente ne peut pas se connecter
    #[Route(path: '/api/v1/users/email-verification/confirm', name: 'api_verify_email', methods: ['POST'])]
    #[OA\Post(
        summary: "Confirme l'adresse email d'un compte par le lien reçu : le compte devient actif (route publique)",
        description: "Le lien est à usage unique et valable 24 heures. Le compte peut ensuite se connecter par /api/login_check (ADR 0020).",
        security: [],
    )]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: VerifyEmailPayload::class)))]
    #[OA\Response(response: 204, description: 'Adresse confirmée, compte actif')]
    #[OA\Response(response: 422, description: 'Corps invalide, ou lien inconnu, déjà utilisé ou expiré (message `INVALID_EMAIL_VERIFICATION_TOKEN`)')]
    #[OA\Response(response: 429, description: 'Trop de demandes depuis cette adresse IP')]
    public function __invoke(#[MapRequestPayload] VerifyEmailPayload $payload): Response
    {
        $this->commandBus->dispatch(new VerifyEmailCommand(tokenHash: EmailVerificationToken::hash($payload->token)));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
