<?php

namespace Repas\Repas\Domain\Model;


use Repas\Repas\Domain\Exception\MarmitonImportException;

/**
 * Ce que l'application retient d'une page de recette Marmiton (ADR 0037 décisions 1 et 13) : les faits des données structurées
 * `schema.org/Recipe`, en texte, et rien d'autre (ni étapes, ni photos, ni notes). Aucun nettoyage ici : le nom, les personnes, le type
 * et les lignes d'ingrédients sont lus plus tard (MAR-002) ; le lecteur de page ne fait que normaliser les espaces et borner les longueurs.
 *
 * Le constructeur est la garde finale : une page sans nom, sans ligne ou avec des lignes démesurées n'est jamais un `MarmitonRecipePage`
 * (une recette neutre n'est créée que si la page est lisible en entier).
 */
final readonly class MarmitonRecipePage
{
    public const int MAX_LINES = 100;
    public const int MAX_LINE_LENGTH = 300;
    public const int MAX_TITLE_LENGTH = 300;
    public const int MAX_LABEL_LENGTH = 100;

    /**
     * @param string       $canonicalUrl    l'adresse de la page, lue après la redirection éventuelle, de la forme de `MarmitonUrl::canonical()`
     * @param string       $title           le titre de la recette (`name`), texte brut, tel que Marmiton l'écrit
     * @param string|null  $servingsText    `recipeYield` brut (« 6 personnes »), ou `null`
     * @param string|null  $category        `recipeCategory` brut (« Dessert »), ou `null`
     * @param list<string> $ingredientLines les lignes d'ingrédients brutes (« 250 g de farine »), de 1 à 100
     *
     * @throws MarmitonImportException
     */
    public function __construct(
        public string $canonicalUrl,
        public string $title,
        public ?string $servingsText,
        public ?string $category,
        public array $ingredientLines,
    ) {
        // L'adresse est revalidée : elle doit être exactement celle que `MarmitonUrl` reconstruirait.
        if (MarmitonUrl::parse($canonicalUrl)->canonical() !== $canonicalUrl) {
            throw MarmitonImportException::urlInvalid();
        }

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw MarmitonImportException::recipeUnreadable();
        }

        $lines = count($ingredientLines);
        if ($lines < 1 || $lines > self::MAX_LINES) {
            throw MarmitonImportException::recipeUnreadable();
        }

        foreach ($ingredientLines as $line) {
            if ($line === '' || mb_strlen($line) > self::MAX_LINE_LENGTH) {
                throw MarmitonImportException::recipeUnreadable();
            }
        }

        foreach ([$servingsText, $category] as $label) {
            if ($label !== null && ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH)) {
                throw MarmitonImportException::recipeUnreadable();
            }
        }
    }
}
