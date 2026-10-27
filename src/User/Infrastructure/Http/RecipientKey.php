<?php

namespace Repas\User\Infrastructure\Http;


use Repas\User\Domain\Model\User;

/**
 * La forme d'une adresse saisie qui sert de clé au plafond par destinataire (`PublicEmailRateLimitListener`) : celle qu'utilise la
 * recherche du compte (`User::normalizeEmail` : casse et espaces autour sans effet), sans l'étiquette de la partie locale (`nom+x@domaine`
 * compte comme `nom@domaine`, les deux arrivent dans la même boîte). Pure : une chaîne en entrée, une chaîne en sortie.
 *
 * Seule la CLÉ du plafond ignore l'étiquette : la recherche du compte garde l'adresse telle quelle. Une partie locale qui ne serait faite
 * que d'une étiquette (`+x@domaine`) est gardée entière.
 */
final readonly class RecipientKey
{
    private function __construct()
    {
    }

    public static function of(string $email): string
    {
        $normalized = preg_match('//u', $email) === 1 ? User::normalizeEmail($email) : strtolower(trim($email));

        $at = strrpos($normalized, '@');
        if ($at === false) {
            return $normalized;
        }

        $local = substr($normalized, 0, $at);
        $plus = strpos($local, '+');
        if ($plus === false || $plus === 0) {
            return $normalized;
        }

        return substr($local, 0, $plus) . substr($normalized, $at);
    }
}
