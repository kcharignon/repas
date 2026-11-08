<?php

namespace Repas\Repas\Infrastructure\Marmiton;


use Generator;
use JsonException;
use Repas\Repas\Domain\Exception\MarmitonImportException;
use Repas\Repas\Domain\Model\MarmitonRecipePage;

/**
 * Tire d'une page Marmiton les faits de sa recette (ADR 0037 décisions 1 et 13, MAR-001). Seuls les blocs
 * `<script type="application/ld+json">` sont lus : le HTML n'est jamais interprété, ni conservé, ni suivi (aucun lien, aucun autre
 * balisage, rien de la page n'est évalué). Chaque bloc est décodé en JSON (profondeur 16, `JSON_THROW_ON_ERROR`) ; un bloc illisible est
 * sauté, pas fatal. On cherche un objet de type `Recipe` à la racine, dans une liste racine ou dans un `@graph` (le bloc est un objet, un
 * tableau d'objets ou un graphe selon la page), et on retient le premier qui est lisible EN ENTIER :
 *
 * - `name` : un texte non vide ;
 * - `recipeIngredient` : une liste non vide de textes, 100 au plus (une seule entrée qui n'est pas un texte rend toute la recette illisible :
 *   on ne fait jamais une recette à moitié remplie) ; les entrées vides sont ignorées ;
 * - `recipeYield` et `recipeCategory`, facultatifs : un texte (ou un nombre, ou le premier d'une liste), `null` sinon ;
 * - tout le reste du bloc (étapes, photos, notes, auteur) est ignoré et jamais gardé.
 *
 * Les textes sont débarrassés de leurs caractères de contrôle et de leurs espaces multiples, puis bornés (300 caractères une ligne). Aucun
 * autre nettoyage : lire « 250 g de farine » est le travail de MAR-002.
 */
final readonly class MarmitonRecipeExtractor
{
    /** Au plus ce nombre de balises `<script>` est examiné : une page n'en a pas des milliers, un corps hostile pourrait. */
    private const int MAX_SCRIPTS = 300;
    /** La longueur maximale des attributs d'une balise `<script>` (le type en fait partie). */
    private const int MAX_TAG_LENGTH = 1000;
    private const int JSON_DEPTH = 16;
    /** Profondeur de recherche d'un objet `Recipe` : racine, liste, objet, `@graph`, liste, objet. */
    private const int MAX_NESTING = 4;
    /** Nombre de nœuds examinés par liste (un `@graph` d'une page de recette en compte une dizaine). */
    private const int MAX_NODES = 100;
    /** Nombre d'entrées d'une liste d'ingrédients que l'on accepte d'examiner (les entrées vides ne comptent pas parmi les 100 lignes). */
    private const int MAX_ENTRIES = 1000;

    /**
     * @throws MarmitonImportException `recipeUnreadable` quand aucun bloc ne porte une recette lisible en entier
     */
    public function extract(string $html, string $canonicalUrl): MarmitonRecipePage
    {
        foreach ($this->jsonLdBlocks($html) as $block) {
            try {
                $decoded = json_decode($block, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            foreach ($this->recipeNodes($decoded, 0) as $node) {
                $page = $this->pageFrom($node, $canonicalUrl);
                if ($page !== null) {
                    return $page;
                }
            }
        }

        throw MarmitonImportException::recipeUnreadable();
    }

    /**
     * Le contenu de chaque balise `<script type="application/ld+json">`, dans l'ordre de la page. Une lecture linéaire, sans expression
     * régulière sur le corps entier : pas de retour arrière coûteux sur un corps de 2 Mio.
     *
     * @return Generator<int, string>
     */
    private function jsonLdBlocks(string $html): Generator
    {
        $offset = 0;
        $examined = 0;

        while ($examined < self::MAX_SCRIPTS && ($open = stripos($html, '<script', $offset)) !== false) {
            ++$examined;
            $attributesStart = $open + 7;

            // `<scripts>` ou `<script-x>` ne sont pas des balises `<script>` : le nom doit se terminer.
            if (!in_array($html[$attributesStart] ?? '', [' ', "\t", "\n", "\r", "\f", '/', '>'], true)) {
                $offset = $attributesStart;
                continue;
            }

            $tagEnd = strpos($html, '>', $attributesStart);
            if ($tagEnd === false || $tagEnd - $attributesStart > self::MAX_TAG_LENGTH) {
                return;
            }

            $close = stripos($html, '</script', $tagEnd);
            if ($close === false) {
                return;
            }

            if ($this->isJsonLd(substr($html, $attributesStart, $tagEnd - $attributesStart))) {
                yield substr($html, $tagEnd + 1, $close - $tagEnd - 1);
            }

            $offset = $close + 8;
        }
    }

    private function isJsonLd(string $attributes): bool
    {
        $matched = preg_match_all('/(?:^|[\s"\'\/])type\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attributes, $matches, PREG_SET_ORDER);
        if ($matched === false || $matched === 0) {
            return false;
        }

        foreach ($matches as $match) {
            $value = strtolower(trim($match[1] . ($match[2] ?? '') . ($match[3] ?? '')));
            if ($value === 'application/ld+json') {
                return true;
            }
        }

        return false;
    }

    /**
     * Les objets de type `Recipe` : la racine, les éléments d'une liste, le contenu d'un `@graph`.
     *
     * @return Generator<int, array<mixed>>
     */
    private function recipeNodes(mixed $node, int $depth): Generator
    {
        if (!is_array($node) || $depth > self::MAX_NESTING) {
            return;
        }

        if (array_is_list($node)) {
            foreach (array_slice($node, 0, self::MAX_NODES) as $item) {
                yield from $this->recipeNodes($item, $depth + 1);
            }

            return;
        }

        if ($this->isRecipe($node)) {
            yield $node;
        }

        if (array_key_exists('@graph', $node)) {
            yield from $this->recipeNodes($node['@graph'], $depth + 1);
        }
    }

    /**
     * @param array<mixed> $node
     */
    private function isRecipe(array $node): bool
    {
        $types = $node['@type'] ?? null;
        foreach (is_array($types) ? $types : [$types] as $type) {
            // `Recipe`, `schema:Recipe` ou `https://schema.org/Recipe`
            if (is_string($type) && preg_match('~(?:^|[/:])Recipe\z~', $type) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     */
    private function pageFrom(array $node, string $canonicalUrl): ?MarmitonRecipePage
    {
        $title = is_string($node['name'] ?? null) ? $this->clean($node['name'], MarmitonRecipePage::MAX_TITLE_LENGTH) : '';
        if ($title === '') {
            return null;
        }

        $lines = $this->ingredientLines($node['recipeIngredient'] ?? null);
        if ($lines === null) {
            return null;
        }

        return new MarmitonRecipePage(
            $canonicalUrl,
            $title,
            $this->label($node['recipeYield'] ?? null),
            $this->label($node['recipeCategory'] ?? null),
            $lines,
        );
    }

    /**
     * @return list<string>|null `null` si la liste manque, n'est pas une liste de textes, est vide ou a plus de 100 lignes
     */
    private function ingredientLines(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_ENTRIES) {
            return null;
        }

        $lines = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return null;
            }

            $line = $this->clean($entry, MarmitonRecipePage::MAX_LINE_LENGTH);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] || count($lines) > MarmitonRecipePage::MAX_LINES ? null : $lines;
    }

    /**
     * Un texte facultatif (`recipeYield`, `recipeCategory`) : un texte, un nombre, ou le premier de ces éléments d'une liste.
     */
    private function label(mixed $value): ?string
    {
        if (is_array($value)) {
            // Un objet (`{"@type": "QuantitativeValue", …}`) n'est pas une liste de textes.
            if (!array_is_list($value)) {
                return null;
            }

            foreach (array_slice($value, 0, 5) as $item) {
                $label = is_array($item) ? null : $this->label($item);
                if ($label !== null) {
                    return $label;
                }
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $label = $this->clean($value, MarmitonRecipePage::MAX_LABEL_LENGTH);

        return $label === '' ? null : $label;
    }

    /**
     * Les espaces (insécables compris) et les caractères de contrôle deviennent une espace, les suites d'espaces une seule ; le texte est
     * coupé à `$maxLength` caractères. Un texte qui n'est pas de l'UTF-8 valide donne un texte vide.
     */
    private function clean(string $text, int $maxLength): string
    {
        $text = preg_replace('/[\p{Z}\p{C}]+/u', ' ', $text);
        if ($text === null) {
            return '';
        }

        return trim(mb_substr(trim($text), 0, $maxLength));
    }
}
