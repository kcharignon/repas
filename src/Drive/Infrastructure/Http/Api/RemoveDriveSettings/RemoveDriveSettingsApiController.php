<?php

namespace Repas\Drive\Infrastructure\Http\Api\RemoveDriveSettings;


use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\RemoveDriveSettings\RemoveDriveSettingsCommand;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class RemoveDriveSettingsApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/settings', name: 'api_remove_drive_settings', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Delete(summary: "Retire le drive d'une enseigne de l'utilisateur connecté : son magasin n'est plus enregistré, ses choix et les produits restent ; si cette enseigne était son drive habituel ou sa dernière enseigne, ils sont effacés")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Parameter(name: 'retailer', in: 'query', required: true, description: 'Enseigne du drive à retirer', schema: new OA\Schema(type: 'string', enum: Retailer::IDS), example: 'leclerc')]
    #[OA\Response(response: 204, description: 'Drive retiré')]
    #[OA\Response(response: 404, description: "Fonction drive coupée (message `DRIVE_DISABLED`), ou enseigne éteinte (message `DRIVE_RETAILER_DISABLED`, `DRIVE_RETAILERS`), ou pas de magasin enregistré chez cette enseigne (message `DRIVE_SETTINGS_NOT_FOUND`)")]
    #[OA\Response(response: 422, description: 'Paramètre `retailer` absent ou inconnu')]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapQueryString(validationFailedStatusCode: 422)] RemoveDriveSettingsRequestQuery $filter): Response
    {
        $this->commandBus->dispatch(new RemoveDriveSettingsCommand($user->getId(), $filter->retailer));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
