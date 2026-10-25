<?php

namespace Repas\Tests\Helper\Http;


use Repas\Shared\Infrastructure\Http\RequiresVoice;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes d'essai de l'environnement de test (config/routes.yaml) : une route soumise à l'interrupteur de la commande vocale, une route
 * libre, et une route soumise qui lit un corps validé (`VoiceProbePayload`), pour prouver le câblage réel de `#[RequiresVoice]` tant
 * qu'aucun vrai controller de `Voice` n'existe — en particulier que l'interrupteur coupé répond avant la validation du corps.
 */
#[AsController]
final class VoiceProbeController
{
    public const string REQUIRED_PATH = '/api/v1/probe/voice-required';
    public const string FREE_PATH = '/api/v1/probe/voice-free';
    public const string BODY_PATH = '/api/v1/probe/voice-body';

    #[Route(path: self::REQUIRED_PATH, name: 'probe_voice_required', methods: ['GET'])]
    #[RequiresVoice]
    public function required(): JsonResponse
    {
        return new JsonResponse(['probe' => 'voice-required']);
    }

    #[Route(path: self::FREE_PATH, name: 'probe_voice_free', methods: ['GET'])]
    public function free(): JsonResponse
    {
        return new JsonResponse(['probe' => 'voice-free']);
    }

    #[Route(path: self::BODY_PATH, name: 'probe_voice_body', methods: ['POST'])]
    #[RequiresVoice]
    public function body(#[MapRequestPayload] VoiceProbePayload $payload): JsonResponse
    {
        return new JsonResponse(['probe' => 'voice-body', 'phrase' => $payload->phrase]);
    }
}
