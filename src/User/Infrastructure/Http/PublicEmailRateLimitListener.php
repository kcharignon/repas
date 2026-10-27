<?php

namespace Repas\User\Infrastructure\Http;


use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafonds des routes publiques qui font partir un email : l'inscription (email de confirmation), le renvoi du lien de confirmation et
 * le mot de passe oublié. Deux compteurs, consultés dans cet ordre :
 *
 * 1. PAR DESTINATAIRE (limiteur `public_email_recipient`, 6 par heure, config/packages/rate_limiter.yaml) : l'adresse saisie, mise sous la
 *    forme qu'utilise la recherche du compte (`User::normalizeEmail`) et sans l'étiquette `+x` de sa partie locale (`RecipientKey`, pour la
 *    clé du plafond seulement), partage un seul compteur entre les trois routes. Une même adresse ne reçoit donc pas de quoi occuper le
 *    compteur commun. La clé est une empreinte (HMAC avec la clé de l'application) : l'adresse n'est pas dans le cache. C'est l'adresse
 *    SAISIE qui compte, jamais l'état du compte : la réponse est la même que le compte existe ou non.
 * 2. COMMUN, toutes adresses confondues (limiteur `public_email`, 100 par heure) : le fournisseur d'envoi a un quota par jour, que tous ces
 *    envois se partagent ; sans ce plafond, ceux des uns le consomment pour les autres.
 *
 * - Compte chaque requête dont le corps est valide, qu'un email parte ou non (adresse inconnue, déjà inscrite, compte désactivé) : la
 *   réponse ne dépend donc jamais de l'existence du compte. Les confirmations par lien (`…/confirm`) n'envoient rien et ne comptent pas.
 * - Passe APRÈS les plafonds par adresse IP (`kernel.controller` précède `kernel.controller_arguments`) : une requête déjà refusée par
 *   le sien ne consomme aucun de ces compteurs ; et après la validation du corps (priorité plus basse que `RequestPayloadValueResolver`,
 *   -10100) : un corps invalide reçoit son `422` et ne coûte rien à personne. Le compteur par destinataire passe avant le commun : une
 *   adresse déjà refusée ne consomme pas le commun.
 * - Au-delà d'un plafond : 429 + `Retry-After`, le même message pour les deux. Les requêtes refusées par un compteur ne le consomment pas.
 * - Aucune requête SQL : l'état des fenêtres est dans le cache.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: -10200)]
final readonly class PublicEmailRateLimitListener
{
    private const array ROUTES = ['api_register_new_user', 'api_send_email_verification', 'api_send_password_reset'];

    /** Un seul compteur commun pour tout le monde. */
    private const string KEY = 'all';

    public function __construct(
        #[Autowire(service: 'limiter.public_email')]
        private RateLimiterFactoryInterface $publicEmailLimiter,
        #[Autowire(service: 'limiter.public_email_recipient')]
        private RateLimiterFactoryInterface $recipientLimiter,
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private string $key,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $recipient = $this->recipientOf($event);
        if ($recipient !== null) {
            $this->assertAccepted($this->recipientLimiter->create(hash_hmac('sha256', $recipient, $this->key))->consume());
        }

        $this->assertAccepted($this->publicEmailLimiter->create(self::KEY)->consume());
    }

    private function assertAccepted(RateLimit $limit): void
    {
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many requests, please try again later.');
        }
    }

    /**
     * La clé de l'adresse saisie (`RecipientKey`) ; `null` si le corps n'en porte pas (jamais pour les trois routes).
     */
    private function recipientOf(ControllerArgumentsEvent $event): ?string
    {
        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof EmailRecipientPayload) {
                return RecipientKey::of($argument->recipientEmail());
            }
        }

        return null;
    }
}
