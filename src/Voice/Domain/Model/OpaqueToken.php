<?php

namespace Repas\Voice\Domain\Model;


/**
 * La forme des secrets de la liaison de compte (ADR 0029 décision 6) : le code d'autorisation, le jeton d'accès et le jeton de
 * rafraîchissement sont OPAQUES, 32 octets aléatoires en base64url sans remplissage (43 caractères). Un secret n'existe en clair que
 * dans la réponse qui le donne : la base ne garde que son haché SHA-256 (hexadécimal), jamais le secret.
 *
 * `isWellFormed()` écarte tout ce qui ne peut pas être un de nos secrets (un JWT, une chaîne trop longue, un caractère étranger) avant
 * la moindre requête SQL : un secret qui n'a pas cette forme n'a jamais été émis.
 */
final class OpaqueToken
{
    public const int BYTES = 32;
    public const int LENGTH = 43;

    private function __construct()
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{' . self::LENGTH . '}\z/', $token) === 1;
    }
}
