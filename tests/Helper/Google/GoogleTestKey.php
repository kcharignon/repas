<?php

namespace Repas\Tests\Helper\Google;


use RuntimeException;

/**
 * Une clé de signature « à la Google » née dans le test (ADR 0024, docs/auth/roadmap.md : aucune clé ni aucun jeton réel de
 * Google dans le dépôt, aucun appel réseau) : paire RSA générée par `openssl_pkey_new`, certificat X.509 auto-signé (la
 * forme que publie `https://www.googleapis.com/oauth2/v1/certs`) et signature de jetons compacts écrits à la main, pour
 * pouvoir fabriquer aussi les jetons que `lcobucci/jwt` refuserait de construire (`alg: none`, revendications mal typées…).
 */
final readonly class GoogleTestKey
{
    private function __construct(
        public string $kid,
        public string $privateKeyPem,
        public string $publicKeyPem,
        public string $certificatePem,
    ) {
    }

    public static function generate(string $kid, int $bits = 2048, int $type = OPENSSL_KEYTYPE_RSA): self
    {
        $options = $type === OPENSSL_KEYTYPE_EC
            ? ['private_key_type' => $type, 'curve_name' => 'prime256v1']
            : ['private_key_type' => $type, 'private_key_bits' => $bits];
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('Génération de la clé de test impossible.');
        }

        $csr = openssl_csr_new(['commonName' => 'repas-test'], $key, ['digest_alg' => 'sha256']);
        $certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $details = openssl_pkey_get_details($key);
        if ($certificate === false
            || $details === false
            || !openssl_pkey_export($key, $privateKeyPem)
            || !openssl_x509_export($certificate, $certificatePem)
        ) {
            throw new RuntimeException('Certificat de test impossible.');
        }

        return new self($kid, $privateKeyPem, $details['key'], $certificatePem);
    }

    /**
     * Corps de la réponse de `oauth2/v1/certs` : `{kid: certificat PEM}`.
     */
    public static function certsBody(self ...$keys): string
    {
        $body = [];
        foreach ($keys as $key) {
            $body[$key->kid] = $key->certificatePem;
        }

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Jeton signé RS256 par cette clé. En-tête par défaut `{alg: RS256, kid, typ: JWT}` ; une entrée de `$headers` le
     * complète ou le remplace, une entrée à `null` le retire. La signature est toujours RSA-SHA256, quoi que dise `alg`.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headers
     */
    public function idToken(array $claims, array $headers = []): string
    {
        $signingInput = self::signingInput($this->headers($headers), $claims);
        if (!openssl_sign($signingInput, $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature de test impossible.');
        }

        return $signingInput . '.' . self::base64Url($signature);
    }

    /**
     * Jeton forgé signé en HMAC-SHA256 avec `$secret` (par exemple la clé publique de Google, l'attaque classique de
     * confusion d'algorithme).
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $headers
     */
    public function hmacToken(string $secret, array $claims, array $headers = []): string
    {
        $signingInput = self::signingInput($this->headers(array_merge(['alg' => 'HS256'], $headers)), $claims);

        return $signingInput . '.' . self::base64Url(hash_hmac('sha256', $signingInput, $secret, true));
    }

    /**
     * Jeton non signé (`alg: none`) : `$signature` vide comme le veut la norme, ou n'importe quoi.
     *
     * @param array<string, mixed> $claims
     */
    public function unsignedToken(array $claims, string $signature = ''): string
    {
        return self::signingInput($this->headers(['alg' => 'none']), $claims) . '.' . $signature;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function headers(array $headers): array
    {
        $merged = array_merge(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'], $headers);

        return array_filter($merged, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $claims
     */
    private static function signingInput(array $headers, array $claims): string
    {
        return self::base64Url(json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            . '.' . self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
