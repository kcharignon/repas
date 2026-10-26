<?php

namespace Repas\Voice\Domain\Service;

use Repas\Shared\Domain\Tool\StringTool;

/**
 * Le lecteur des nombres dits à voix haute (ADR 0029 décisions 5 et 13) : le même pour le nombre laissé en tête d'un produit
 * (`SpokenIngredientNamer::withoutLeadingQuantity`) et pour le nombre de personnes d'un repas (`SpokenMealPhrase`). Pur.
 *
 * Il lit des chiffres (de un à trois : « 0 », « 6 », « 100 ») ou des mots de « un » à « quatre vingt dix neuf », avec des espaces ou des traits
 * d'union (« vingt et un », « quatre-vingt-dix-neuf »), « zéro » et « cent » ; tout le reste, y compris un nombre suivi d'un autre mot, donne `null`. Il ne
 * juge pas si le nombre est utilisable (1 à 99 pour une quantité ou un nombre de personnes) : c'est à l'appelant, qui doit pouvoir dire « zéro » ou
 * « cent » à l'utilisateur plutôt que de l'ignorer. Les mots lus sont ceux que le générateur du modèle d'interaction écrit pour le type `QUANTITY`
 * (`DocsVoiceSkillTest`).
 */
final readonly class SpokenNumber
{
    private const array SMALL = [
        1 => 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
    ];

    private const array TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    /**
     * Le nombre dit, ou `null` si ce n'en est pas un.
     */
    public function parse(string $said): ?int
    {
        $text = trim($said);
        if (preg_match('/^\d{1,3}$/', $text) === 1) {
            return (int) $text;
        }

        return self::table()[StringTool::slugify($text)] ?? null;
    }

    /**
     * Les formes écrites (sans accent, mots joints par « - ») de chaque nombre de 1 à 99, plus « cent ».
     *
     * @return array<string, int>
     */
    private static function table(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }

        $table = ['zero' => 0, 'une' => 1, 'cent' => 100, 'quatre-vingt' => 80];
        foreach (range(1, 99) as $number) {
            $table[self::word($number)] = $number;
        }

        return $table;
    }

    /** Un nombre de 1 à 99 en lettres, mots joints par « - » (la même règle que `frenchNumber` du générateur du modèle). */
    private static function word(int $number): string
    {
        if ($number <= 16) {
            return self::SMALL[$number];
        }
        if ($number < 20) {
            return 'dix-' . self::SMALL[$number - 10];
        }
        if ($number < 70) {
            $tens = self::TENS[intdiv($number, 10)];
            $units = $number % 10;

            return $units === 0 ? $tens : ($units === 1 ? $tens . '-et-un' : $tens . '-' . self::SMALL[$units]);
        }
        if ($number < 80) {
            return $number === 71 ? 'soixante-et-onze' : 'soixante-' . self::word($number - 60);
        }

        return $number === 80 ? 'quatre-vingts' : 'quatre-vingt-' . self::word($number - 80);
    }
}
