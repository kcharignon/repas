<?php

namespace Repas\Tests\Helper\Http;


use Repas\Drive\Infrastructure\Http\DriveRateLimit;
use Repas\Drive\Infrastructure\Http\RateLimitedPerUser;
use Repas\Drive\Infrastructure\Http\RequiresDrive;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes d'essai de l'environnement de test (config/routes.yaml) : une route soumise à l'interrupteur du drive et une
 * route libre, pour prouver le câblage réel de `#[RequiresDrive]` tant qu'aucun vrai controller du drive n'existe ; une
 * route par plafond de débit par utilisateur (`#[RateLimitedPerUser]`, DRV-615), qui ne touchent ni la base ni un bus : on
 * les appelle des dizaines de fois pour vider un compteur, puis on essaie les vraies routes du drive.
 */
#[AsController]
final class DriveProbeController
{
    public const string REQUIRED_PATH = '/api/v1/probe/drive-required';
    public const string FREE_PATH = '/api/v1/probe/drive-free';
    public const string WRITE_LIMITED_PATH = '/api/v1/probe/drive-write-limited';
    public const string READ_LIMITED_PATH = '/api/v1/probe/drive-read-limited';

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

    #[Route(path: self::WRITE_LIMITED_PATH, name: 'probe_drive_write_limited', methods: ['PUT'])]
    #[RequiresDrive]
    #[RateLimitedPerUser(DriveRateLimit::WRITE)]
    public function writeLimited(): JsonResponse
    {
        return new JsonResponse(['probe' => 'drive-write-limited']);
    }

    #[Route(path: self::READ_LIMITED_PATH, name: 'probe_drive_read_limited', methods: ['GET'])]
    #[RequiresDrive]
    #[RateLimitedPerUser(DriveRateLimit::READ)]
    public function readLimited(): JsonResponse
    {
        return new JsonResponse(['probe' => 'drive-read-limited']);
    }
}
