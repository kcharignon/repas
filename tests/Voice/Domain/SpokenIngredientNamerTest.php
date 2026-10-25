<?php

namespace Repas\Tests\Voice\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Voice\Domain\Service\SpokenIngredientNamer;

/**
 * Le nom du produit à créer d'après la phrase entendue (ADR 0029 décision 11, VOX-303) : sans article, en minuscules, borné, jamais sans lettre.
 */
class SpokenIngredientNamerTest extends TestCase
{
    #[DataProvider('phrases')]
    public function testItNamesTheProductFromThePhrase(string $phrase, ?string $expected): void
    {
        $this->assertSame($expected, new SpokenIngredientNamer()->nameFrom($phrase));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function phrases(): iterable
    {
        yield '« de la ciboulette »' => ['de la ciboulette', 'ciboulette'];
        yield '« du pain de mie »' => ['du pain de mie', 'pain de mie'];
        yield '« des tomates cerises »' => ['des tomates cerises', 'tomates cerises'];
        yield '« un melon »' => ['un melon', 'melon'];
        yield '« une courgette »' => ['une courgette', 'courgette'];
        yield '« le persil »' => ['le persil', 'persil'];
        yield '« la ciboulette »' => ['la ciboulette', 'ciboulette'];
        yield '« les haricots »' => ['les haricots', 'haricots'];
        yield '« de l\'ail »' => ['de l\'ail', 'ail'];
        yield '« d\'ail »' => ['d\'ail', 'ail'];
        yield '« l\'ail »' => ['l\'ail', 'ail'];
        yield 'apostrophe typographique' => ['de l’échalote', 'échalote'];
        yield 'plusieurs articles de suite' => ['de la la ciboulette', 'ciboulette'];
        yield 'majuscules' => ['De la CIBOULETTE', 'ciboulette'];
        yield 'espaces et tabulations' => ["  du \t pain   complet ", 'pain complet'];
        yield 'sans article' => ['ciboulette', 'ciboulette'];
        yield 'un article au milieu reste' => ['pain de mie', 'pain de mie'];
        yield 'un nom qui commence comme un article' => ['lardons', 'lardons'];
        yield 'un nom qui commence par « un »' => ['unagi', 'unagi'];
        yield 'un tiret reste' => ['chocolat au lait', 'chocolat au lait'];
        yield 'nombre dans le nom' => ['sauce 4 fromages', 'sauce 4 fromages'];
        yield 'signes retirés' => ['de la ciboulette !', 'ciboulette'];
        yield 'balises retirées' => ['<speak>ciboulette</speak>', 'speak ciboulette speak'];
        yield 'un article seul reste le nom' => ['la', 'la'];
        yield 'aucune lettre' => ['12', null];
        yield 'signes seulement' => ['?!', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('phrasesWithAUnit')]
    public function testALeadingUnitAndItsDeAreRemovedFromThePhrase(string $phrase, string $expectedPhrase, ?string $expectedUnit): void
    {
        // When
        $result = new SpokenIngredientNamer()->withoutLeadingUnit($phrase);

        // Then
        $this->assertSame(['phrase' => $expectedPhrase, 'unitSlug' => $expectedUnit], $result);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function phrasesWithAUnit(): iterable
    {
        yield '« paquets de croquettes »' => ['paquets de croquettes', 'croquettes', 'paquet'];
        yield '« paquet de croquettes »' => ['paquet de croquettes', 'croquettes', 'paquet'];
        yield '« kilos de tomates »' => ['kilos de tomates', 'tomates', 'kilo'];
        yield '« kilo de tomates »' => ['kilo de tomates', 'tomates', 'kilo'];
        yield '« kilos d\'ail »' => ["kilos d'ail", 'ail', 'kilo'];
        yield 'apostrophe typographique' => ['kilos d’ail', 'ail', 'kilo'];
        yield 'majuscules' => ['Paquets De Croquettes', 'croquettes', 'paquet'];
        yield 'espaces' => ["  paquets   de  croquettes ", 'croquettes', 'paquet'];
        yield 'unité de plusieurs mots' => ['cuillères à soupe de sucre', 'sucre', 'cuillere-a-soupe'];
        yield 'le plus long d\'abord' => ['tasses à café de lait', 'lait', 'tasse-a-cafe'];
        yield 'tasse seule' => ['tasse de lait', 'lait', 'tasse-a-cafe'];
        yield 'boîte avec accent' => ['boîtes de thon', 'thon', 'boite'];
        yield 'boite sans accent' => ['boite de thon', 'thon', 'boite'];
        yield 'pièces' => ['pièces de boeuf', 'boeuf', 'unite'];
        yield 'un nom sans unité' => ['du pain', 'du pain', null];
        yield 'un nom sans unité, « de » au milieu' => ['pain de mie', 'pain de mie', null];
        yield 'une unité sans « de » n\'est pas retirée' => ['paquets croquettes', 'paquets croquettes', null];
        yield 'une unité seule est un nom' => ['paquets', 'paquets', null];
        yield 'une unité suivie de « de » seul est un nom' => ['paquets de', 'paquets de', null];
        yield 'un nom qui commence comme une unité' => ['bolognaise', 'bolognaise', null];
        yield 'un nom qui commence par pot' => ['potiron', 'potiron', null];
        yield 'l\'unité n\'est retirée qu\'en tête' => ['croquettes en paquets de dix', 'croquettes en paquets de dix', null];
    }

    #[DataProvider('phrasesWithAQuantity')]
    public function testALeadingNumberIsRemovedFromThePhraseAndSaidAsTheQuantity(string $phrase, string $expectedPhrase, ?int $expectedQuantity): void
    {
        // When
        $result = new SpokenIngredientNamer()->withoutLeadingQuantity($phrase);

        // Then
        $this->assertSame(['phrase' => $expectedPhrase, 'quantity' => $expectedQuantity], $result);
    }

    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function phrasesWithAQuantity(): iterable
    {
        yield '« 10 croquettes » (essai réel)' => ['10 croquettes', 'croquettes', 10];
        yield 'un chiffre' => ['2 croquettes', 'croquettes', 2];
        yield 'avec une unité derrière' => ['2 paquets de croquettes', 'paquets de croquettes', 2];
        yield 'en lettres' => ['deux croquettes', 'croquettes', 2];
        yield 'en lettres, majuscules et espaces' => ["  Trois   Paquets de croquettes", 'paquets de croquettes', 3];
        yield 'vingt' => ['vingt tomates', 'tomates', 20];
        yield '99' => ['99 tomates', 'tomates', 99];
        yield 'un nom qui contient un nombre plus loin' => ['sauce 4 fromages', 'sauce 4 fromages', null];
        yield 'sans nombre' => ['du pain', 'du pain', null];
        yield '« un » reste un article' => ['un pain', 'un pain', null];
        yield '« une » reste un article' => ['une baguette', 'une baguette', null];
        yield 'zéro n\'est pas une quantité' => ['0 croquette', '0 croquette', null];
        yield 'trois chiffres sont refusés' => ['100 croquettes', '100 croquettes', null];
        yield 'un nombre seul est un nom' => ['10', '10', null];
        yield 'un nombre en lettres seul est un nom' => ['dix', 'dix', null];
        yield 'un nom qui commence comme un nombre' => ['sixtine', 'sixtine', null];
        yield 'vide' => ['', '', null];
    }

    #[DataProvider('spokenSplits')]
    public function testSplitSpokenTakesTheLeadingNumberAndUnitOffThePhraseUnlessTheSlotsGaveThem(string $phrase, int $quantity, ?string $unitSlug, string $expectedPhrase, int $expectedQuantity, ?string $expectedUnit): void
    {
        // When
        $result = new SpokenIngredientNamer()->splitSpoken($phrase, $quantity, $unitSlug);

        // Then
        $this->assertSame(['phrase' => $expectedPhrase, 'quantity' => $expectedQuantity, 'unitSlug' => $expectedUnit], $result);
    }

    /**
     * @return iterable<string, array{string, int, ?string, string, int, ?string}>
     */
    public static function spokenSplits(): iterable
    {
        yield 'rien à retirer' => ['des croquettes', 1, null, 'des croquettes', 1, null];
        yield 'un nombre' => ['10 croquettes', 1, null, 'croquettes', 10, null];
        yield 'un nombre et une unité' => ['2 paquets de croquettes', 1, null, 'croquettes', 2, 'paquet'];
        yield 'en lettres' => ['deux kilos de tomates', 1, null, 'tomates', 2, 'kilo'];
        yield 'une unité sans nombre' => ['paquets de croquettes', 1, null, 'croquettes', 1, 'paquet'];
        yield 'la quantité du créneau gagne sur un nombre resté dans le produit' => ['3 croquettes', 4, null, '3 croquettes', 4, null];
        yield 'l\'unité du créneau gagne sur une unité restée dans le produit' => ['2 paquets de croquettes', 1, 'kilo', 'croquettes', 2, 'kilo'];
        yield 'le créneau dit un, le produit un nombre : le nombre du produit' => ['5 croquettes', 1, null, 'croquettes', 5, null];
        yield 'un nom qui contient un nombre plus loin' => ['sauce 4 fromages', 1, null, 'sauce 4 fromages', 1, null];
    }

    public function testTheNameOfAProductIsStillTheWholeNameEvenWhenItStartsWithAUnit(): void
    {
        // « pot de miel » nommé à la création est un nom : seul `withoutLeadingUnit` retire l'unité, pas `nameFrom`
        $this->assertSame('pot de miel', new SpokenIngredientNamer()->nameFrom('un pot de miel'));
    }
    public function testANameOfSixtyCharactersFitsAndSixtyOneIsRefused(): void
    {
        $namer = new SpokenIngredientNamer();

        $this->assertSame(str_repeat('a', 60), $namer->nameFrom(str_repeat('a', 60)));
        $this->assertNull($namer->nameFrom(str_repeat('a', 61)));
    }

    public function testTheLengthIsCountedAfterTheArticleIsRemoved(): void
    {
        $this->assertSame(str_repeat('a', 60), new SpokenIngredientNamer()->nameFrom('de la ' . str_repeat('a', 60)));
    }

    /**
     * La création repasse le nom de l'état par ce normalisateur (l'état est une entrée non fiable) : un nom déjà normalisé doit rester tel quel.
     */
    #[DataProvider('phrases')]
    public function testANameAlreadyNormalisedIsLeftUnchanged(string $phrase, ?string $ignored): void
    {
        $namer = new SpokenIngredientNamer();
        $name = $namer->nameFrom($phrase);

        if ($name === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertSame($name, $namer->nameFrom($name));
    }
}
