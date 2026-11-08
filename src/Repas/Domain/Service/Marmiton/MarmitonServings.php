<?php

namespace Repas\Repas\Domain\Service\Marmiton;


/**
 * Le nombre de personnes d'une recette Marmiton (ADR 0037 décision 2) : le premier nombre de `recipeYield`, de 1 à 100, sinon 4.
 * « 6 personnes » donne 6, « 4 à 6 personnes » 4, « 12 madeleines » 12, « pour 8 » 8 ; « 0 », « 250 », « quelques parts » et rien donnent 4.
 */
final readonly class MarmitonServings
{
    public const int DEFAULT = 4;

    private const int MIN = 1;

    private const int MAX = 100;

    /** Plus de chiffres que cela, le nombre est hors des bornes sans qu'il faille le convertir. */
    private const int MAX_DIGITS = 3;

    private function __construct()
    {
    }

    public static function from(?string $yield): int
    {
        if ($yield === null || preg_match('/\d+/', $yield, $match) !== 1 || strlen($match[0]) > self::MAX_DIGITS) {
            return self::DEFAULT;
        }
        $servings = (int) $match[0];

        return $servings >= self::MIN && $servings <= self::MAX ? $servings : self::DEFAULT;
    }
}
