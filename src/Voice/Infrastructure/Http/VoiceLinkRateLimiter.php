<?php

namespace Repas\Voice\Infrastructure\Http;


use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le plafond du webhook d'Alexa PAR LIAISON (ADR 0029 décision 7, VOX-204) : 60 requêtes par minute (`voice_webhook_link`). Il se pose
 * une fois la requête authentique et le jeton résolu, donc dans le controller et non dans `VoiceRateLimitListener` (la liaison n'est pas
 * connue avant). Au-delà, le webhook répond par une phrase (« Un instant, réessaie dans une minute. »), jamais par une erreur : Alexa
 * dirait à l'utilisateur que le skill ne répond pas.
 *
 * Aucune requête SQL : l'état de la fenêtre glissante est dans le cache. La clé est l'identifiant de la liaison, jamais le jeton.
 */
final readonly class VoiceLinkRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.voice_webhook_link')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * Consomme une requête du plafond de la liaison ; faux quand il est atteint (la requête refusée n'est pas comptée).
     */
    public function allows(string $linkId): bool
    {
        return $this->limiter->create('link-' . $linkId)->consume()->isAccepted();
    }
}
