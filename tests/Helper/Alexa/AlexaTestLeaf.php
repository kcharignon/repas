<?php

namespace Repas\Tests\Helper\Alexa;


use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Un certificat de signature de test (voir `AlexaTestAuthority`) avec sa clé privée : il signe un corps comme le fait Amazon
 * (RSA PKCS#1 v1.5 SHA-256, en base64) et sait écrire « sa chaîne » comme le fait Amazon : lui d'abord, puis ses intermédiaires.
 */
final readonly class AlexaTestLeaf
{
    /**
     * @param list<string> $intermediates les certificats PEM au-dessus du sien, hors racine
     */
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public array $intermediates,
        private OpenSSLAsymmetricKey $key,
    ) {
    }

    /**
     * Le texte PEM de la chaîne : le certificat de signature, puis les intermédiaires (jamais la racine).
     */
    public function chainPem(): string
    {
        return $this->certificatePem . implode('', $this->intermediates);
    }

    /**
     * La signature `Signature-256` d'un corps : la signature RSA SHA-256 de ses octets, en base64.
     */
    public function sign(string $body): string
    {
        if (!openssl_sign($body, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature de test impossible.');
        }

        return base64_encode($signature);
    }
}
