<?php

namespace Repas\Voice\Domain\Model;


/**
 * PKCE (RFC 7636) de la liaison de compte (ADR 0029 décision 6) : seule la méthode `S256` est acceptée, `plain` est refusée. Le défi
 * (`code_challenge`) est le SHA-256 du vérificateur (`code_verifier`) en base64url sans remplissage : 43 caractères. Le vérificateur
 * fait de 43 à 128 caractères parmi les caractères non réservés (RFC 7636, 4.1).
 */
final class Pkce
{
    public const string METHOD_S256 = 'S256';

    private function __construct()
    {
    }

    public static function isChallenge(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $value) === 1;
    }

    public static function isVerifier(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $value) === 1;
    }

    public static function challengeOf(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Le vérificateur correspond au défi gardé avec le code : comparaison à temps constant, vérificateur mal formé refusé.
     */
    public static function verifies(string $verifier, string $challenge): bool
    {
        return self::isVerifier($verifier) && hash_equals($challenge, self::challengeOf($verifier));
    }
}
