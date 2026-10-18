<?php

namespace Repas\Drive\Infrastructure\Http\Api\GetDriveSettings;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\GetDriveSettingsQuery;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sans `#[RequiresDrive]` : l'application lit cette route pour savoir si la fonction est allumée (`enabled`), elle répond
 * donc `200` même quand l'interrupteur est coupé.
 */
final class GetDriveSettingsApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/settings', name: 'api_get_drive_settings', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Réglages du drive de l'utilisateur connecté (magasin) et état de l'interrupteur de la fonction")]
    #[OA\Tag(name: 'Drive')]
    #[OA\Response(
        response: 200,
        description: "L'interrupteur et le magasin de l'utilisateur (`store: null` tant qu'il n'en a pas enregistré) ; `200` aussi quand la fonction est coupée (`enabled: false`)",
        content: new OA\JsonContent(ref: new Model(type: DriveSettingsDTO::class)),
    )]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var DriveSettingsDTO $settings */
        $settings = $this->queryBus->ask(new GetDriveSettingsQuery($user->getId()));

        return new JsonResponse($settings);
    }
}
