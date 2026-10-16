<?php

namespace Repas\Shared\Domain\Tool;

/**
 * Pertinence d'un nom (ingrédient, ...) pour un texte recherché, de 0.0 à 1.0. Les deux textes sont des slugs
 * (`StringTool::slugify()` : sans accent, sans majuscule, mots séparés par des tirets).
 *
 * Plus un nom est proche de ce qui est tapé, plus il monte :
 *  1. le nom exact ;
 *  2. un nom ou un mot du nom qui commence par le texte, le plus court d'abord (on tape « tom », on veut « tomate » avant
 *     « tomate cerise »). Un texte au milieu d'un mot ne compte pas (« riz » ne trouve pas « chorizo ») ;
 *  3. Jaro-Winkler sur le nom entier ou sur un mot du nom (un peu moins bien noté), qui rattrape les fautes de frappe
 *     (« tomtae » trouve « tomate »).
 */
final class SearchRelevance
{
    /** Au-dessous de ce score, un nom n'est pas une réponse à la recherche. */
    public const float MINIMUM = 0.85;

    /** Un mot du nom qui ressemble au texte vaut moins que le nom entier qui lui ressemble. */
    private const float WORD_PENALTY = 0.9;

    public static function score(string $search, string $name): float
    {
        if ($search === '' || $name === '') {
            return 0.0;
        }
        if ($search === $name) {
            return 1.0;
        }

        $score = JaroWinkler::similarity($search, $name);
        foreach (explode('-', $name) as $word) {
            $score = max($score, self::WORD_PENALTY * JaroWinkler::similarity($search, $word));
        }

        // Le texte commence le nom ou l'un de ses mots
        if (str_contains('-' . $name, '-' . $search)) {
            $score = max($score, 0.9 + 0.1 * mb_strlen($search) / mb_strlen($name));
        }

        // Seul un nom identique au texte atteint 1.0
        return min($score, 0.999);
    }
}
