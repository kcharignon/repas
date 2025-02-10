<?php

namespace Repas\Tests\Shared;


use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Repas\Shared\Domain\Tool\StringTool;

class StringToolTest extends TestCase
{

    public function stringToolSlugifyDataProvider(): array
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

    /**
     * @dataProvider stringToolSlugifyDataProvider
     */
    public function testSlugify(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::slugify($initial);

        //Assert
        $this->assertSame($expected, $actual);
    }

    /**
     * @dataProvider stringToolSlugifyDataProvider
     */
    public function testSlugifyTwice(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::slugify(StringTool::slugify($initial));

        //Assert
        $this->assertSame($expected, $actual);
    }

    public function upperCaseFirstDataProvider(): array
    {
        return [
            "simple" => ["simple", "Simple"],
            "œuf" => ["œuf", "Œuf"],
            "accent" => ["épice", "Épice"],
            "espace" => [" espace", " espace"],
        ];
    }

    /**
     * @dataProvider upperCaseFirstDataProvider
     */
    public function testUpperCaseFirst(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::upperCaseFirst($initial);

        //Assert
        $this->assertSame($expected, $actual);
    }

    public function generateRandomStringDataProvider(): array
    {
        return [
            'Length 10 with default alphabet' => [10, StringTool::ALPHABET_ALPHA_NUMERIC, 'abcdefghijklmnopqrstuvwxyz0123456789'],
            'Length 15 with numeric alphabet' => [15, StringTool::ALPHABET_NUMERIC, '0123456789'],
            'Length 20 with hex alphabet' => [20, StringTool::ALPHABET_HEXA, '0123456789abcdef'],
            'Length 8 with custom alphabet' => [8, 'ABCD1234', 'ABCD1234'],
        ];
    }

    /**
     * @dataProvider generateRandomStringDataProvider
     * @throws RandomException
     */
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
