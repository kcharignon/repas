<?php

namespace Repas\Voice\Infrastructure\Security;


use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\CertificateChainFetcher;
use Repas\Voice\Domain\Model\CertificateChainUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge la chaîne de certificats d'Amazon (ADR 0029 décision 7, ticket VOX-203) et la garde dans le cache de l'application :
 *
 * - le délai d'Alexa est serré (~8 s) : la chaîne est gardée 24 h par adresse, jamais au-delà de l'expiration du premier de ses
 *   certificats à échoir, et partagée entre tous les workers (une seule requête vers Amazon par adresse et par fenêtre) ;
 * - un échec (réseau, statut, taille, forme, chaîne déjà expirée) est retenu 15 s : une adresse qui échoue, ou qu'un inconnu
 *   invente, ne fait pas appeler Amazon à chaque requête ;
 * - le téléchargement est borné : délai de 2 s (4 s en tout), aucune redirection, vérification TLS stricte (pair et nom d'hôte),
 *   64 Kio au plus, statut `200` exigé ; seul l'hôte `s3.amazonaws.com` est joignable puisque seule une `CertificateChainUrl` entre ;
 * - seuls les certificats PEM de la réponse sont retenus (de 1 à 8) : tout autre contenu est jeté.
 *
 * Il ne juge PAS le certificat (validité, nom, racine) : c'est le rôle du vérificateur, à chaque requête, avec l'horloge. Les dates
 * viennent de l'horloge injectée. Le journal reçoit la raison technique d'un échec, jamais le contenu de la réponse.
 */
final readonly class HttpCertificateChainFetcher implements CertificateChainFetcher
{
    public const int FRESHNESS = 86400;
    public const int FAILURE_MEMORY = 15;
    public const float TIMEOUT = 2.0;
    public const float MAX_DURATION = 4.0;
    public const int MAX_BYTES = 65536;
    public const int MAX_CERTIFICATES = 8;
    public const string CACHE_KEY_PREFIX = 'voice.alexa_certificate_chain.';

    /** Marge de vie de l'entrée de cache au-delà de sa date de fin : la date qui fait foi est relue dans l'entrée, avec l'horloge. */
    private const int CACHE_MARGIN = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function fetch(CertificateChainUrl $url): string
    {
        $now = $this->clock->now()->getTimestamp();
        $item = $this->cache->getItem(self::CACHE_KEY_PREFIX . hash('sha256', $url->value()));

        $entry = $item->isHit() ? $item->get() : null;
        if (self::isEntry($entry) && $now < $entry['until']) {
            if ($entry['chain'] === null) {
                throw VoiceException::certificateChainUnavailable($entry['reason']);
            }

            return $entry['chain'];
        }

        try {
            [$chain, $until] = $this->download($url, $now);
        } catch (VoiceException $exception) {
            $this->remember($item, ['chain' => null, 'reason' => $exception->reason, 'until' => $now + self::FAILURE_MEMORY], $now);
            $this->logger->warning('Chaîne de certificats d\'Alexa indisponible.', ['reason' => $exception->reason]);

            throw $exception;
        }

        $this->remember($item, ['chain' => $chain, 'reason' => '', 'until' => $until], $now);

        return $chain;
    }

    /**
     * @return array{0: string, 1: int} la chaîne (certificats PEM) et la date (timestamp) jusqu'à laquelle on la garde
     *
     * @throws VoiceException
     */
    private function download(CertificateChainUrl $url, int $now): array
    {
        try {
            $response = $this->httpClient->request('GET', $url->value(), [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'max_redirects' => 0,
                'verify_peer' => true,
                'verify_host' => true,
                'on_progress' => static function (int $downloaded, int $total): void {
                    if ($downloaded > self::MAX_BYTES || $total > self::MAX_BYTES) {
                        throw new TransportException('réponse trop grosse');
                    }
                },
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw VoiceException::certificateChainUnavailable(sprintf('réponse HTTP %d', $status));
            }

            $content = $response->getContent();
        } catch (HttpClientException $exception) {
            throw VoiceException::certificateChainUnavailable($exception instanceof TransportException ? $exception->getMessage() : 'Amazon injoignable ou réponse illisible');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw VoiceException::certificateChainUnavailable('réponse trop grosse');
        }

        $certificates = PemCertificates::split($content, self::MAX_CERTIFICATES);
        if ($certificates === null) {
            throw VoiceException::certificateChainUnavailable('réponse sans chaîne de certificats lisible');
        }

        $firstExpiry = $this->firstExpiry($certificates);
        if ($firstExpiry === null) {
            throw VoiceException::certificateChainUnavailable('certificat illisible');
        }
        if ($firstExpiry <= $now) {
            throw VoiceException::certificateChainUnavailable('chaîne expirée');
        }

        return [implode('', $certificates), min($now + self::FRESHNESS, $firstExpiry)];
    }

    /**
     * @param list<string> $certificates
     */
    private function firstExpiry(array $certificates): ?int
    {
        $firstExpiry = null;
        foreach ($certificates as $certificate) {
            $details = openssl_x509_parse($certificate);
            PemCertificates::clearOpenSslErrors();
            $expiry = is_array($details) ? ($details['validTo_time_t'] ?? null) : null;
            if (!is_int($expiry)) {
                return null;
            }
            $firstExpiry = $firstExpiry === null ? $expiry : min($firstExpiry, $expiry);
        }

        return $firstExpiry;
    }

    /**
     * @param array{chain: ?string, reason: string, until: int} $entry
     */
    private function remember(CacheItemInterface $item, array $entry, int $now): void
    {
        $item->set($entry);
        $item->expiresAfter(max(1, $entry['until'] - $now) + self::CACHE_MARGIN);
        $this->cache->save($item);
    }

    /**
     * @phpstan-assert-if-true array{chain: ?string, reason: string, until: int} $entry
     */
    private static function isEntry(mixed $entry): bool
    {
        // Une entrée d'une autre forme (version précédente du code, cache abîmé) compte comme absente.
        return is_array($entry)
            && is_int($entry['until'] ?? null)
            && is_string($entry['reason'] ?? null)
            && array_key_exists('chain', $entry)
            && ($entry['chain'] === null || is_string($entry['chain']));
    }
}
