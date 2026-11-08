<?php

namespace Repas\Repas\Domain\Service\Marmiton;


use Repas\Repas\Domain\Model\QuantityBounds;
use Repas\Shared\Domain\Tool\StringTool;

/**
 * Comprend une ligne d'ingrédient en texte libre de Marmiton (« 250 g de farine », « 2 jaunes d'oeuf », « 1 pincées de sel ») et en tire une
 * quantité, une unité de référence et un nom (ADR 0037 décision 7, MAR-002). Pur : ni base, ni réseau, ni horloge, et JAMAIS d'exception.
 *
 * Le principe : au moindre doute on garde les mots dans le nom plutôt que de deviner (un doublon se voit et se fusionne, un faux
 * rapprochement ne se voit pas, ADR 0037 décision 6). Les étapes, dans l'ordre :
 *
 * 1. Le texte est nettoyé (entités HTML, espaces insécables, apostrophes typographiques, parenthèses et leur contenu, fractions « ½ »,
 *    unité collée au nombre « 250g ») et borné à 1 000 caractères : aucune expression régulière ne travaille sur plus.
 * 2. La quantité est lue en tête, mot par mot : « 250 », « 0.5 », « 1,5 », « 1/2 », « 1 1/2 », « 2 à 3 » (le plus petit), « 2-3 »,
 *    « un » à « vingt », « demi », « un peu de » et « quelques » (1). Pas de quantité lisible, ou hors de `QuantityBounds` : 1.
 * 3. L'unité vient ensuite (`MarmitonUnitWords`), sinon `unite`. Une ligne sans quantité mais qui commence par une unité (« pincée de sel »)
 *    vaut 1 de cette unité.
 * 4. Le nom est ce qui reste : coupé à la première virgule et à « pour … » (« du beurre pour le moule »), débarrassé de son article (« de »,
 *    « d' », « du », « des », « de la », « de l' »…) et des précisions de taille en fin de nom (« de taille moyenne », « moyens », « gros »,
 *    « petites »), borné à 100 caractères.
 *
 * Une ligne sans nom (« . », « — », « 250 g ») rend `null` : elle est ignorée. Deux nombres (« 2 x 200 g de crème ») ne se multiplient pas :
 * c'est 2 `unite` et « 200 g de crème » dans le nom (trop malin sinon).
 */
final readonly class MarmitonIngredientLineParser
{
    /** Tout ce qui dépasse est ignoré avant de toucher à une expression régulière. */
    private const int MAX_INPUT_LENGTH = 1000;

    private const int MAX_RAW_LENGTH = 255;

    private const int MAX_NAME_LENGTH = 100;

    private const string DEFAULT_UNIT = 'unite';

    private const string NUMBER = '\d{1,15}(?:[.,]\d{1,15})?';

    /** @var array<string, string> fractions d'un seul caractère => écriture « n/d » */
    private const array FRACTIONS = [
        '½' => '1/2', '⅓' => '1/3', '⅔' => '2/3', '¼' => '1/4', '¾' => '3/4', '⅕' => '1/5', '⅛' => '1/8', '⅜' => '3/8', '⅝' => '5/8',
        '⅞' => '7/8',
    ];

    /** @var array<string, float> les quantités écrites en lettres (mots normalisés) */
    private const array NUMBER_WORDS = [
        'un' => 1.0, 'une' => 1.0, 'deux' => 2.0, 'trois' => 3.0, 'quatre' => 4.0, 'cinq' => 5.0, 'six' => 6.0, 'sept' => 7.0, 'huit' => 8.0,
        'neuf' => 9.0, 'dix' => 10.0, 'onze' => 11.0, 'douze' => 12.0, 'quinze' => 15.0, 'vingt' => 20.0,
        'demi' => 0.5, 'demie' => 0.5,
    ];

    /** Mots qui relient deux nombres d'une fourchette (« 2 à 3 », « 2 ou 3 », « 2 - 3 »). */
    private const array RANGE_WORDS = ['à', 'a', 'ou', '-'];

    private function __construct()
    {
    }

    /**
     * @return ParsedIngredientLine|null `null` quand la ligne ne dit aucun ingrédient (elle est alors ignorée)
     */
    public static function parse(string $line): ?ParsedIngredientLine
    {
        $text = self::normalise($line);
        if ($text === '') {
            return null;
        }
        $words = explode(' ', $text);

        [$quantity, $index, $quantityGiven] = self::readQuantity($words);
        if ($index < count($words) && in_array($words[$index], ['x', 'X', '×'], true) && $quantityGiven) {
            // « 2 x 200 g de crème » : on ne multiplie pas, le reste est le nom
            $index++;
        }

        $unit = MarmitonUnitWords::match(array_slice($words, $index, 3));
        $unitSlug = self::DEFAULT_UNIT;
        if ($unit !== null) {
            $unitSlug = $unit['slug'];
            $index += $unit['words'];
        }

        $name = self::cleanName(implode(' ', array_slice($words, $index)));
        // Un nom sans lettre ni chiffre (« / », « . ») n'est pas un produit
        if ($name === '' || preg_match('/[\p{L}\p{N}]/u', $name) !== 1) {
            return null;
        }

        return new ParsedIngredientLine(
            QuantityBounds::isQuantity($quantity) ? $quantity : 1.0,
            $unitSlug,
            $name,
            mb_substr(trim(mb_scrub($line)), 0, self::MAX_RAW_LENGTH),
        );
    }

    /**
     * Le texte de la ligne en mots séparés par UNE espace : sans HTML, sans parenthèses, sans espace spéciale, avec un espace entre le nombre
     * et l'unité qui le suit.
     */
    private static function normalise(string $line): string
    {
        $text = mb_substr(mb_scrub($line), 0, self::MAX_INPUT_LENGTH);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Les apostrophes et tirets typographiques, les espaces de toute sorte (insécable, fine, retours à la ligne, largeur nulle)
        $text = strtr($text, ['’' => "'", '‘' => "'", '`' => "'", '′' => "'", '–' => '-', '—' => '-', '−' => '-', '×' => ' x ']);
        $text = (string) preg_replace('/[\p{Z}\s\x{200B}\x{FEFF}]+/u', ' ', $text);
        // Une parenthèse (même jamais fermée) et ce qu'elle contient : « 200 g de farine (type 55) »
        $text = (string) preg_replace('/\([^)]*\)?/u', ' ', $text);
        // Les fractions d'un caractère : « 1½ » est « 1 1/2 »
        $text = strtr($text, array_map(static fn(string $fraction): string => " $fraction ", self::FRACTIONS));
        // « 2x200g » est « 2 x 200g » ; l'unité collée au nombre est détachée : « 250g », « 1,5kg », « 5cl », « 1l »
        $text = (string) preg_replace('/(?<=\d)x(?=\d)/u', ' x ', $text);
        $text = (string) preg_replace('/(?<=\d)(?=\p{L})/u', ' ', $text);
        // Une puce ou un tilde en tête n'est pas un mot
        $text = (string) preg_replace('/^[\s~≈*•·\-]+/u', '', $text);
        $text = (string) preg_replace('/ {2,}/', ' ', $text);

        return trim($text);
    }

    /**
     * @param list<string> $words
     *
     * @return array{float, int, bool} la quantité, l'index du premier mot qui la suit, et si la ligne en disait une
     */
    private static function readQuantity(array $words): array
    {
        $first = mb_strtolower($words[0]);
        $second = mb_strtolower($words[1] ?? '');
        // « un peu de poivre », « quelques feuilles de basilic » : 1
        if (in_array($first, ['un', 'une'], true) && $second === 'peu') {
            return [1.0, 2, true];
        }
        if (in_array($first, ['quelque', 'quelques'], true)) {
            return [1.0, 1, true];
        }
        // « environ 2 », « env. 2 »
        $start = 0;
        if (in_array(rtrim($first, '.'), ['environ', 'env'], true)) {
            $start = 1;
        }

        $number = self::readNumber($words, $start);
        if ($number === null) {
            return [1.0, 0, false];
        }
        [$value, $next] = $number;

        // « 2 à 3 gousses », « 2 ou 3 » : le plus petit des deux
        $connector = mb_strtolower($words[$next] ?? '');
        if (in_array($connector, self::RANGE_WORDS, true)) {
            $upper = self::readNumber($words, $next + 1);
            if ($upper !== null) {
                [$other, $next] = $upper;
                $value = min($value, $other);
            }
        }

        return [$value, $next, true];
    }

    /**
     * Un nombre à l'index donné : « 250 », « 0.5 », « 1,5 », « 1/2 », « 1 1/2 », « 2-3 » (le plus petit), « deux », « un demi ».
     *
     * @param list<string> $words
     *
     * @return array{float, int}|null la valeur et l'index du mot suivant, `null` si ce n'est pas un nombre
     */
    private static function readNumber(array $words, int $index): ?array
    {
        $word = $words[$index] ?? null;
        if ($word === null) {
            return null;
        }

        if (preg_match('/^(' . self::NUMBER . ')-(' . self::NUMBER . ')$/', $word, $range) === 1) {
            return [min(self::toFloat($range[1]), self::toFloat($range[2])), $index + 1];
        }
        if (self::isFraction($word)) {
            return [self::fraction($word), $index + 1];
        }
        if (preg_match('/^' . self::NUMBER . '$/', $word) === 1) {
            $value = self::toFloat($word);
            // « 1 1/2 » : un entier suivi d'une fraction
            if (ctype_digit($word) && isset($words[$index + 1]) && self::isFraction($words[$index + 1])) {
                return [$value + self::fraction($words[$index + 1]), $index + 2];
            }

            return [$value, $index + 1];
        }

        // Les mots : « deux », « une », « un demi »
        if (mb_strlen($word) <= 8) {
            $value = self::NUMBER_WORDS[StringTool::slugify($word)] ?? null;
            if ($value !== null) {
                $next = mb_strtolower($words[$index + 1] ?? '');
                if ($value === 1.0 && ($next === 'demi' || $next === 'demie')) {
                    return [0.5, $index + 2];
                }

                return [$value, $index + 1];
            }
        }

        return null;
    }

    private static function isFraction(string $word): bool
    {
        return preg_match('/^\d{1,9}\/\d{1,9}$/', $word) === 1;
    }

    private static function fraction(string $word): float
    {
        [$numerator, $denominator] = array_map('intval', explode('/', $word));

        // « 1/0 » n'est pas un nombre : zéro, que les bornes ramènent à 1
        return $denominator === 0 ? 0.0 : $numerator / $denominator;
    }

    private static function toFloat(string $number): float
    {
        return (float) str_replace(',', '.', $number);
    }

    /**
     * Le nom du produit dans ce qui suit la quantité et l'unité (voir la description de la classe).
     */
    private static function cleanName(string $rest): string
    {
        // Ce qui suit une virgule ou un point-virgule est une indication de préparation : « farine, tamisée »
        $cut = strcspn($rest, ',;');
        $name = substr($rest, 0, $cut);

        // « du beurre pour le moule » : tout ce qui suit « pour » est retiré
        if (preg_match('/(?:^|\s)pour(?:\s|$)/iu', $name, $match, PREG_OFFSET_CAPTURE) === 1) {
            $name = substr($name, 0, $match[0][1]);
        }

        // L'article qui suit l'unité : « de », « d' », « du », « des », « de la », « de l' » (deux fois : « de la » après « des »)
        for ($pass = 0; $pass < 2; $pass++) {
            $name = (string) preg_replace('/^(?:(?:de\s+(?:la|le|les)|du|des|de|la|le|les)\s+|de\s+l\'|d\'|l\')/iu', '', ltrim($name));
        }

        // Les précisions de taille : « citrons de taille moyenne », « pommes de terre moyennes », « oeufs gros »
        $name = (string) preg_replace('/\s+de\s+taille\b.*$/iu', '', $name);
        $name = (string) preg_replace('/\s+(?:(?:très|bien)\s+)?(?:moyen(?:ne)?s?|gros(?:se)?s?|petit(?:e)?s?|grand(?:e)?s?)$/iu', '', $name);

        $name = trim($name, " .:;,-*'");
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = trim(mb_substr($name, 0, self::MAX_NAME_LENGTH), " .:;,-*'");
        }

        return $name;
    }
}
