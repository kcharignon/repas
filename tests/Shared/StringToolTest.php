<?php

namespace Repas\Tests\Shared;


use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Repas\Shared\Domain\Tool\StringTool;

class StringToolTest extends TestCase
{

    public static function stringToolSlugifyDataProvider(): array
    {
        return [
            "Caractères Spéciaux" => ["& @ œ '", "et-at-oe"],
            "Caractères espacement" => ["start'end", "start-end"],
            "Majuscules" => ["ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"],
            "Minuscules" => ["abcdefghijklmnopqrstuvwxyz", "abcdefghijklmnopqrstuvwxyz"],
            "Chiffre" => ["0123456789", "0123456789"],
            "Accents minuscules" => ["âàéèêëîïôöûüç", "aaeeeeiioouuc"],
            "Accents majuscules" => ["ÂÀÉÈÊËÎÏÔÖÛÜÇ", "aaeeeeiioouuc"],
            "Parentheses" => ["avec des (paretnhese)", "avec-des-paretnhese"],
        ];
    }

    #[DataProvider('stringToolSlugifyDataProvider')]
    public function testSlugify(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::slugify($initial);

        //Assert
        $this->assertSame($expected, $actual);
    }

    #[DataProvider('stringToolSlugifyDataProvider')]
    public function testSlugifyTwice(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::slugify(StringTool::slugify($initial));

        //Assert
        $this->assertSame($expected, $actual);
    }

    public static function upperCaseFirstDataProvider(): array
    {
        return [
            "simple" => ["simple", "Simple"],
            "œuf" => ["œuf", "Œuf"],
            "accent" => ["épice", "Épice"],
            "espace" => [" espace", " espace"],
        ];
    }

    #[DataProvider('upperCaseFirstDataProvider')]
    public function testUpperCaseFirst(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::upperCaseFirst($initial);

        //Assert
        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function capitalizeFirstDataProvider(): array
    {
        return [
            'minuscules' => ['pâtes carbonara', 'Pâtes carbonara'],
            'un mot' => ['farine', 'Farine'],
            'la suite est conservée' => ['de la farine T55', 'De la farine T55'],
            'la suite en majuscules est conservée' => ['lait de COCO', 'Lait de COCO'],
            'majuscule déjà là' => ['Pâtes carbonara', 'Pâtes carbonara'],
            'accent minuscule' => ['éclair', 'Éclair'],
            'accent majuscule déjà là' => ['Éclair', 'Éclair'],
            'ligature minuscule' => ['œufs', 'Œufs'],
            'ligature majuscule déjà là' => ['Œufs', 'Œufs'],
            'ligature de bœuf en cours de mot' => ['bœuf', 'Bœuf'],
            'ligature typographique' => ["\u{FB01}let", 'Filet'],
            'e + accent combinant' => ["e\u{0301}clair", "E\u{0301}clair"],
            'apostrophe droite en tête' => ["'sel", "'sel"],
            'apostrophe typographique en tête' => ["\u{2019}sel", "\u{2019}sel"],
            'lettre puis apostrophe' => ["l\u{2019}ail", "L\u{2019}ail"],
            'chiffre en tête' => ['00 farine', '00 farine'],
            'parenthèse en tête' => ['(bio) tomate', '(bio) tomate'],
            'tiret en tête' => ['- sel', '- sel'],
            'chaîne vide' => ['', ''],
            'un seul espace' => [' ', ' '],
            'espaces de tête conservés, première lettre en majuscule' => ['  espace', '  Espace'],
            'espace insécable en tête' => ["\u{00A0}abc", "\u{00A0}Abc"],
            'espaces puis chiffre' => ['  3 fromages', '  3 fromages'],
            'UTF-8 invalide rendu tel quel' => ["\xff\xfe", "\xff\xfe"],
            'saut de ligne dans la suite' => ["a\nb", "A\nb"],
        ];
    }

    #[DataProvider('capitalizeFirstDataProvider')]
    public function testCapitalizeFirst(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::capitalizeFirst($initial);

        //Assert
        $this->assertSame($expected, $actual);
    }

    #[DataProvider('capitalizeFirstDataProvider')]
    public function testCapitalizeFirstTwiceChangesNothing(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::capitalizeFirst(StringTool::capitalizeFirst($initial));

        //Assert
        $this->assertSame($expected, $actual);
    }

    public function testCapitalizeFirstNeverChangesATextThatAlreadyStartsWithAnUppercaseLetter(): void
    {
        // Given : toutes les lettres majuscules d'Unicode (plan multilingue de base et plan 1), suivies d'une suite quelconque
        $checked = 0;
        foreach (array_merge(range(0, 0xD7FF), range(0xE000, 0x1FFFF)) as $codePoint) {
            $character = mb_chr($codePoint, 'UTF-8');
            if ($character === false || preg_match('/^\p{Lu}$/u', $character) !== 1) {
                continue;
            }
            $text = $character . 'ou la suite DE la casse';

            // When / Then
            $this->assertSame($text, StringTool::capitalizeFirst($text), sprintf('U+%04X', $codePoint));
            $checked++;
        }
        $this->assertGreaterThan(1000, $checked, 'le contrôle doit parcourir les majuscules');
    }

    public function testCapitalizeFirstIsIdempotentForEveryFirstCharacterAndLeavesTheRestAlone(): void
    {
        // Given : chaque point de code en tête d'un texte dont la suite est connue
        foreach (array_merge(range(0, 0xD7FF), range(0xE000, 0x1FFFF)) as $codePoint) {
            $character = mb_chr($codePoint, 'UTF-8');
            if ($character === false) {
                continue;
            }
            $once = StringTool::capitalizeFirst($character . ' suite eN mIxTe');

            // When / Then : une seconde passe ne change rien, la suite après le premier caractère est intacte
            $this->assertSame($once, StringTool::capitalizeFirst($once), sprintf('U+%04X', $codePoint));
            // (une espace en tête est conservée et c'est la lettre qui la suit qui passe en majuscule : le contrôle de la suite ne la vise pas)
            if (preg_match('/^\s$/u', $character) !== 1) {
                $this->assertStringEndsWith(' suite eN mIxTe', $once, sprintf('U+%04X', $codePoint));
            }
        }
    }

    public function testCapitalizeFirstDoesNotChangeTheSlug(): void
    {
        // Then : l'identité d'un ingrédient (son slug) et son nom normalisé ne dépendent pas de la casse
        foreach (['pâtes carbonara', 'œufs à la coque', 'éclair', 'de la farine T55', 'ßtraße'] as $name) {
            $this->assertSame(StringTool::slugify($name), StringTool::slugify(StringTool::capitalizeFirst($name)), $name);
        }
    }

    public static function generateRandomStringDataProvider(): array
    {
        return [
            'Length 10 with default alphabet' => [10, StringTool::ALPHABET_ALPHA_NUMERIC, 'abcdefghijklmnopqrstuvwxyz0123456789'],
            'Length 15 with numeric alphabet' => [15, StringTool::ALPHABET_NUMERIC, '0123456789'],
            'Length 20 with hex alphabet' => [20, StringTool::ALPHABET_HEXA, '0123456789abcdef'],
            'Length 8 with custom alphabet' => [8, 'ABCD1234', 'ABCD1234'],
        ];
    }

    #[DataProvider('generateRandomStringDataProvider')]
    public function testGenerateRandomString(int $length, string|int $alphabet, string $expectedAlphabet): void
    {
        // Act
        $randomString = StringTool::generateRandomString($length, $alphabet);

        // Assert
        $this->assertSame($length, strlen($randomString), 'Generated string length does not match the expected length.');
        // Vérifie que chaque caractère appartient à l'alphabet attendu
        foreach (str_split($randomString) as $char) {
            $this->assertStringContainsString($char, $expectedAlphabet, "Character '{$char}' is not in the expected alphabet.");
        }
    }

    /**
     * @throws RandomException
     */
    public function testGenerateRandomStringThrowsExceptionForInvalidLength(): void
    {
        //Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Length must be a positive integer.');

        //Act
        StringTool::generateRandomString(0);
    }

    /**
     * @throws RandomException
     */
    public function testGenerateRandomStringThrowsExceptionForInvalidAlphabet(): void
    {
        //Assert
        $this->expectExceptionObject(new InvalidArgumentException('Alphabet must be a non-empty string.'));

        //Act
        StringTool::generateRandomString(10, '');
    }

    /**
     * @throws RandomException
     */
    public function testGenerateRandomStringThrowsExceptionForInvalidAlphabetIdentifier(): void
    {
        //Assert
        $this->expectExceptionObject(new InvalidArgumentException('Invalid alphabet identifier.'));

        //Act
        StringTool::generateRandomString(10, 999); // Identifiant inconnu
    }
}
