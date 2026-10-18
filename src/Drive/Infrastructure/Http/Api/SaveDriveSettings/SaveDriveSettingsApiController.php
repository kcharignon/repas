<?php

namespace Repas\Drive\Infrastructure\Http\Api\SaveDriveSettings;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\SaveDriveSettings\SaveDriveSettingsCommand;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
final class SaveDriveSettingsApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/settings', name: 'api_save_drive_settings', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: "Enregistre le magasin du drive de l'utilisateur connecté (remplace le précédent)")]
    #[OA\Tag(name: 'Drive')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: DriveSettingsPayload::class)))]
    #[OA\Response(response: 204, description: 'Magasin enregistré')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`)')]
    #[OA\Response(response: 422, description: "Corps invalide, ou magasin refusé (message `DRIVE_STORE_INVALID`) : adresse qui n'est pas celle d'un magasin de l'enseigne, identifiant différent de celui de l'adresse")]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload] DriveSettingsPayload $payload): Response
    {
        $this->commandBus->dispatch(new SaveDriveSettingsCommand(
            userId: $user->getId(),
            retailer: $payload->retailer,
            storeId: $payload->store->id,
            storeLabel: $payload->store->label,
            baseUrl: $payload->store->baseUrl,
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
