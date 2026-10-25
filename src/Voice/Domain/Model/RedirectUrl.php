<?php

namespace Repas\Voice\Domain\Model;


/**
 * L'adresse où l'on renvoie l'utilisateur après qu'il a autorisé Alexa (ADR 0029 décision 6) : l'adresse de retour AUTORISÉE (choisie
 * dans la configuration, jamais construite d'après la requête) suivie de `code` et `state`, encodés (RFC 3986).
 */
final class RedirectUrl
{
    private function __construct()
    {
    }

    public static function build(string $redirectUri, string $code, string $state): string
    {
        $query = http_build_query(['state' => $state, 'code' => $code], '', '&', PHP_QUERY_RFC3986);

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query;
    }
}
