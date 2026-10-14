<?php

namespace Repas\User\Infrastructure\Http\Api\RegisterNewUser;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Application\RegisterNewUser\RegisterNewUserCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class RegisterNewUserApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    // Seule route d'écriture publique de l'API : `PUBLIC_ACCESS` dans `access_control` (config/packages/security.yaml)
    #[Route(path: '/api/v1/users', name: 'api_register_new_user', methods: ['POST'])]
    #[OA\Post(summary: "Inscrit un utilisateur (route publique) ; il se connecte ensuite par /api/login_check", security: [])]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RegisterNewUserPayload::class)))]
    #[OA\Response(response: 201, description: 'Créé', headers: [new OA\Header(header: 'Location', description: "URL du compte créé (lisible par lui-même une fois connecté)", schema: new OA\Schema(type: 'string'))])]
    #[OA\Response(response: 409, description: 'Cette adresse email est déjà utilisée')]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(#[MapRequestPayload] RegisterNewUserPayload $payload): Response
    {
        $userId = UuidGenerator::new();
        $this->commandBus->dispatch(new RegisterNewUserCommand(
            userId: $userId,
            email: $payload->email,
            passwordPlainText: $payload->password,
            defaultServing: $payload->defaultServing,
        ));

        return new Response(status: Response::HTTP_CREATED, headers: ['Location' => $this->generateUrl('api_get_user', ['id' => $userId])]);
    }
}
