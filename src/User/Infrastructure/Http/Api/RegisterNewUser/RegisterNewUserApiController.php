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

    // Seule route d'écriture publique de l'API qui crée un compte : `PUBLIC_ACCESS` dans `access_control` (config/packages/security.yaml)
    #[Route(path: '/api/v1/users', name: 'api_register_new_user', methods: ['POST'])]
    #[OA\Post(
        summary: "Inscrit un utilisateur avec sa seule adresse (route publique)",
        description: "Un email part vers l'adresse : le lien de bienvenue qui fait choisir le mot de passe (adresse nouvelle ou compte encore en attente), ou le rappel qu'un compte existe déjà (compte actif). Un compte désactivé ne reçoit rien. La réponse est la même dans tous les cas : elle ne dit rien d'un compte existant. Le compte ne peut se connecter qu'après le choix du mot de passe (POST /api/v1/users/password-reset/confirm avec le secret du lien).",
        security: [],
    )]
    #[OA\Tag(name: 'Users')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RegisterNewUserPayload::class)))]
    #[OA\Response(response: 204, description: "Demande prise en compte, quel que soit l'état de l'adresse")]
    #[OA\Response(response: 429, description: "Trop d'inscriptions depuis cette adresse IP, trop d'emails demandés pour cette même adresse email (6 par heure, que le compte existe ou non), ou trop d'emails demandés au total dans l'heure (100, toutes adresses confondues)")]
    #[OA\Response(response: 422, description: 'Corps de la requête invalide')]
    public function __invoke(#[MapRequestPayload] RegisterNewUserPayload $payload): Response
    {
        $this->commandBus->dispatch(new RegisterNewUserCommand(
            userId: UuidGenerator::new(),
            email: $payload->email,
            defaultServing: $payload->defaultServing,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
