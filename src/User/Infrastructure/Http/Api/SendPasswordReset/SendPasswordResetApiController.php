<?php

namespace Repas\User\Infrastructure\Http\Api\SendPasswordReset;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\SendPasswordReset\SendPasswordResetCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class SendPasswordResetApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    // Route publique (`PUBLIC_ACCESS` dans `access_control`, config/packages/security.yaml) : celui qui a oublié son mot de passe ne peut pas se connecter
    #[Route(path: '/api/v1/users/password-reset/send', name: 'api_send_password_reset', methods: ['POST'])]
    #[OA\Post(
        summary: "Envoie par email le lien pour choisir un nouveau mot de passe (route publique)",
        description: "Répond 204 que l'adresse ait un compte ou non (adresse inconnue, compte désactivé, ou lien envoyé il y a moins d'une minute) : la réponse ne révèle pas quelles adresses sont inscrites (ADR 0026). Le lien est valable 30 minutes et un nouveau lien remplace l'ancien.",
        security: [],
    )]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SendPasswordResetPayload::class)))]
    #[OA\Response(response: 204, description: 'Demande prise en compte')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    #[OA\Response(response: 429, description: "Trop de demandes depuis cette adresse IP, ou trop d'emails demandés au total dans l'heure (100, toutes adresses confondues)")]
    public function __invoke(#[MapRequestPayload] SendPasswordResetPayload $payload): Response
    {
        $this->commandBus->dispatch(new SendPasswordResetCommand(email: $payload->email));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
