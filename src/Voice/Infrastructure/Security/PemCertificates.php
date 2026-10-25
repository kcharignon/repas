<?php

namespace Repas\Voice\Infrastructure\Security;


/**
 * Extrait les certificats d'un texte PEM, et eux seuls : tout le reste (clé privée, commentaire, ligne parasite) est écarté, jamais
 * transmis à OpenSSL. Chaque certificat est relu par OpenSSL (`openssl_x509_read`) : un bloc illisible refuse le tout.
 */
final class PemCertificates
{
    private const string BLOCK = '/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\r\n]{1,16384}?-----END CERTIFICATE-----/';

    private function __construct()
    {
    }

    /**
     * @return list<string>|null les certificats (de 1 à `$max`), chacun terminé par un saut de ligne ; `null` si aucun, trop, ou un illisible
     */
    public static function split(string $text, int $max): ?array
    {
        $count = preg_match_all(self::BLOCK, $text, $matches);
        if ($count === false || $count < 1 || $count > $max) {
            return null;
        }

        $certificates = [];
        foreach ($matches[0] as $block) {
            // Un bloc illisible est attendu d'une réponse qu'on ne maîtrise pas : refus, pas avertissement PHP.
            $certificate = @openssl_x509_read($block);
            self::clearOpenSslErrors();
            if ($certificate === false) {
                return null;
            }
            $certificates[] = $block . "\n";
        }

        return $certificates;
    }

    /**
     * Vide la file d'erreurs d'OpenSSL : ses restes se mêleraient aux messages des vérifications suivantes.
     */
    public static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Rien à faire des messages : les raisons du refus sont les nôtres.
        }
    }
}
