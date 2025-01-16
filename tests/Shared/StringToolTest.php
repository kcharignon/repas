<?php

namespace Repas\Tests\Shared;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Infrastructure\DataFixture\RecipeFixture;
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
}
