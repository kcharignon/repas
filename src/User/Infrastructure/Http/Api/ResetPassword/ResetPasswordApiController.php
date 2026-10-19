<?php

namespace Repas\User\Infrastructure\Http\Api\ResetPassword;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\ResetPassword\ResetPasswordCommand;
use Repas\User\Domain\Model\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class ResetPasswordApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    // Route publique (`PUBLIC_ACCESS` dans `access_control`, config/packages/security.yaml) : celui qui a oublié son mot de passe ne peut pas se connecter
    #[Route(path: '/api/v1/users/password-reset/confirm', name: 'api_reset_password', methods: ['POST'])]
    #[OA\Post(
        summary: "Fixe un nouveau mot de passe grâce au lien reçu par email (route publique)",
        description: "Le lien est à usage unique et valable 30 minutes. Le nouveau mot de passe remplace l'ancien, quel qu'il soit, et toutes les connexions ouvertes du compte sont fermées : il faut se reconnecter par /api/login_check. Un compte en attente dont l'adresse n'était pas confirmée est confirmé et activé, car le lien prouve la possession de l'adresse. Un compte désactivé reste inchangé et reçoit la même réponse qu'un lien invalide (ADR 0026).",
        security: [],
    )]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ResetPasswordPayload::class)))]
    #[OA\Response(response: 204, description: 'Mot de passe changé, connexions fermées')]
    #[OA\Response(response: 422, description: 'Corps invalide (mot de passe de moins de 8 caractères), ou lien inconnu, déjà utilisé, expiré ou de compte désactivé (message `INVALID_PASSWORD_RESET_TOKEN`)')]
    #[OA\Response(response: 429, description: 'Trop de demandes depuis cette adresse IP')]
    public function __invoke(#[MapRequestPayload] ResetPasswordPayload $payload): Response
    {
        $this->commandBus->dispatch(new ResetPasswordCommand(
            tokenHash: PasswordResetToken::hash($payload->token),
            newPassword: $payload->password,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
