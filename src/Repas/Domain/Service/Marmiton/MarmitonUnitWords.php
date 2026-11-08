<?php

namespace Repas\Repas\Domain\Service\Marmiton;


use Repas\Shared\Domain\Tool\StringTool;

/**
 * Les mots d'unité de Marmiton et l'unité de référence de Repas qu'ils désignent (ADR 0037 décision 7) : le SEUL endroit où ce tableau est
 * écrit (`MarmitonUnitWordsTest` le lit contre `unit.json`).
 *
 * Les clés sont des mots NORMALISÉS (`StringTool::slugify` : minuscules, sans accent ni ponctuation, mots séparés par un tiret) dont le « s » final
 * est retiré quand le mot a plus de trois lettres : « Cuillères à soupe » et « c. à s. » s'écrivent donc `cuillere-a-soupe` et `c-a-s`, et
 * « boîtes » `boite`. Une clé de plusieurs mots est comparée à plusieurs mots de la ligne, dans l'ordre.
 *
 * Choix simples : « tasse » seule est une tasse à café et « cuillère » seule une cuillère à soupe, les deux seules de Repas.
 */
final readonly class MarmitonUnitWords
{
    /** L'unité de référence ajoutée par ce chantier (`unit.json`, migration de MAR-003). */
    public const string PINCEE = 'pincee';

    /** Au plus ce nombre de mots forme une unité (« cuillère à soupe »). */
    private const int MAX_WORDS = 3;

    /** Un mot plus long n'est pas une unité : on ne l'examine même pas. */
    private const int MAX_WORD_LENGTH = 20;

    /** @var array<string, string> mot(s) normalisé(s) => slug de l'unité */
    public const array WORDS = [
        'g' => 'gramme',
        'gr' => 'gramme',
        'gramme' => 'gramme',
        'kg' => 'kilo',
        'kgs' => 'kilo',
        'kilo' => 'kilo',
        'kilogramme' => 'kilo',
        'l' => 'litre',
        'litre' => 'litre',
        'ml' => 'millilitre',
        'millilitre' => 'millilitre',
        'cl' => 'centilitre',
        'centilitre' => 'centilitre',
        'cuillere-a-soupe' => 'cuillere-a-soupe',
        'c-a-soupe' => 'cuillere-a-soupe',
        'c-a-s' => 'cuillere-a-soupe',
        'cas' => 'cuillere-a-soupe',
        'cs' => 'cuillere-a-soupe',
        'cuillere' => 'cuillere-a-soupe',
        'cuillere-a-cafe' => 'cuillere-a-cafe',
        'c-a-cafe' => 'cuillere-a-cafe',
        'c-a-c' => 'cuillere-a-cafe',
        'cac' => 'cuillere-a-cafe',
        'cc' => 'cuillere-a-cafe',
        'tasse-a-cafe' => 'tasse-a-cafe',
        'tasse' => 'tasse-a-cafe',
        'verre' => 'verre',
        'bol' => 'bol',
        'plaquette' => 'plaquette',
        'tablette' => 'tablette',
        'tranche' => 'tranche',
        'paquet' => 'paquet',
        'boite' => 'boite',
        'sachet' => 'sachet',
        'bouteille' => 'bouteille',
        'conserve' => 'conserve',
        'cube' => 'cube',
        'brique' => 'brique',
        'pot' => 'pot',
        'gousse' => 'gousse',
        'flacon' => 'flacon',
        'botte' => 'botte',
        'pincee' => self::PINCEE,
    ];

    private function __construct()
    {
    }

    /**
     * L'unité que disent les premiers mots (au plus trois) : la plus longue forme connue l'emporte (« tasse à café » avant « tasse »).
     *
     * @param list<string> $words les mots de la ligne à partir de l'endroit où une unité peut commencer
     *
     * @return array{slug: string, words: int}|null l'unité et le nombre de mots qu'elle occupe, `null` si le premier mot n'en est pas une
     */
    public static function match(array $words): ?array
    {
        $keys = [];
        foreach (array_slice($words, 0, self::MAX_WORDS) as $word) {
            $key = self::normalise($word);
            if ($key === '') {
                break;
            }
            $keys[] = $key;
        }

        for ($count = count($keys); $count >= 1; $count--) {
            $slug = self::WORDS[implode('-', array_slice($keys, 0, $count))] ?? null;
            if ($slug !== null) {
                return ['slug' => $slug, 'words' => $count];
            }
        }

        return null;
    }

    /**
     * Un mot de la ligne dans la forme des clés du tableau (vide s'il n'a ni lettre ni chiffre, ou s'il est trop long pour être une unité).
     */
    public static function normalise(string $word): string
    {
        if (mb_strlen($word) > self::MAX_WORD_LENGTH) {
            return '';
        }
        $key = StringTool::slugify($word);

        // « c.à.s » (c-a-s) garde son « s » : seul un mot de plus de trois lettres perd le sien
        $dash = strrpos($key, '-');
        $lastWord = $dash === false ? $key : substr($key, $dash + 1);

        return strlen($lastWord) > 3 && str_ends_with($key, 's') ? substr($key, 0, -1) : $key;
    }
}
