<?php

namespace Repas\Voice\Domain\Service;

/**
 * Le nom du produit à créer d'après la phrase entendue (ADR 0029 décision 11, VOX-303) : « de la ciboulette » donne « ciboulette », « des
 * tomates cerises » donne « tomates cerises ». Pur.
 *
 * - en minuscules, comme tout le catalogue ; espaces réduits ; l'apostrophe typographique devient droite ;
 * - l'article du début est retiré (du, de la, de l', des, d', un, une, le, la, les, l'), même plusieurs de suite ; jamais ce qui en
 *   ferait un nom vide (« la » seul n'est pas un nom) ;
 * - les caractères qui ne sont ni lettre, chiffre, espace, apostrophe ni tiret sont remplacés par une espace (la voix transcrite n'en
 *   contient pas, un nom saisi ne doit pas en porter) ;
 * - refus (`null`) d'un nom sans aucune lettre (« 12 ») ou plus long que `MAX_LENGTH` : Alexa a mal compris, on redemande.
 */
final readonly class SpokenIngredientNamer
{
    /** Un nom d'ingrédient dit à voix haute tient en quelques mots. */
    public const int MAX_LENGTH = 60;

    private const string LEADING_ARTICLE = "/^(?:de\\s+l'|de\\s+la\\s+|du\\s+|des\\s+|de\\s+|d'|l'|un\\s+|une\\s+|le\\s+|la\\s+|les\\s+)/u";

    public function nameFrom(string $phrase): ?string
    {
        $name = mb_strtolower(str_replace('’', "'", $phrase));
        $name = preg_replace(["/[^\\p{L}\\p{N}\\s'\\-]+/u", '/\\s+/u'], ' ', $name) ?? '';
        $name = trim($name, " -'");

        // Un article à la fois, tant qu'il en reste un et qu'il reste un nom derrière
        while (preg_match(self::LEADING_ARTICLE, $name) === 1) {
            $without = trim((string) preg_replace(self::LEADING_ARTICLE, '', $name, 1), " -'");
            if ($without === '') {
                break;
            }
            $name = $without;
        }

        if ($name === '' || preg_match('/\p{L}/u', $name) !== 1 || mb_strlen($name) > self::MAX_LENGTH) {
            return null;
        }

        return $name;
    }
}
