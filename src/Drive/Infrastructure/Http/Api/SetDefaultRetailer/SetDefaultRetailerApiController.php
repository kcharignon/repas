<?php

namespace Repas\Drive\Infrastructure\Http\Api\SetDefaultRetailer;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SetDefaultRetailer\SetDefaultRetailerCommand;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class SetDefaultRetailerApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/default-retailer', name: 'api_set_default_retailer', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: "Fixe (ou efface avec `null`) le drive habituel de l'utilisateur connecté : avec plusieurs drives configurés, Repas l'utilise sans rien demander")]
    #[OA\Tag(name: 'Drive')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SetDefaultRetailerPayload::class)))]
    #[OA\Response(response: 204, description: 'Drive habituel enregistré (ou effacé)')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`), ou enseigne éteinte (message `DRIVE_RETAILER_DISABLED`, `DRIVE_RETAILERS`)')]
    #[OA\Response(response: 422, description: "Corps invalide (clé `retailer` absente, enseigne inconnue), ou l'utilisateur n'a pas de magasin chez cette enseigne (message `DRIVE_RETAILER_NOT_CONFIGURED`)")]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload(serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true])] SetDefaultRetailerPayload $payload): Response
    {
        $this->commandBus->dispatch(new SetDefaultRetailerCommand($user->getId(), $payload->retailer));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
