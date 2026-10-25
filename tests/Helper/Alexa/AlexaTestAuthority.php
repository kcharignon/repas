<?php

namespace Repas\Tests\Helper\Alexa;


use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Une autorité de certification née dans le test (ADR 0029, docs/voice/roadmap.md : aucun appel à Amazon, aucun certificat réel dans
 * le dépôt) : racine, intermédiaire et certificats « à la echo-api.amazon.com » produits par `openssl_csr_sign`, avec de VRAIES
 * clés RSA et de VRAIES signatures (comme `GoogleTestKey`). Les extensions X.509 (autorité ou non, noms alternatifs) passent par un
 * fichier de configuration OpenSSL écrit à la volée, car PHP n'offre pas d'autre moyen de les poser.
 *
 * - `root()` crée une racine auto-signée ; `intermediate()` un intermédiaire signé par cette autorité ;
 * - `issue()` émet un certificat feuille (par défaut : nom alternatif `echo-api.amazon.com`, 30 jours) ;
 * - `trustFile()` écrit le certificat de l'autorité dans un fichier temporaire, effacé à la fin du processus : c'est le « magasin de
 *   racines » qu'on injecte au vérificateur.
 */
final class AlexaTestAuthority
{
    /** @var list<string> fichiers temporaires à effacer à la fin du processus */
    private static array $temporaryFiles = [];

    private function __construct(
        public readonly string $certificatePem,
        private readonly OpenSSLCertificate $certificate,
        private readonly OpenSSLAsymmetricKey $key,
        /** @var list<string> les certificats des autorités au-dessus de celle-ci, hors racine, de la plus proche à la plus lointaine */
        private readonly array $ancestorsBelowRoot,
        private readonly bool $isRoot,
    ) {
    }

    public static function root(string $name = 'Repas Test Root CA'): self
    {
        [$certificate, $key, $pem] = self::create($name, 'v3_root', null, null, 3650);

        return new self($pem, $certificate, $key, [], true);
    }

    /**
     * Un intermédiaire signé par cette autorité. `$isAuthority` à faux : un certificat qui ne PEUT PAS signer d'autres certificats
     * (`CA:FALSE`), que tout valideur sérieux doit refuser comme maillon d'une chaîne.
     */
    public function intermediate(string $name = 'Repas Test Intermediate CA', bool $isAuthority = true): self
    {
        [$certificate, $key, $pem] = self::create($name, $isAuthority ? 'v3_intermediate' : 'v3_leaf_plain', $this->certificate, $this->key, 3650);

        return new self($pem, $certificate, $key, $this->isRoot ? [] : [$this->certificatePem, ...$this->ancestorsBelowRoot], false);
    }

    /**
     * @param list<string>|null $dnsNames  noms alternatifs (`null` : aucune extension de noms alternatifs, le nom n'est alors que dans le CN)
     * @param int               $days      durée de validité ; `0` : expire à la seconde de sa création (déjà expiré pour OpenSSL)
     * @param string|null       $keyPem    clé privée du certificat (générée si absente) : en réutiliser une accélère les tests
     * @param string            $usage     usage étendu de la clé (`serverAuth` : serveur TLS, comme celui d'Amazon ; `clientAuth` : client TLS)
     */
    public function issue(?array $dnsNames = ['echo-api.amazon.com'], int $days = 30, ?string $keyPem = null, bool $isAuthority = false, string $commonName = 'echo-api.amazon.com', string $usage = 'serverAuth'): AlexaTestLeaf
    {
        $extensions = $isAuthority ? 'v3_leaf_authority' : ($dnsNames === null ? 'v3_leaf_plain' : 'v3_leaf');
        [, $key, $pem, $privateKeyPem] = self::create($commonName, $extensions, $this->certificate, $this->key, $days, $dnsNames, $keyPem, $usage);

        return new AlexaTestLeaf($pem, $privateKeyPem, $this->certificatesAbove(), $key);
    }

    /**
     * Un certificat feuille AUTO-SIGNÉ (il se garantit lui-même, aucune autorité derrière lui) qui nomme `echo-api.amazon.com`.
     */
    public static function selfSignedLeaf(): AlexaTestLeaf
    {
        [, $key, $pem, $privateKeyPem] = self::create('echo-api.amazon.com', 'v3_leaf', null, null, 30, ['echo-api.amazon.com']);

        return new AlexaTestLeaf($pem, $privateKeyPem, [], $key);
    }

    /**
     * Le fichier PEM de cette autorité, à donner au vérificateur comme racine de confiance.
     */
    public function trustFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alexa-test-root-');
        if ($path === false || file_put_contents($path, $this->certificatePem) === false) {
            throw new RuntimeException('Fichier de racine de test impossible.');
        }
        self::$temporaryFiles[] = $path;
        if (count(self::$temporaryFiles) === 1) {
            register_shutdown_function(static function (): void {
                foreach (self::$temporaryFiles as $file) {
                    @unlink($file);
                }
            });
        }

        return $path;
    }

    /**
     * Une clé RSA privée neuve (PEM) : à passer à plusieurs `issue()` pour n'en générer qu'une.
     */
    public static function newPrivateKey(int $bits = 2048): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Génération de la clé de test impossible.');
        }

        return $pem;
    }

    /**
     * Les certificats que la chaîne d'Amazon porte après le certificat de signature : cette autorité et celles au-dessus d'elle,
     * sans la racine (que le vérificateur trouve dans son magasin).
     *
     * @return list<string>
     */
    private function certificatesAbove(): array
    {
        return $this->isRoot ? [] : [$this->certificatePem, ...$this->ancestorsBelowRoot];
    }

    /**
     * @param list<string>|null $dnsNames
     *
     * @return array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey, 2: string, 3: string}
     */
    private static function create(
        string $commonName,
        string $extensions,
        ?OpenSSLCertificate $issuerCertificate,
        ?OpenSSLAsymmetricKey $issuerKey,
        int $days,
        ?array $dnsNames = null,
        ?string $keyPem = null,
        string $usage = 'serverAuth',
    ): array {
        $config = self::writeConfiguration($commonName, $dnsNames, $usage);
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = $keyPem === null ? openssl_pkey_new($options) : openssl_pkey_get_private($keyPem);
            $request = $key === false ? false : openssl_csr_new(['commonName' => $commonName], $key, $options);
            $certificate = $request === false || $request === true || $key === false
                ? false
                : openssl_csr_sign($request, $issuerCertificate, $issuerKey ?? $key, $days, $options + ['x509_extensions' => $extensions], random_int(1, PHP_INT_MAX));
            if ($key === false
                || $certificate === false
                || !openssl_x509_export($certificate, $pem)
                || !openssl_pkey_export($key, $privateKeyPem, null, ['config' => $config])
            ) {
                throw new RuntimeException('Certificat de test impossible.');
            }
        } finally {
            @unlink($config);
            while (openssl_error_string() !== false) {
                // Vide la file d'erreurs d'OpenSSL.
            }
        }

        return [$certificate, $key, $pem, $privateKeyPem];
    }

    /**
     * @param list<string>|null $dnsNames
     */
    private static function writeConfiguration(string $commonName, ?array $dnsNames, string $usage): string
    {
        $alternativeNames = $dnsNames === null || $dnsNames === []
            ? ''
            : 'subjectAltName = ' . implode(',', array_map(static fn (string $name): string => 'DNS:' . $name, $dnsNames)) . "\n";

        $configuration = <<<CNF
            [req]
            distinguished_name = dn
            prompt = no
            [dn]
            CN = {$commonName}
            [v3_root]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            [v3_intermediate]
            basicConstraints = critical,CA:TRUE,pathlen:0
            keyUsage = critical,keyCertSign,cRLSign
            subjectKeyIdentifier = hash
            authorityKeyIdentifier = keyid
            [v3_leaf]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = {$usage}
            {$alternativeNames}authorityKeyIdentifier = keyid
            [v3_leaf_plain]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            authorityKeyIdentifier = keyid
            [v3_leaf_authority]
            basicConstraints = critical,CA:TRUE
            keyUsage = critical,digitalSignature,keyEncipherment,keyCertSign
            extendedKeyUsage = serverAuth
            {$alternativeNames}authorityKeyIdentifier = keyid
            CNF;

        $path = tempnam(sys_get_temp_dir(), 'alexa-test-cnf-');
        if ($path === false || file_put_contents($path, $configuration . "\n") === false) {
            throw new RuntimeException('Configuration OpenSSL de test impossible.');
        }

        return $path;
    }
}
