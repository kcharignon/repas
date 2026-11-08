<?php

namespace Repas\Tests\Helper;


/**
 * Des pages Marmiton pour les tests : le jeu d'essai du dépôt (le bloc `ld+json` réduit aux faits d'une vraie page, sans étapes ni photo)
 * et de quoi en fabriquer d'autres. Aucun test automatique n'appelle Marmiton.
 */
final class MarmitonPages
{
    public const string EXAMPLE_URL = 'https://www.marmiton.org/recettes/recette_the-tarte-au-citron-meringuee_22082.aspx';
    public const string EXAMPLE_TITLE = 'Tarte au citron meringuée : la meilleure recette';
    /** @var list<string> les lignes d'ingrédients du jeu d'essai, dans l'ordre */
    public const array EXAMPLE_LINES = [
        '250 g de farine',
        '125 g de beurre doux',
        '70 g de sucre semoule',
        "2 jaunes d'oeuf",
        "5 cl d'eau",
        '1 pincées de sel',
        '4 citrons de taille moyenne',
        '150 g de sucre semoule',
        '3 oeufs',
        '1 cuillères à soupe de maïzena',
        "2 blancs d'oeuf",
        '100 g de sucre glace',
        '0.5 cuillères à café de levure chimique',
    ];

    private function __construct()
    {
    }

    /** La page d'exemple enregistrée (`tests/Repas/Fixture/Marmiton/`). */
    public static function example(): string
    {
        $html = file_get_contents(dirname(__DIR__) . '/Repas/Fixture/Marmiton/recette_the-tarte-au-citron-meringuee_22082.html');
        if ($html === false) {
            throw new \RuntimeException('Jeu d\'essai Marmiton introuvable.');
        }

        return $html;
    }

    /**
     * Une page HTML minimale dont chaque bloc est écrit tel quel dans une balise `<script type="application/ld+json">`.
     *
     * @param list<mixed> $blocks un texte est écrit tel quel, toute autre valeur est encodée en JSON
     */
    public static function page(array $blocks, string $head = ''): string
    {
        $scripts = '';
        foreach ($blocks as $block) {
            $json = is_string($block) ? $block : json_encode($block, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $scripts .= '<script type="application/ld+json">' . $json . "</script>\n";
        }

        return "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\">\n" . $head . $scripts . "</head><body><p>Une page.</p></body></html>\n";
    }

    /**
     * Un objet `Recipe` lisible ; `$overrides` remplace ou ajoute des clés (une valeur `null` retire la clé).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function recipe(array $overrides = []): array
    {
        $recipe = array_merge([
            '@type' => 'Recipe',
            'name' => self::EXAMPLE_TITLE,
            'recipeCategory' => 'Dessert',
            'recipeYield' => '6 personnes',
            'recipeIngredient' => self::EXAMPLE_LINES,
        ], $overrides);

        return array_filter($recipe, static fn (mixed $value): bool => $value !== null);
    }
}
