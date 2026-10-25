<?php

namespace Repas\Voice\Infrastructure\Http;


use Psr\Clock\ClockInterface;
use Repas\Shared\Domain\Interface\AuthenticatedUser;
use Repas\Voice\Domain\Exception\VoiceException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Plafonne les routes de la commande vocale marquées `#[VoiceRateLimited]` (VOX-202, VOX-204).
 *
 * - L'échange de jetons (`voice_token`, 30 par heure) est PUBLIC (firewall sans sécurité) : son seul garde-fou avec l'authentification du
 *   client est ce plafond, par adresse IP. L'émission du code (`voice_authorize`, 20 par heure) est celle d'un utilisateur connecté :
 *   le compteur est celui de l'utilisateur du jeton, pas de l'adresse IP (repli sur l'adresse sans utilisateur, plutôt que sur aucun
 *   plafond). Le webhook d'Alexa (`voice_webhook`, 60 par minute) est PUBLIC aussi, plafonné par adresse IP AVANT la vérification de la
 *   signature (chaque requête à l'adresse de chaîne inventée coûte un téléchargement de certificat) ; son plafond par liaison est celui de
 *   `VoiceLinkRateLimiter`, posé par le controller une fois le jeton résolu.
 * - « Par adresse IP » compte par `ClientAddressKey` : une adresse IPv6 est ramenée à son préfixe /64 (un abonné y choisit autant d'adresses
 *   qu'il veut), une adresse IPv4 mappée en IPv6 est l'adresse IPv4 qu'elle porte.
 * - Passe APRÈS `VoiceEnabledListener` (priorité plus basse) : interrupteur coupé, `404 VOICE_DISABLED` et aucun jeton consommé.
 *   Passe avant la résolution des arguments : une requête au corps invalide compte aussi.
 * - Au-delà du plafond : `429` + `Retry-After` en secondes, message `VOICE_RATE_LIMITED` (`ApiExceptionListener` met la réponse en
 *   forme). Les requêtes refusées ne sont pas comptées : attendre `Retry-After` suffit.
 * - Aucune requête SQL : l'état de la fenêtre glissante est dans le cache (`cache.rate_limiter`).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -10)]
final readonly class VoiceRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.voice_token')]
        private RateLimiterFactoryInterface $tokenLimiter,
        #[Autowire(service: 'limiter.voice_authorize')]
        private RateLimiterFactoryInterface $authorizeLimiter,
        #[Autowire(service: 'limiter.voice_webhook')]
        private RateLimiterFactoryInterface $webhookLimiter,
        private TokenStorageInterface $tokenStorage,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        $attributes = $event->getAttributes(VoiceRateLimited::class);
        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $attribute) {
            $limit = $this->factoryFor($attribute->limit)->create($this->keyOf($attribute->limit, $event->getRequest()))->consume();
            if ($limit->isAccepted()) {
                continue;
            }

            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());

            throw new TooManyRequestsHttpException($retryAfter, VoiceException::RATE_LIMITED, VoiceException::rateLimited(), headers: $attribute->limit->refusalHeaders());
        }
    }

    private function keyOf(VoiceRateLimit $limit, Request $request): string
    {
        $user = $limit->isPerUser() ? $this->tokenStorage->getToken()?->getUser() : null;

        return $user instanceof AuthenticatedUser
            ? 'user-' . $user->getId()
            : 'ip-' . ClientAddressKey::of($request->getClientIp());
    }

    private function factoryFor(VoiceRateLimit $limit): RateLimiterFactoryInterface
    {
        return match ($limit) {
            VoiceRateLimit::TOKEN => $this->tokenLimiter,
            VoiceRateLimit::AUTHORIZE => $this->authorizeLimiter,
            VoiceRateLimit::WEBHOOK => $this->webhookLimiter,
        };
    }
}
