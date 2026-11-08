<?php

namespace Repas\Tests\Repas\Domain\Service\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\Marmiton\MarmitonIngredientLineParser;

/**
 * Les lignes d'ingrédients de Marmiton (ADR 0037 décision 7, MAR-002) : la quantité, l'unité de référence et le nom attendus pour chaque
 * ligne, y compris les lignes pièges. Aucune base, aucun réseau.
 */
class MarmitonIngredientLineParserTest extends TestCase
{
    /**
     * Les mêmes cas, nommés d'après leur ligne (le numéro rend le nom unique).
     *
     * @return iterable<string, array{string, array{float, string, string}|null}>
     */
    public static function lines(): iterable
    {
        foreach (array_values(iterator_to_array(self::cases(), false)) as $index => $case) {
            yield sprintf('%03d %s', $index, $case[0] === '' ? '(vide)' : $case[0]) => $case;
        }
    }

    /**
     * Chaque cas : [ligne, [quantité, unité, nom]] ou `null` (ligne ignorée).
     *
     * @return iterable<int, array{string, array{float, string, string}|null}>
     */
    public static function cases(): iterable
    {
        // Les 13 lignes de la page d'exemple (tarte au citron meringuée, tests/Repas/Fixture/Marmiton)
        yield ['250 g de farine', [250.0, 'gramme', 'farine']];
        yield ['125 g de beurre doux', [125.0, 'gramme', 'beurre doux']];
        yield ['70 g de sucre semoule', [70.0, 'gramme', 'sucre semoule']];
        yield ["2 jaunes d'oeuf", [2.0, 'unite', "jaunes d'oeuf"]];
        yield ["5 cl d'eau", [5.0, 'centilitre', 'eau']];
        yield ['1 pincées de sel', [1.0, 'pincee', 'sel']];
        yield ['4 citrons de taille moyenne', [4.0, 'unite', 'citrons']];
        yield ['150 g de sucre semoule', [150.0, 'gramme', 'sucre semoule']];
        yield ['3 oeufs', [3.0, 'unite', 'oeufs']];
        yield ['1 cuillères à soupe de maïzena', [1.0, 'cuillere-a-soupe', 'maïzena']];
        yield ["2 blancs d'oeuf", [2.0, 'unite', "blancs d'oeuf"]];
        yield ['100 g de sucre glace', [100.0, 'gramme', 'sucre glace']];
        yield ['0.5 cuillères à café de levure chimique', [0.5, 'cuillere-a-cafe', 'levure chimique']];

        // Sans unité
        yield ['1 pâte brisée', [1.0, 'unite', 'pâte brisée']];
        yield ['6 pommes', [6.0, 'unite', 'pommes']];
        yield ['2 œufs', [2.0, 'unite', 'œufs']];
        yield ['1 oignon', [1.0, 'unite', 'oignon']];
        yield ['3 pommes de terre moyennes', [3.0, 'unite', 'pommes de terre']];
        yield ['2 gros oignons', [2.0, 'unite', 'gros oignons']];
        yield ['2 oignons gros', [2.0, 'unite', 'oignons']];
        yield ['2 petites courgettes', [2.0, 'unite', 'petites courgettes']];
        yield ['1 pot de yaourt nature', [1.0, 'pot', 'yaourt nature']];
        yield ['150 g de petits pois', [150.0, 'gramme', 'petits pois']];
        yield ['1 c. à café de gros sel', [1.0, 'cuillere-a-cafe', 'gros sel']];
        yield ['4 citrons de taille moyenne à très grosse', [4.0, 'unite', 'citrons']];

        // Quantités écrites autrement
        yield ['1,5 kg de pommes de terre', [1.5, 'kilo', 'pommes de terre']];
        yield ['1/2 litre de lait', [0.5, 'litre', 'lait']];
        yield ['½ citron', [0.5, 'unite', 'citron']];
        yield ['1½ cuillère à café de sel', [1.5, 'cuillere-a-cafe', 'sel']];
        yield ['1 1/2 tasse à café de sucre', [1.5, 'tasse-a-cafe', 'sucre']];
        yield ['2 à 3 gousses d\'ail', [2.0, 'gousse', 'ail']];
        yield ['2 ou 3 gousses d\'ail', [2.0, 'gousse', 'ail']];
        yield ['3 à 2 gousses d\'ail', [2.0, 'gousse', 'ail']];
        yield ['2-3 gousses d\'ail', [2.0, 'gousse', 'ail']];
        yield ['2 - 3 gousses d\'ail', [2.0, 'gousse', 'ail']];
        yield ['une pincée de sel', [1.0, 'pincee', 'sel']];
        yield ['Une pincée de sel', [1.0, 'pincee', 'sel']];
        yield ['un peu de poivre', [1.0, 'unite', 'poivre']];
        yield ['quelques feuilles de basilic', [1.0, 'unite', 'feuilles de basilic']];
        yield ['un citron', [1.0, 'unite', 'citron']];
        yield ['deux oeufs', [2.0, 'unite', 'oeufs']];
        yield ['trois pommes', [3.0, 'unite', 'pommes']];
        yield ['quatre tranches de jambon', [4.0, 'tranche', 'jambon']];
        yield ['cinq gousses d\'ail', [5.0, 'gousse', 'ail']];
        yield ['six oeufs', [6.0, 'unite', 'oeufs']];
        yield ['sept feuilles de menthe', [7.0, 'unite', 'feuilles de menthe']];
        yield ['huit biscuits', [8.0, 'unite', 'biscuits']];
        yield ['neuf champignons', [9.0, 'unite', 'champignons']];
        yield ['dix olives', [10.0, 'unite', 'olives']];
        yield ['onze noix', [11.0, 'unite', 'noix']];
        yield ['douze escargots', [12.0, 'unite', 'escargots']];
        yield ['un demi citron', [0.5, 'unite', 'citron']];
        yield ['une demi cuillère à café de cannelle', [0.5, 'cuillere-a-cafe', 'cannelle']];
        yield ['environ 200 g de farine', [200.0, 'gramme', 'farine']];
        yield ['env. 2 oeufs', [2.0, 'unite', 'oeufs']];
        yield ['1.5 l de lait', [1.5, 'litre', 'lait']];

        // Sans quantité
        yield ['sel', [1.0, 'unite', 'sel']];
        yield ['Poivre du moulin', [1.0, 'unite', 'Poivre du moulin']];
        yield ['du beurre pour le moule', [1.0, 'unite', 'beurre']];
        yield ['de la crème fraîche', [1.0, 'unite', 'crème fraîche']];
        yield ["de l'huile d'olive pour la poêle", [1.0, 'unite', "huile d'olive"]];
        yield ['des petits pois', [1.0, 'unite', 'petits pois']];
        yield ['gousse d\'ail', [1.0, 'gousse', 'ail']];
        yield ['pincée de sel', [1.0, 'pincee', 'sel']];
        yield ['- sel', [1.0, 'unite', 'sel']];
        yield ['• 2 oeufs', [2.0, 'unite', 'oeufs']];

        // Les unités, leurs abréviations et leurs formes
        yield ['2 cuillères à soupe de sucre', [2.0, 'cuillere-a-soupe', 'sucre']];
        yield ['1 cuillère à soupe d\'huile', [1.0, 'cuillere-a-soupe', 'huile']];
        yield ['2 c. à s. de miel', [2.0, 'cuillere-a-soupe', 'miel']];
        yield ['2 c à s de miel', [2.0, 'cuillere-a-soupe', 'miel']];
        yield ['2 càs de miel', [2.0, 'cuillere-a-soupe', 'miel']];
        yield ['2 CS de miel', [2.0, 'cuillere-a-soupe', 'miel']];
        yield ['2 c.à.s de miel', [2.0, 'cuillere-a-soupe', 'miel']];
        yield ['2 cuillères de miel', [2.0, 'cuillere-a-soupe', 'miel']];
        yield ['2 cuillères à café de sel', [2.0, 'cuillere-a-cafe', 'sel']];
        yield ['2 c. à c. de sel', [2.0, 'cuillere-a-cafe', 'sel']];
        yield ['2 càc de sel', [2.0, 'cuillere-a-cafe', 'sel']];
        yield ['2 CC de sel', [2.0, 'cuillere-a-cafe', 'sel']];
        yield ['2 tasses à café de lait', [2.0, 'tasse-a-cafe', 'lait']];
        yield ['1 tasse de thé', [1.0, 'tasse-a-cafe', 'thé']];
        yield ['2 verres de lait', [2.0, 'verre', 'lait']];
        yield ['1 bol de riz', [1.0, 'bol', 'riz']];
        yield ['2 kg de tomates', [2.0, 'kilo', 'tomates']];
        yield ['2 kilos de tomates', [2.0, 'kilo', 'tomates']];
        yield ['500 gr de boeuf haché', [500.0, 'gramme', 'boeuf haché']];
        yield ['500 grammes de boeuf haché', [500.0, 'gramme', 'boeuf haché']];
        yield ['1 l de lait', [1.0, 'litre', 'lait']];
        yield ['2 litres de bouillon', [2.0, 'litre', 'bouillon']];
        yield ['20 cl de crème liquide', [20.0, 'centilitre', 'crème liquide']];
        yield ['250 ml de lait', [250.0, 'millilitre', 'lait']];
        yield ['2 sachets de sucre vanillé', [2.0, 'sachet', 'sucre vanillé']];
        yield ['1 paquet de spaghetti', [1.0, 'paquet', 'spaghetti']];
        yield ['1 boîte de tomates pelées', [1.0, 'boite', 'tomates pelées']];
        yield ['2 boites de thon', [2.0, 'boite', 'thon']];
        yield ['1 bouteille de vin blanc', [1.0, 'bouteille', 'vin blanc']];
        yield ['1 brique de crème', [1.0, 'brique', 'crème']];
        yield ['1 botte de persil', [1.0, 'botte', 'persil']];
        yield ['4 tranches de lard', [4.0, 'tranche', 'lard']];
        yield ['2 plaquettes de beurre', [2.0, 'plaquette', 'beurre']];
        yield ['1 tablette de chocolat noir', [1.0, 'tablette', 'chocolat noir']];
        yield ['2 cubes de bouillon', [2.0, 'cube', 'bouillon']];
        yield ['1 conserve de maïs', [1.0, 'conserve', 'maïs']];
        yield ['1 flacon d\'arôme vanille', [1.0, 'flacon', 'arôme vanille']];
        yield ['3 pincées de piment', [3.0, 'pincee', 'piment']];
        yield ['1 PINCÉE DE SEL', [1.0, 'pincee', 'SEL']];

        // Pièges : unité collée au nombre, virgule décimale, majuscules, texte autour
        yield ['250g de farine', [250.0, 'gramme', 'farine']];
        yield ['5cl de rhum', [5.0, 'centilitre', 'rhum']];
        yield ['1l de lait', [1.0, 'litre', 'lait']];
        yield ['1,5kg de farine', [1.5, 'kilo', 'farine']];
        yield ['250 G DE FARINE', [250.0, 'gramme', 'FARINE']];
        yield ['0,5 l de lait', [0.5, 'litre', 'lait']];
        yield ['  250   g   de   farine  ', [250.0, 'gramme', 'farine']];
        yield ["250\u{00A0}g de farine", [250.0, 'gramme', 'farine']];
        yield ["250\u{202F}g\u{00A0}de\u{00A0}farine", [250.0, 'gramme', 'farine']];
        yield ["250 g\nde farine\r\n", [250.0, 'gramme', 'farine']];
        yield ["1 cuillère à soupe d&#39;huile d&#39;olive", [1.0, 'cuillere-a-soupe', "huile d'olive"]];
        yield ['2 jaunes d&rsquo;oeuf', [2.0, 'unite', "jaunes d'oeuf"]];
        yield ["5 cl d’eau", [5.0, 'centilitre', 'eau']];
        yield ['200&nbsp;g de farine', [200.0, 'gramme', 'farine']];
        yield ['200 g de farine (type 55)', [200.0, 'gramme', 'farine']];
        yield ['200 g de farine (type 55', [200.0, 'gramme', 'farine']];
        yield ['200 g de (belle) farine', [200.0, 'gramme', 'farine']];
        yield ['2 oeufs, battus', [2.0, 'unite', 'oeufs']];
        yield ['200 g de farine; tamisée', [200.0, 'gramme', 'farine']];
        yield ['2 x 200 g de crème', [2.0, 'unite', '200 g de crème']];
        yield ['2x200 g de crème', [2.0, 'unite', '200 g de crème']];
        yield ['2 × 200 g de crème', [2.0, 'unite', '200 g de crème']];
        yield ['2 cups of flour', [2.0, 'unite', 'cups of flour']];
        yield ['1 tablespoon of sugar', [1.0, 'unite', 'tablespoon of sugar']];
        yield ['salt', [1.0, 'unite', 'salt']];
        yield ['250 g', null];
        yield ['2 cuillères à soupe', null];
        yield ['1/2', null];

        // Quantités hors des bornes ramenées à 1
        yield ['0 g de sel', [1.0, 'gramme', 'sel']];
        yield ['1/0 citron', [1.0, 'unite', 'citron']];
        yield ['2000000 g de sel', [1.0, 'gramme', 'sel']];
        yield ['99999999999999999999 g de sel', [1.0, 'unite', '99999999999999999999 g de sel']];
        yield ['1000000 g de sel', [1000000.0, 'gramme', 'sel']];

        // Illisibles
        yield ['', null];
        yield ['   ', null];
        yield ['.', null];
        yield ['—', null];
        yield ['-', null];
        yield ['(type 55)', null];
        yield ['...', null];
        yield ['/', null];
    }

    /**
     * @param array{float, string, string}|null $expected
     */
    #[DataProvider('lines')]
    public function testParse(string $line, ?array $expected): void
    {
        // When
        $parsed = MarmitonIngredientLineParser::parse($line);

        // Then
        if ($expected === null) {
            $this->assertNull($parsed, $line);

            return;
        }
        $this->assertNotNull($parsed, $line);
        $this->assertSame($expected, [$parsed->quantity, $parsed->unitSlug, $parsed->name], $line);
    }

    public function testTheDataSetIsBigEnough(): void
    {
        // Then : le ticket demande au moins 80 lignes
        $this->assertGreaterThanOrEqual(80, iterator_count(self::cases()));
    }

    public function testTheRawLineIsKeptAndBounded(): void
    {
        // When
        $parsed = MarmitonIngredientLineParser::parse('  250 g de farine ');
        $long = MarmitonIngredientLineParser::parse('1 ' . str_repeat('a', 500));

        // Then
        $this->assertSame('250 g de farine', $parsed->raw);
        $this->assertSame(255, mb_strlen($long->raw));
        $this->assertSame(100, mb_strlen($long->name));
    }

    public function testTheNameIsAtMostAHundredCharacters(): void
    {
        // When
        $parsed = MarmitonIngredientLineParser::parse('3 ' . str_repeat('é', 400));

        // Then : les caractères sont comptés, pas les octets
        $this->assertSame(100, mb_strlen($parsed->name));
        $this->assertSame(3.0, $parsed->quantity);
    }

    public function testInvalidUtf8NeverThrows(): void
    {
        // When
        $parsed = MarmitonIngredientLineParser::parse("250 g de fa\xB1\xFFrine");

        // Then
        $this->assertNotNull($parsed);
        $this->assertSame(250.0, $parsed->quantity);
        $this->assertTrue(mb_check_encoding($parsed->name, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathologicalLines(): iterable
    {
        yield 'dix mille a' => [str_repeat('a', 10_000)];
        yield 'dix mille mots' => [str_repeat('a ', 5_000)];
        yield 'dix mille espaces puis un mot' => [str_repeat(' ', 10_000) . 'sel'];
        yield 'dix mille parenthèses ouvrantes' => [str_repeat('(', 10_000)];
        yield 'dix mille parenthèses fermantes' => ['2 ' . str_repeat(')', 10_000)];
        yield 'dix mille parenthèses imbriquées' => [str_repeat('(a', 5_000)];
        yield 'dix mille chiffres' => [str_repeat('9', 10_000) . ' g de sel'];
        yield 'dix mille uns' => [str_repeat('1 ', 5_000)];
        yield 'dix mille fractions' => [str_repeat('1/2 ', 2_500)];
        yield 'dix mille chiffres puis lettres' => [str_repeat('1a', 5_000)];
        yield 'dix mille tirets' => [str_repeat('-', 10_000) . 'a'];
        yield 'dix mille virgules' => ['2 oeufs' . str_repeat(',', 10_000)];
        yield 'dix mille de' => [str_repeat('de ', 3_400)];
        yield 'dix mille pour' => [str_repeat('pour ', 2_000)];
        yield 'dix mille de taille' => ['1 pomme' . str_repeat(' de taille', 1_000)];
        yield 'dix mille moyennes' => ['1 pomme' . str_repeat(' moyenne', 1_300)];
        yield 'dix mille espaces insécables' => ['2' . str_repeat("\u{00A0}", 10_000) . 'oeufs'];
        yield 'dix mille entités' => [str_repeat('&#39;', 2_000)];
        yield 'dix mille esperluettes' => [str_repeat('&', 10_000)];
        yield 'dix mille c.' => [str_repeat('c. à ', 2_000)];
        yield 'dix mille fois la virgule décimale' => [str_repeat('1,', 5_000)];
        yield 'dix mille x' => ['2' . str_repeat('x2', 5_000)];
    }

    #[DataProvider('pathologicalLines')]
    public function testPathologicalLinesAreFastAndNeverThrow(string $line): void
    {
        // Given : un premier appel qui charge ce qu'il faut (le test mesure la ligne, pas le démarrage)
        MarmitonIngredientLineParser::parse('250 g de farine');

        // When
        $start = hrtime(true);
        $parsed = MarmitonIngredientLineParser::parse($line);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        // Then : borné en temps (le ticket demande moins de 50 ms) et bornée en taille
        $this->assertLessThan(50, $elapsedMs, 'trop lent');
        if ($parsed !== null) {
            $this->assertLessThanOrEqual(100, mb_strlen($parsed->name));
            $this->assertGreaterThan(0, $parsed->quantity);
        }
    }
}
