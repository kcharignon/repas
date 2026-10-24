<?php

namespace Repas\User\Infrastructure\Security;


use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Clés publiques avec lesquelles Google signe ses jetons d'identité, lues à `CERTS_URL` (un objet JSON `{kid: certificat
 * X.509 PEM}`) et gardées dans le cache de l'application (ADR 0024 décision 7, analyse § 3.1). Règles :
 *
 * - fraîches pendant le `max-age` de `Cache-Control`, borné entre 10 min et 24 h (10 min sans en-tête) ;
 * - au plus UN téléchargement toutes les 5 minutes, quelle qu'en soit la raison : `kid` inconnu (Google a tourné ses clés),
 *   clés périmées, ou essai précédent manqué. Un flot de jetons au `kid` inventé ne fait donc pas appeler Google en boucle ;
 * - Google injoignable ou réponse malformée : les clés téléchargées il y a moins de 24 h servent encore ; sinon
 *   `GoogleSigningKeysUnavailable` et le jeton est refusé (échec fermé) ;
 * - délai d'attente de 3 s ; une réponse n'est retenue que si elle est entière et que chaque valeur est une clé publique RSA
 *   PEM d'au moins 2048 bits (jamais un chemin de fichier : `openssl` lirait le disque).
 *
 * Aucune clé de Google n'est dans le dépôt ni dans `config/` : le cache se vide au redéploiement, c'est voulu. Les dates
 * viennent de l'horloge injectée (testable sans attendre).
 */
final readonly class GoogleSigningKeys
{
    public const string CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';
    public const int MIN_FRESHNESS = 600;
    public const int MAX_FRESHNESS = 86400;
    public const int DOWNLOAD_INTERVAL = 300;
    public const int STALE_LIMIT = 86400;
    public const float TIMEOUT = 3.0;
    public const string CACHE_KEY = 'user.google_signing_keys';
    private const int MINIMUM_RSA_BITS = 2048;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * La clé publique PEM de `$kid`, ou `null` si Google ne la publie pas (ou plus).
     *
     * @throws GoogleSigningKeysUnavailable aucune clé utilisable (échec fermé)
     */
    public function publicKey(string $kid): ?string
    {
        $now = $this->clock->now()->getTimestamp();
        $state = $this->load();

        $isFresh = $state !== null && $now < $state['freshUntil'];
        $failure = null;
        if ((!$isFresh || !isset($state['keys'][$kid])) && $this->mayDownload($state, $now)) {
            [$state, $failure] = $this->download($state, $now);
        }

        if ($state === null || $state['fetchedAt'] === null || $now - $state['fetchedAt'] >= self::STALE_LIMIT) {
            throw GoogleSigningKeysUnavailable::because($failure ?? 'aucune clé récente, prochain essai dans 5 minutes au plus');
        }

        if ($failure !== null) {
            // La vérification peut réussir avec les clés d'avant : on le signale pour qu'une panne longue se voie avant 24 h.
            $this->logger->warning('Clés publiques de Google injoignables : les clés en cache servent encore.', ['reason' => $failure]);
        }

        return $state['keys'][$kid] ?? null;
    }

    /**
     * @param array{keys: array<string, string>, fetchedAt: ?int, freshUntil: int, attemptedAt: int}|null $state
     */
    private function mayDownload(?array $state, int $now): bool
    {
        return $state === null || $now - $state['attemptedAt'] >= self::DOWNLOAD_INTERVAL;
    }

    /**
     * Télécharge les clés et enregistre l'essai, réussi ou non (c'est lui qui espace les essais suivants).
     *
     * @param array{keys: array<string, string>, fetchedAt: ?int, freshUntil: int, attemptedAt: int}|null $state
     *
     * @return array{0: array{keys: array<string, string>, fetchedAt: ?int, freshUntil: int, attemptedAt: int}, 1: ?string}
     *         le nouvel état et la raison de l'échec (`null` si les clés ont été renouvelées)
     */
    private function download(?array $state, int $now): array
    {
        $failure = null;
        try {
            $response = $this->httpClient->request('GET', self::CERTS_URL, [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $failure = sprintf('réponse HTTP %d', $status);
            } else {
                $keys = $this->parseKeys($response->toArray());
                if ($keys === null) {
                    $failure = 'réponse malformée';
                } else {
                    $state = [
                        'keys' => $keys,
                        'fetchedAt' => $now,
                        'freshUntil' => $now + $this->freshness($response->getHeaders()['cache-control'] ?? []),
                        'attemptedAt' => $now,
                    ];
                }
            }
        } catch (HttpClientException) {
            $failure = 'Google injoignable ou réponse illisible';
        }

        if ($failure !== null) {
            $state = $state ?? ['keys' => [], 'fetchedAt' => null, 'freshUntil' => 0, 'attemptedAt' => $now];
            $state['attemptedAt'] = $now;
        }

        $this->save($state);

        return [$state, $failure];
    }

    /**
     * Toute la réponse ou rien : un objet non vide dont chaque valeur est une clé publique RSA PEM d'au moins 2048 bits.
     *
     * @param array<mixed> $data
     *
     * @return array<string, string>|null
     */
    private function parseKeys(array $data): ?array
    {
        if ($data === [] || array_is_list($data)) {
            return null;
        }

        $keys = [];
        foreach ($data as $kid => $pem) {
            $kid = (string) $kid;
            if ($kid === '' || !is_string($pem) || !$this->isRsaPublicKey($pem)) {
                return null;
            }
            $keys[$kid] = $pem;
        }

        return $keys;
    }

    private function isRsaPublicKey(string $pem): bool
    {
        if (!str_starts_with(ltrim($pem), '-----BEGIN ')) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        while (openssl_error_string() !== false) {
            // Vide la file d'erreurs d'OpenSSL : elle se mêlerait aux messages des vérifications suivantes.
        }

        return is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($details['bits'] ?? 0) >= self::MINIMUM_RSA_BITS;
    }

    /**
     * @param list<string> $cacheControl
     */
    private function freshness(array $cacheControl): int
    {
        if (preg_match('/(?:^|[,\s])max-age\s*=\s*"?(\d+)"?/i', implode(',', $cacheControl), $matches) !== 1) {
            return self::MIN_FRESHNESS;
        }

        return max(self::MIN_FRESHNESS, min(self::MAX_FRESHNESS, (int) $matches[1]));
    }

    /**
     * @return array{keys: array<string, string>, fetchedAt: ?int, freshUntil: int, attemptedAt: int}|null
     */
    private function load(): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$item->isHit()) {
            return null;
        }

        // Une entrée d'une autre forme (version précédente du code, cache abîmé) compte comme absente.
        $state = $item->get();
        if (!is_array($state)
            || !is_array($state['keys'] ?? null)
            || !array_key_exists('fetchedAt', $state)
            || !($state['fetchedAt'] === null || is_int($state['fetchedAt']))
            || !is_int($state['freshUntil'] ?? null)
            || !is_int($state['attemptedAt'] ?? null)
        ) {
            return null;
        }

        return $state;
    }

    /**
     * L'entrée du cache vit 24 h et 5 min : assez pour servir des clés périmées pendant la panne de Google, pas au-delà (la
     * limite réelle est relue dans l'état, avec l'horloge injectée).
     *
     * @param array{keys: array<string, string>, fetchedAt: ?int, freshUntil: int, attemptedAt: int} $state
     */
    private function save(array $state): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($state);
        $item->expiresAfter(self::STALE_LIMIT + self::DOWNLOAD_INTERVAL);
        $this->cache->save($item);
    }
}
