<?php

namespace Repas\Voice\Infrastructure\Security;


use DateTimeImmutable;
use OpenSSLAsymmetricKey;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Repas\Voice\Domain\Exception\VoiceException;
use Repas\Voice\Domain\Interface\AlexaRequestVerifier;
use Repas\Voice\Domain\Interface\CertificateChainFetcher;
use Repas\Voice\Domain\Model\AlexaRequestBody;
use Repas\Voice\Domain\Model\CertificateChainUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vérifie une requête d'Alexa avec OpenSSL (ADR 0029 décision 7, ticket VOX-203, page « Host a custom skill as a web service »).
 * Tout est refusé (`VoiceException::invalidRequest()`, une seule réponse) sauf si TOUT ceci est vrai, dans cet ordre, les contrôles
 * qui ne coûtent rien d'abord, le réseau en dernier :
 *
 * 1. `ALEXA_SKILL_ID` n'est pas vide (vide : aucune requête n'est acceptable) ;
 * 2. le corps fait 1 Mio au plus, la signature est du base64 strict (1 Kio au plus) ;
 * 3. l'adresse de la chaîne est celle d'Amazon (`CertificateChainUrl`) ;
 * 4. le corps est un JSON d'Alexa (`AlexaRequestBody`) dont l'horodatage est à 150 s de l'horloge et dont l'`applicationId` est notre skill ;
 * 5. la chaîne de certificats est obtenue (`CertificateChainFetcher`, en cache) : de 1 à 8 certificats, TOUS dans leur période de
 *    validité d'après l'horloge injectée ; le premier (celui qui signe) n'est pas une autorité, nomme `echo-api.amazon.com` parmi ses
 *    noms alternatifs (DNS, comparaison exacte, aucun joker) et porte une clé RSA d'au moins 2048 bits ;
 * 6. OpenSSL (`openssl_x509_checkpurpose`, usage « serveur TLS ») remonte ce certificat à une racine de confiance, les autres
 *    certificats de la chaîne n'étant que des intermédiaires (jamais des ancres : une racine fournie par la requête ne vaut rien) ;
 * 7. la signature `Signature-256` est celle, RSA PKCS#1 v1.5 SHA-256, du corps BRUT par la clé de ce certificat.
 *
 * Les racines de confiance sont celles du système, sauf si l'on en injecte (fichiers ou dossiers PEM : c'est ainsi que les tests posent
 * leur propre autorité). OpenSSL juge la période de validité d'après l'horloge de la machine, qu'on ne peut pas lui dicter : la
 * vérification de période faite ici, avec l'horloge injectée, double la sienne, elle ne la remplace pas.
 *
 * Un refus écrit UN message `warning` avec sa catégorie (`configuration`, `body`, `signature`, `chain_url`, `request`, `timestamp`,
 * `application`, `chain`, `certificate`), jamais le corps (il contient la voix transcrite), la signature ni l'adresse reçues.
 */
final readonly class OpenSslAlexaRequestVerifier implements AlexaRequestVerifier
{
    public const string SIGNING_NAME = 'echo-api.amazon.com';
    public const int MAX_BODY_BYTES = 1048576;
    public const int MAX_SIGNATURE_BYTES = 1024;
    public const int MINIMUM_RSA_BITS = 2048;
    public const int MAX_CERTIFICATES = HttpCertificateChainFetcher::MAX_CERTIFICATES;

    public const string CONFIGURATION = 'configuration';
    public const string BODY = 'body';
    public const string SIGNATURE = 'signature';
    public const string CHAIN_URL = 'chain_url';
    public const string REQUEST = 'request';
    public const string TIMESTAMP = 'timestamp';
    public const string APPLICATION = 'application';
    public const string CHAIN = 'chain';
    public const string CERTIFICATE = 'certificate';

    private string $skillId;

    /**
     * @param list<string> $trustedRoots fichiers ou dossiers PEM des racines de confiance ; vide : le magasin du système
     */
    public function __construct(
        private CertificateChainFetcher $chainFetcher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%env(string:default::ALEXA_SKILL_ID)%')]
        string $skillId,
        private array $trustedRoots = [],
    ) {
        $this->skillId = trim($skillId);
    }

    public function verify(string $rawBody, string $certChainUrl, string $signature256): void
    {
        if ($this->skillId === '') {
            // Skill non configuré : aucune requête n'est acceptable (les routes répondent déjà 404 tant que la fonction est coupée).
            $this->refuse(self::CONFIGURATION);
        }

        if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
            $this->refuse(self::BODY);
        }

        $signature = $this->decodeSignature($signature256);

        $url = CertificateChainUrl::tryFrom($certChainUrl);
        if ($url === null) {
            $this->refuse(self::CHAIN_URL);
        }

        $body = AlexaRequestBody::tryFrom($rawBody);
        if ($body === null) {
            $this->refuse(self::REQUEST);
        }

        $now = $this->clock->now();
        if (!$body->isFreshAt($now)) {
            $this->refuse(self::TIMESTAMP);
        }
        if (!$body->isAddressedTo($this->skillId)) {
            $this->refuse(self::APPLICATION);
        }

        try {
            $chain = $this->chainFetcher->fetch($url);
        } catch (VoiceException $exception) {
            // Sans certificat, rien ne se prouve : échec fermé.
            $this->refuse(self::CHAIN, $exception->reason);
        }

        $certificates = PemCertificates::split($chain, self::MAX_CERTIFICATES);
        if ($certificates === null) {
            $this->refuse(self::CHAIN);
        }

        $signingCertificate = $certificates[0];
        $this->assertWithinValidityPeriod($certificates, $now);
        $this->assertEndEntityNamingEchoApi($signingCertificate);
        $publicKey = $this->strongRsaPublicKey($signingCertificate);
        $this->assertTrusted($signingCertificate, array_slice($certificates, 1));
        $this->assertSignedBy($publicKey, $rawBody, $signature);
    }

    private function decodeSignature(string $signature256): string
    {
        if ($signature256 === '' || strlen($signature256) > self::MAX_SIGNATURE_BYTES) {
            $this->refuse(self::SIGNATURE);
        }

        $signature = base64_decode($signature256, true);
        if ($signature === false || $signature === '') {
            $this->refuse(self::SIGNATURE);
        }

        return $signature;
    }

    /**
     * Chaque certificat de la chaîne est valable à l'instant de l'horloge : de `notBefore` (compris) à `notAfter` (exclu), comme
     * OpenSSL.
     *
     * @param list<string> $certificates
     */
    private function assertWithinValidityPeriod(array $certificates, DateTimeImmutable $now): void
    {
        $instant = $now->getTimestamp();
        foreach ($certificates as $certificate) {
            $details = $this->parse($certificate);
            $notBefore = $details['validFrom_time_t'] ?? null;
            $notAfter = $details['validTo_time_t'] ?? null;
            if (!is_int($notBefore) || !is_int($notAfter) || $instant < $notBefore || $instant >= $notAfter) {
                $this->refuse(self::CERTIFICATE, 'période de validité');
            }
        }
    }

    /**
     * Le certificat qui signe n'est pas une autorité (un CA ne signe pas les requêtes d'Alexa) et nomme `echo-api.amazon.com`.
     */
    private function assertEndEntityNamingEchoApi(string $signingCertificate): void
    {
        $extensions = $this->parse($signingCertificate)['extensions'] ?? [];
        $constraints = is_array($extensions) ? ($extensions['basicConstraints'] ?? '') : '';
        if (!is_string($constraints) || str_contains(strtoupper($constraints), 'CA:TRUE')) {
            $this->refuse(self::CERTIFICATE, 'autorité de certification');
        }

        $names = is_array($extensions) ? ($extensions['subjectAltName'] ?? null) : null;
        if (!is_string($names)) {
            $this->refuse(self::CERTIFICATE, 'noms alternatifs absents');
        }

        foreach (explode(',', $names) as $name) {
            $name = trim($name);
            if (str_starts_with($name, 'DNS:') && strcasecmp(substr($name, 4), self::SIGNING_NAME) === 0) {
                return;
            }
        }

        $this->refuse(self::CERTIFICATE, 'nom alternatif absent');
    }

    /**
     * @param list<string> $intermediates
     */
    private function assertTrusted(string $signingCertificate, array $intermediates): void
    {
        $untrustedFile = null;
        if ($intermediates !== []) {
            // `openssl_x509_checkpurpose` ne lit les intermédiaires que dans un fichier.
            $untrustedFile = tempnam(sys_get_temp_dir(), 'alexa-chain-');
            if ($untrustedFile === false) {
                $this->refuse(self::CHAIN, 'fichier temporaire impossible');
            }
        }

        try {
            if ($untrustedFile !== null && file_put_contents($untrustedFile, implode('', $intermediates)) === false) {
                $this->refuse(self::CHAIN, 'fichier temporaire impossible');
            }

            $trusted = openssl_x509_checkpurpose($signingCertificate, X509_PURPOSE_SSL_SERVER, $this->trustedRoots, $untrustedFile);
        } finally {
            if ($untrustedFile !== null) {
                @unlink($untrustedFile);
            }
            PemCertificates::clearOpenSslErrors();
        }

        // `true` seulement : `false` (refus) comme `-1` (erreur d'OpenSSL) refusent.
        if ($trusted !== true) {
            $this->refuse(self::CERTIFICATE, 'racine de confiance');
        }
    }

    /**
     * La clé publique du certificat qui signe, si elle est RSA d'au moins 2048 bits (une clé plus faible se forge).
     */
    private function strongRsaPublicKey(string $signingCertificate): OpenSSLAsymmetricKey
    {
        $publicKey = openssl_pkey_get_public($signingCertificate);
        $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        PemCertificates::clearOpenSslErrors();
        if ($publicKey === false
            || !is_array($details)
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ($details['bits'] ?? 0) < self::MINIMUM_RSA_BITS
        ) {
            $this->refuse(self::CERTIFICATE, 'clé inacceptable');
        }

        return $publicKey;
    }

    private function assertSignedBy(OpenSSLAsymmetricKey $publicKey, string $rawBody, string $signature): void
    {
        // 1 seulement : 0 (signature fausse) comme -1 (erreur d'OpenSSL) refusent.
        $verified = openssl_verify($rawBody, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        PemCertificates::clearOpenSslErrors();
        if ($verified !== 1) {
            $this->refuse(self::SIGNATURE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $certificate): array
    {
        $details = openssl_x509_parse($certificate);
        PemCertificates::clearOpenSslErrors();
        if (!is_array($details)) {
            $this->refuse(self::CERTIFICATE, 'illisible');
        }

        return $details;
    }

    private function refuse(string $category, ?string $reason = null): never
    {
        $context = ['category' => $category];
        if ($reason !== null && $reason !== '') {
            $context['reason'] = $reason;
        }
        $this->logger->warning('Requête d\'Alexa refusée.', $context);

        throw VoiceException::invalidRequest();
    }
}
