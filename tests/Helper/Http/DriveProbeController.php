<?php

namespace Repas\Tests\Helper\Http;


use Repas\Shared\Infrastructure\Http\RequiresDrive;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes d'essai de l'environnement de test (config/routes.yaml) : une route soumise à l'interrupteur du drive et une
 * route libre, pour prouver le câblage réel de `#[RequiresDrive]` tant qu'aucun vrai controller du drive n'existe.
 */
#[AsController]
final class DriveProbeController
{
    public const string REQUIRED_PATH = '/api/v1/probe/drive-required';
    public const string FREE_PATH = '/api/v1/probe/drive-free';

    #[Route(path: self::REQUIRED_PATH, name: 'probe_drive_required', methods: ['GET'])]
    #[RequiresDrive]
    public function required(): JsonResponse
    {
        return new JsonResponse(['probe' => 'drive-required']);
    }

    #[Route(path: self::FREE_PATH, name: 'probe_drive_free', methods: ['GET'])]
    public function free(): JsonResponse
    {
        return new JsonResponse(['probe' => 'drive-free']);
    }
}
