<?php

namespace Repas\Repas\Domain\Service\Marmiton;


use Repas\Shared\Domain\Tool\StringTool;

/**
 * Le type de recette de Repas pour une catégorie de Marmiton (ADR 0037 décision 2) : entrée -> `entree` ; plat principal, accompagnement,
 * sauce -> `plat` ; dessert, confiserie -> `dessert` ; amuse-gueule -> `apero` ; boisson -> `boisson`. La casse et les accents ne comptent
 * pas ; une catégorie inconnue, vide ou absente donne `plat`. Les slugs sont ceux de `recipe_type.json`.
 */
final readonly class MarmitonRecipeCategory
{
    public const string DEFAULT = 'plat';

    /** Plus long que cela, ce n'est pas une catégorie : on ne l'examine pas. */
    private const int MAX_LENGTH = 100;

    /** @var array<string, string> catégorie normalisée (`StringTool::slugify`) => slug du type */
    private const array TYPES = [
        'entree' => 'entree',
        'entrees' => 'entree',
        'plat' => self::DEFAULT,
        'plats' => self::DEFAULT,
        'plat-principal' => self::DEFAULT,
        'accompagnement' => self::DEFAULT,
        'accompagnements' => self::DEFAULT,
        'sauce' => self::DEFAULT,
        'sauces' => self::DEFAULT,
        'dessert' => 'dessert',
        'desserts' => 'dessert',
        'confiserie' => 'dessert',
        'confiseries' => 'dessert',
        'amuse-gueule' => 'apero',
        'amuse-gueules' => 'apero',
        'boisson' => 'boisson',
        'boissons' => 'boisson',
    ];

    private function __construct()
    {
    }

    public static function typeSlug(?string $category): string
    {
        if ($category === null || mb_strlen($category) > self::MAX_LENGTH) {
            return self::DEFAULT;
        }

        return self::TYPES[StringTool::slugify($category)] ?? self::DEFAULT;
    }
}
