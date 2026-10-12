<?php

namespace Repas\Shared\Infrastructure\Http\Api\Health;


use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthApiController extends AbstractController
{
    #[Route(path: '/api/v1/health', name: 'api_get_health', methods: ['GET'])]
    #[OA\Get(summary: "État de l'API")]
    #[OA\Tag(name: 'Health')]
    #[OA\Response(
        response: 200,
        description: "L'API répond",
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', example: 'ok'),
        ]),
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
