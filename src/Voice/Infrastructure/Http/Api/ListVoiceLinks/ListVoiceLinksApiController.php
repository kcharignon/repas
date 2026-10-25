<?php

namespace Repas\Voice\Infrastructure\Http\Api\ListVoiceLinks;


use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Repas\Shared\Application\Interface\QueryBusInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Repas\Voice\Application\Query\ListVoiceLinks\ListVoiceLinksQuery;
use Repas\Voice\Application\Query\ListVoiceLinks\VoiceLinkDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[RequiresVoice]
final class ListVoiceLinksApiController extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(path: '/api/v1/voice/links', name: 'api_list_voice_links', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Get(summary: "Les liaisons de l'utilisateur connecté avec un assistant vocal (Alexa), pour l'écran « Moi » : la plus récente d'abord. Une liaison retirée n'y figure plus. Jamais de jeton.")]
    #[OA\Tag(name: 'Voice')]
    #[OA\Response(
        response: 200,
        description: "Les liaisons de l'utilisateur (liste vide tant qu'il n'a pas relié Alexa)",
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'member', type: 'array', items: new OA\Items(ref: new Model(type: VoiceLinkDTO::class))),
            new OA\Property(property: 'totalItems', type: 'integer', example: 1),
        ]),
    )]
    #[OA\Response(response: 404, description: 'Commande vocale coupée (message `VOICE_DISABLED`)')]
    public function __invoke(#[CurrentUser] AuthenticatedUser $user): JsonResponse
    {
        /** @var list<VoiceLinkDTO> $links */
        $links = $this->queryBus->ask(new ListVoiceLinksQuery($user->getId()));

        return new JsonResponse(['member' => $links, 'totalItems' => count($links)]);
    }
}
