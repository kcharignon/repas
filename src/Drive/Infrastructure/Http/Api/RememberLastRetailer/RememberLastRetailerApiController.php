<?php

namespace Repas\Drive\Infrastructure\Http\Api\RememberLastRetailer;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Drive\Application\Command\RememberLastRetailer\RememberLastRetailerCommand;
use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Drive\Infrastructure\Http\RequiresDrive;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresDrive]
#[RateLimitedPerUser(DriveRateLimit::WRITE)]
final class RememberLastRetailerApiController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/api/v1/drive/last-retailer', name: 'api_remember_last_retailer', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Put(summary: "Retient l'enseigne que l'utilisateur connecté vient d'ouvrir : c'est la présélection quand Repas lui demande où envoyer sa liste (le front l'écrit à l'ouverture du drive, jamais une lecture)")]
    #[OA\Tag(name: 'Drive')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RememberLastRetailerPayload::class)))]
    #[OA\Response(response: 204, description: 'Dernière enseigne enregistrée')]
    #[OA\Response(response: 404, description: 'Fonction drive coupée (message `DRIVE_DISABLED`), ou enseigne éteinte (message `DRIVE_RETAILER_DISABLED`, `DRIVE_RETAILERS`)')]
    #[OA\Response(response: 422, description: "Corps invalide (enseigne absente ou inconnue), ou l'utilisateur n'a pas de magasin chez cette enseigne (message `DRIVE_RETAILER_NOT_CONFIGURED`)")]
    #[OA\Response(response: 429, ref: '#/components/responses/DriveWriteRateLimited')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user, #[MapRequestPayload] RememberLastRetailerPayload $payload): Response
    {
        $this->commandBus->dispatch(new RememberLastRetailerCommand($user->getId(), $payload->retailer));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
