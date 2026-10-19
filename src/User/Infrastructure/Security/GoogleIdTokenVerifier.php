<?php

namespace Repas\User\Infrastructure\Security;


use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint;
use Lcobucci\JWT\Validation\Constraint\HasClaimWithValue;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\User\Domain\Exception\IdentityException;
use Repas\User\Domain\Interface\IdentityTokenVerifier;
use Repas\User\Domain\Model\IdentityProvider;
use Repas\User\Domain\Model\VerifiedIdentity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vérifie un jeton d'identité de Google avec `lcobucci/jwt` (ADR 0024 décisions 6 et 7, analyse § 3.1). Tout est refusé
 * (`IdentityException::invalidCredential()`, une seule réponse) sauf si TOUT ceci est vrai, dans cet ordre :
 *
 * 1. la fonction est allumée (`GOOGLE_CLIENT_ID` non vide) et le jeton est un JWS compact lisible de 8 Kio au plus ;
 * 2. en-tête : `alg` = `RS256` exactement (algorithme épinglé : le signataire n'est jamais choisi d'après le jeton, donc ni
 *    `none`, ni `HS256` signé avec la clé publique), `kid` non vide, aucun `crit` ; `jwk`, `jku`, `x5u`, `x5c` sont ignorés ;
 * 3. signature valide par la clé que Google publie sous ce `kid` (`GoogleSigningKeys`) ;
 * 4. `iss` = `accounts.google.com` ou `https://accounts.google.com` ;
 * 5. `aud` = l'ID client Web, et lui seul (avec Credential Manager aussi : `azp` n'est pas lu) ;
 * 6. `exp` ET `iat` présents (`LooseValidAt` accepte un jeton qui n'en a pas), non dépassé et pas émis dans le futur, à 30 s
 *    près ; `nbf` n'est pas exigé (`StrictValidAt` le ferait) mais respecté s'il est là ;
 * 7. `sub` et `email` : chaînes non vides de 255 caractères au plus ; `email_verified` est le booléen `true` (la chaîne
 *    `"true"` est refusée) ; `nonce` et `hd`, s'ils sont là, sont des chaînes non vides.
 *
 * Un refus écrit UN message `warning` avec sa catégorie (`signature`, `audience`, `issuer`, `expired`, `claims`, `keys`),
 * jamais le jeton, l'adresse ni le nonce. Les NOMS des revendications d'un jeton bien signé sont écrits au niveau `info` une
 * fois par jour au plus (lever à la recette les inconnues `nbf` et `azp`, analyse § 10).
 */
final readonly class GoogleIdTokenVerifier implements IdentityTokenVerifier
{
    public const array ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
    public const string ALGORITHM = 'RS256';
    public const string LEEWAY = 'PT30S';
    public const int MAX_TOKEN_LENGTH = 8192;
    public const int MAX_CLAIM_LENGTH = 255;
    public const int CLAIM_NAMES_LOG_INTERVAL = 86400;

    public const string SIGNATURE = 'signature';
    public const string AUDIENCE = 'audience';
    public const string ISSUER = 'issuer';
    public const string EXPIRED = 'expired';
    public const string CLAIMS = 'claims';
    public const string KEYS = 'keys';

    private const string CLAIM_NAMES_CACHE_KEY = 'user.google_claim_names_logged';

    public function __construct(
        private GoogleSignInSettings $settings,
        private GoogleSigningKeys $signingKeys,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function verify(string $idToken): VerifiedIdentity
    {
        $clientId = $this->settings->clientId();
        if ($clientId === '') {
            // Fonction coupée : aucune audience n'est acceptable (les routes répondent déjà 404, AUTH-004).
            $this->refuse(self::AUDIENCE);
        }

        $token = $this->parse($idToken);
        $this->assertSignedByGoogle($token);
        $this->logClaimNamesAtMostOncePerDay($token->claims());

        $this->assert($token, self::ISSUER, new IssuedBy(...self::ISSUERS));
        $this->assert($token, self::AUDIENCE, new PermittedFor($clientId));
        if ($token->claims()->get(RegisteredClaims::AUDIENCE) !== [$clientId]) {
            $this->refuse(self::AUDIENCE);
        }

        $claims = $token->claims();
        if (!$claims->has(RegisteredClaims::EXPIRATION_TIME) || !$claims->has(RegisteredClaims::ISSUED_AT)) {
            $this->refuse(self::CLAIMS);
        }
        $this->assert($token, self::EXPIRED, new LooseValidAt($this->clock, new DateInterval(self::LEEWAY)));

        $subject = $this->requiredString($claims, RegisteredClaims::SUBJECT);
        $email = $this->requiredString($claims, 'email');
        $this->assert($token, self::CLAIMS, new HasClaimWithValue('email_verified', true));

        return new VerifiedIdentity(
            IdentityProvider::GOOGLE,
            $subject,
            $email,
            true,
            $this->optionalString($claims, 'nonce'),
            $this->optionalString($claims, 'hd'),
        );
    }

    private function parse(string $idToken): UnencryptedToken
    {
        if ($idToken === '' || strlen($idToken) > self::MAX_TOKEN_LENGTH) {
            $this->refuse(self::SIGNATURE);
        }

        try {
            $token = new Parser(new JoseEncoder())->parse($idToken);
        } catch (JwtException) {
            $this->refuse(self::SIGNATURE);
        }

        if (!$token instanceof UnencryptedToken) {
            $this->refuse(self::SIGNATURE);
        }

        return $token;
    }

    private function assertSignedByGoogle(UnencryptedToken $token): void
    {
        $headers = $token->headers();
        $kid = $headers->get('kid');
        if ($headers->get('alg') !== self::ALGORITHM || !is_string($kid) || $kid === '' || $headers->has('crit')) {
            $this->refuse(self::SIGNATURE);
        }

        try {
            $publicKey = $this->signingKeys->publicKey($kid);
        } catch (GoogleSigningKeysUnavailable $exception) {
            $this->refuse(self::KEYS, $exception->getMessage());
        }

        if ($publicKey === null || $publicKey === '') {
            $this->refuse(self::SIGNATURE);
        }

        $this->assert($token, self::SIGNATURE, new SignedWith(new Sha256(), InMemory::plainText($publicKey)));
    }

    private function assert(UnencryptedToken $token, string $category, Constraint $constraint): void
    {
        try {
            $valid = new Validator()->validate($token, $constraint);
        } catch (JwtException) {
            // Clé illisible ou incompatible pour `SignedWith` : la signature n'est pas prouvée.
            $valid = false;
        }

        if (!$valid) {
            $this->refuse($category);
        }
    }

    private function requiredString(DataSet $claims, string $name): string
    {
        $value = $claims->get($name);
        if (!is_string($value) || trim($value) === '' || strlen($value) > self::MAX_CLAIM_LENGTH) {
            $this->refuse(self::CLAIMS);
        }

        return $value;
    }

    private function optionalString(DataSet $claims, string $name): ?string
    {
        if (!$claims->has($name)) {
            return null;
        }

        $value = $claims->get($name);
        if (!is_string($value) || trim($value) === '' || strlen($value) > self::MAX_CLAIM_LENGTH) {
            $this->refuse(self::CLAIMS);
        }

        return $value;
    }

    /**
     * Seulement les noms, triés : jamais une valeur (adresse, nonce, nom, photo).
     */
    private function logClaimNamesAtMostOncePerDay(DataSet $claims): void
    {
        $item = $this->cache->getItem(self::CLAIM_NAMES_CACHE_KEY);
        if ($item->isHit()) {
            return;
        }

        $names = array_map('strval', array_keys($claims->all()));
        sort($names);
        $this->logger->info('Revendications d\'un jeton Google bien signé (noms seulement).', ['claims' => implode(',', $names)]);

        $item->set($this->clock->now()->getTimestamp());
        $item->expiresAfter(self::CLAIM_NAMES_LOG_INTERVAL);
        $this->cache->save($item);
    }

    private function refuse(string $category, ?string $reason = null): never
    {
        $context = ['category' => $category];
        if ($reason !== null) {
            $context['reason'] = $reason;
        }
        $this->logger->warning('Jeton d\'identité Google refusé.', $context);

        throw IdentityException::invalidCredential();
    }
}
