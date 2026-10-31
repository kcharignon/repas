<?php

namespace Repas\Repas\Infrastructure\Presentation;

use DateTimeInterface;
use IntlDateFormatter;

/**
 * La date en français qui nomme une liste de courses sans nom (« 15 janvier 2026 ») : écrite à un seul endroit, pour que les lectures des listes
 * et la réponse de la planification d'une recette (LST-005) donnent le même nom à la même liste.
 */
final readonly class ShoppingListDateLabel
{
    public static function formatter(): IntlDateFormatter
    {
        // « yyyy » est l'année du calendrier (« YYYY », celle de la semaine ISO, donnait « 29 décembre 2026 » pour le 29 décembre 2025)
        return new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, IntlDateFormatter::GREGORIAN, 'dd MMMM yyyy');
    }

    /**
     * Le nom de la liste, ou à défaut sa date : le nom affiché dans les listes en cours.
     */
    public static function nameOf(?string $name, DateTimeInterface $createdAt): string
    {
        return $name ?? (string) self::formatter()->format($createdAt);
    }
}
