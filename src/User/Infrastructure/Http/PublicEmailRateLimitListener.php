<?php

namespace Repas\User\Infrastructure\Http;


use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafond GLOBAL (toutes adresses confondues) des routes publiques qui font partir un email : l'inscription (email de confirmation),
 * le renvoi du lien de confirmation et le mot de passe oublié (limiteur `public_email`, 100 par heure, config/packages/rate_limiter.yaml).
 * Le fournisseur d'envoi a un quota par jour, que tous ces envois se partagent : sans ce plafond, ceux des uns le consomment pour les autres.
 *
 * - Compte chaque requête dont le corps est valide, qu'un email parte ou non (adresse inconnue, déjà inscrite, compte désactivé) : la
 *   réponse ne dépend donc jamais de l'existence du compte. Les confirmations par lien (`…/confirm`) n'envoient rien et ne comptent pas.
 * - Passe APRÈS les plafonds par adresse (`kernel.controller` précède `kernel.controller_arguments`) : une adresse déjà refusée par le
 *   sien ne consomme pas ce compteur commun ; et après la validation du corps (priorité plus basse que `RequestPayloadValueResolver`,
 *   -10100) : un corps invalide reçoit son `422` et ne coûte rien à personne.
 * - Au-delà du plafond : 429 + `Retry-After`, comme les plafonds par adresse. Les requêtes refusées ne sont pas comptées.
 * - Aucune requête SQL : l'état de la fenêtre est dans le cache.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: -10200)]
final readonly class PublicEmailRateLimitListener
{
    private const array ROUTES = ['api_register_new_user', 'api_send_email_verification', 'api_send_password_reset'];

    /** Un seul compteur pour tout le monde. */
    private const string KEY = 'all';

    public function __construct(
        #[Autowire(service: 'limiter.public_email')]
        private RateLimiterFactoryInterface $publicEmailLimiter,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $limit = $this->publicEmailLimiter->create(self::KEY)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many requests, please try again later.');
        }
    }
}
