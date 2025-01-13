<?php

namespace Repas\Tests\Shared;


use PHPUnit\Framework\TestCase;
use Repas\Shared\Domain\Tool\StringTool;

class StringToolTest extends TestCase
{

    public function stringToolSlugifyDataProvider(): array
    {
        return [
            "Viande Surgelée" => ["Viande Surgelée", "viande-surgelee"],
            "Soupe 2 pâte" => ["Soupe 2 pâte", "soupe-2-pate"],
            "œuf" => ["œuf", "oeuf"],
        ];
    }

    /**
     * @dataProvider stringToolSlugifyDataProvider
     */
    public function testStringTool(string $initial, string $expected): void
    {
        //Act
        $actual = StringTool::slugify($initial);

        //Assert
        $this->assertSame($expected, $actual);
    }
}
