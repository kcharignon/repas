<?php

namespace Repas\User\Infrastructure\Http\Api\SendEmailVerification;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\User\Application\SendEmailVerification\SendEmailVerificationCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class SendEmailVerificationApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    // Route publique (`PUBLIC_ACCESS` dans `access_control`, config/packages/security.yaml) : l'utilisateur en attente ne peut pas se connecter
    #[Route(path: '/api/v1/users/email-verification/send', name: 'api_send_email_verification', methods: ['POST'])]
    #[OA\Post(
        summary: "Renvoie le lien de confirmation d'adresse email d'un compte en attente (route publique)",
        description: "Répond 204 que l'adresse ait un compte en attente ou non (compte inconnu, déjà confirmé, désactivé, ou lien envoyé il y a moins d'une minute) : la réponse ne révèle pas quelles adresses sont inscrites (ADR 0020).",
        security: [],
    )]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SendEmailVerificationPayload::class)))]
    #[OA\Response(response: 204, description: 'Demande prise en compte')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    #[OA\Response(response: 429, description: "Trop de demandes depuis cette adresse IP, ou trop d'emails demandés au total dans l'heure (100, toutes adresses confondues)")]
    public function __invoke(#[MapRequestPayload] SendEmailVerificationPayload $payload): Response
    {
        $this->commandBus->dispatch(new SendEmailVerificationCommand(email: $payload->email));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
