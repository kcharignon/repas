<?php

namespace Repas\User\Infrastructure\Security;


use Repas\Shared\Infrastructure\Http\ClientAddressKey;
use Repas\User\Domain\Model\User;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Plafonne les échecs de connexion (`login_throttling` du pare-feu `login`, config/packages/security.yaml ; ADR 0008). Remplace le
 * limiteur par défaut de Symfony, dont la clé était l'adresse IP brute ; les chiffres sont dans config/packages/rate_limiter.yaml.
 *
 * Trois compteurs, qui comptent les ÉCHECS seulement (le limiteur est interrogé sans rien consommer avant la vérification du mot de
 * passe, puis consomme un jeton à chaque échec) :
 * - par adresse (`login_address`) : combien d'adresses e-mail un même client essaie ;
 * - par adresse ET e-mail (`login_address_email`) : combien de mots de passe il essaie pour une même adresse e-mail ;
 * - par e-mail seul (`login_email`) : le total sur toutes les adresses, plus large.
 * Un e-mail inconnu est compté comme un e-mail connu : la clé est ce qui est saisi, rien n'est lu en base avant.
 *
 * - L'adresse est la clé commune des limiteurs (`ClientAddressKey` : préfixe /64 en IPv6, IPv4 mappée en IPv4).
 * - L'e-mail est mis sous la forme qu'utilise la recherche du compte (`User::normalizeEmail` : casse et espaces autour sans effet).
 * - Les clés sont des empreintes (HMAC avec le secret de l'application) : ni l'adresse e-mail ni l'adresse IP ne sont dans le cache.
 * - Une connexion réussie ne remet à zéro que le compteur « adresse + e-mail » : les deux autres bornent ce qu'un client ou un compte
 *   subit, et survivent à la réussite d'une autre connexion.
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.login_address')]
        private readonly RateLimiterFactoryInterface $addressLimiter,
        #[Autowire(service: 'limiter.login_address_email')]
        private readonly RateLimiterFactoryInterface $addressEmailLimiter,
        #[Autowire(service: 'limiter.login_email')]
        private readonly RateLimiterFactoryInterface $emailLimiter,
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    /**
     * Compte un échec, sauf si un des compteurs est déjà épuisé : une tentative refusée par le plafond ne consomme rien, ni pour
     * l'adresse, ni pour l'e-mail. Sans cela, des refus répétés d'une même adresse videraient le compteur de l'e-mail et le fermeraient
     * à toutes les autres.
     */
    public function consume(Request $request): RateLimit
    {
        $current = $this->peek($request);
        if (!$current->isAccepted() || $current->getRemainingTokens() === 0) {
            return $current;
        }

        return parent::consume($request);
    }

    /**
     * Ne remet à zéro que le compteur de ce couple adresse + e-mail.
     */
    public function reset(Request $request): void
    {
        $this->addressEmailLimiterOf($request)->reset();
    }

    /**
     * @return list<LimiterInterface>
     */
    protected function getLimiters(Request $request): array
    {
        return [
            $this->addressLimiter->create($this->hash('address|' . ClientAddressKey::of($request->getClientIp()))),
            $this->addressEmailLimiterOf($request),
            $this->emailLimiter->create($this->hash('email|' . $this->emailOf($request))),
        ];
    }

    private function addressEmailLimiterOf(Request $request): LimiterInterface
    {
        return $this->addressEmailLimiter->create($this->hash('address-email|' . ClientAddressKey::of($request->getClientIp()) . '|' . $this->emailOf($request)));
    }

    private function emailOf(Request $request): string
    {
        $identifier = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        return preg_match('//u', $identifier) === 1 ? User::normalizeEmail($identifier) : strtolower(trim($identifier));
    }

    private function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret);
    }
}
