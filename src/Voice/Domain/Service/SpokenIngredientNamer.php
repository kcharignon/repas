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
 *
 * `withoutLeadingUnit()` retire, EN TÊTE de la phrase entendue, une unité et son « de » ou « d' » quand Alexa les a laissés dans le créneau
 * `item` (« paquets de croquettes » : l'unité « paquet » et la phrase « croquettes »).
 *
 * `withoutLeadingQuantity()` fait de même d'un nombre (« 10 croquettes », « deux paquets de croquettes ») : Amazon le laisse collé au produit
 * quand il ne remplit pas le créneau `quantity` (essai réel du 2026-10-25).
 */
final readonly class SpokenIngredientNamer
{
    /** Un nom d'ingrédient dit à voix haute tient en quelques mots. */
    public const int MAX_LENGTH = 60;

    private const string LEADING_ARTICLE = "/^(?:de\\s+l'|de\\s+la\\s+|du\\s+|des\\s+|de\\s+|d'|l'|un\\s+|une\\s+|le\\s+|la\\s+|les\\s+)/u";

    /** Les nombres dits en lettres que l'on reconnaît en tête d'une phrase (« un » et « une » restent des articles : ils valent la quantité 1 par défaut). */
    private const array NUMBER_WORDS = [
        'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10, 'onze' => 11,
        'douze' => 12, 'treize' => 13, 'quatorze' => 14, 'quinze' => 15, 'seize' => 16, 'vingt' => 20,
    ];

    private SpokenUnits $units;

    public function __construct(?SpokenUnits $units = null)
    {
        $this->units = $units ?? new SpokenUnits();
    }

    /**
     * La phrase débarrassée de l'unité qu'elle commence par nommer (« paquets de croquettes » donne « croquettes » et l'unité `paquet`),
     * ou telle quelle avec une unité `null` quand elle n'en commence par aucune ou que rien ne la suit.
     *
     * @return array{phrase: string, unitSlug: ?string}
     */
    public function withoutLeadingUnit(string $phrase): array
    {
        $split = $this->units->splitLeading($phrase);

        return $split === null ? ['phrase' => $phrase, 'unitSlug' => null] : ['phrase' => $split['rest'], 'unitSlug' => $split['unitSlug']];
    }

    /**
     * La phrase débarrassée du nombre qu'elle commence par dire (« 10 croquettes » donne « croquettes » et la quantité 10), de 1 à 99 en
     * chiffres, de 2 à 20 en lettres ; telle quelle avec une quantité `null` sinon, ou quand rien ne suit le nombre (« dix » seul est un nom).
     *
     * @return array{phrase: string, quantity: ?int}
     */
    public function withoutLeadingQuantity(string $phrase): array
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', mb_strtolower($phrase)) ?? '');
        if (preg_match('/^(\d{1,2}|\p{L}+)\s+(\S.*)$/u', $normalized, $matches) !== 1) {
            return ['phrase' => $phrase, 'quantity' => null];
        }

        $quantity = ctype_digit($matches[1]) ? (int) $matches[1] : (self::NUMBER_WORDS[$matches[1]] ?? null);
        if ($quantity === null || $quantity < 1) {
            return ['phrase' => $phrase, 'quantity' => null];
        }

        return ['phrase' => $matches[2], 'quantity' => $quantity];
    }

    /**
     * Ce qu'Amazon a laissé dans le créneau `item` : le nombre (« 10 croquettes ») puis l'unité (« paquets de croquettes ») en tête sont retirés de
     * la phrase et retenus comme quantité et unité dites. Le nombre seulement tant que le créneau `quantity` n'a rien donné (`$quantity` vaut 1 ;
     * une quantité dite par le créneau gagne toujours) et l'unité seulement tant que le créneau `unit` n'a rien donné. Sert à ajouter comme à retirer.
     *
     * @param int     $quantity la quantité du créneau `quantity` (1 par défaut)
     * @param ?string $unitSlug l'unité du créneau `unit`
     *
     * @return array{phrase: string, quantity: int, unitSlug: ?string}
     */
    public function splitSpoken(string $phrase, int $quantity = 1, ?string $unitSlug = null): array
    {
        $counted = $this->withoutLeadingQuantity($phrase);
        if ($quantity === 1 && $counted['quantity'] !== null) {
            $quantity = $counted['quantity'];
            $phrase = $counted['phrase'];
        }

        $split = $this->withoutLeadingUnit($phrase);

        return ['phrase' => $split['phrase'], 'quantity' => $quantity, 'unitSlug' => $unitSlug ?? $split['unitSlug']];
    }

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
