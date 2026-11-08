<?php

namespace Repas\Repas\Domain\Exception;


use Repas\Shared\Domain\Exception\DomainException;

/**
 * Les refus de la copie d'une recette depuis Marmiton (ADR 0037, MAR-001). Les messages sont des constantes que le front traduit : ils ne
 * se renomment pas. Le code HTTP est celui de l'ADR 0037 décision 11 (`422` pour ce que la personne peut corriger, `502` quand Marmiton
 * ne répond pas comme il faut : on ne distingue pas un blocage d'une panne).
 */
final class MarmitonImportException extends DomainException
{
    public const string URL_INVALID = 'MARMITON_URL_INVALID';
    public const string RECIPE_NOT_FOUND = 'MARMITON_RECIPE_NOT_FOUND';
    public const string RECIPE_UNREADABLE = 'MARMITON_RECIPE_UNREADABLE';
    public const string UNAVAILABLE = 'MARMITON_UNAVAILABLE';
    /** Le plafond d'imports (appels sortants) d'un utilisateur est atteint : `429` + `Retry-After`, portée par `RateLimitedException` (ADR 0037 décision 14). */
    public const string RATE_LIMITED = 'MARMITON_IMPORT_RATE_LIMITED';

    /** Ce n'est pas l'adresse d'une recette Marmiton (hôte, chemin ou identifiant hors de la forme permise). */
    public static function urlInvalid(): self
    {
        return new self(self::URL_INVALID, 422);
    }

    /** Marmiton répond « introuvable » (404) ou « disparue » (410) pour cet identifiant. */
    public static function recipeNotFound(): self
    {
        return new self(self::RECIPE_NOT_FOUND, 422);
    }

    /** La page existe mais ne porte pas de recette lisible en entier : aucun bloc `Recipe`, pas de nom, pas de ligne d'ingrédient. */
    public static function recipeUnreadable(): self
    {
        return new self(self::RECIPE_UNREADABLE, 422);
    }

    /** Délai dépassé, erreur réseau ou TLS, refus ou panne du serveur, redirection hors de Marmiton, réponse trop grosse ou d'un autre type. */
    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 502);
    }
}
