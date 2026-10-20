<?php

namespace Repas\Tests\Drive\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\SearchQuery;

class SearchQueryTest extends TestCase
{
    #[DataProvider('acceptedTexts')]
    public function testAcceptsAndNormalizesAText(string $text, string $expected): void
    {
        // When
        $searchQuery = SearchQuery::of($text);

        // Then
        $this->assertSame($expected, $searchQuery->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedTexts(): iterable
    {
        yield 'un mot' => ['cruesli', 'cruesli'];
        yield 'deux caractères, la borne basse' => ['ab', 'ab'];
        yield 'quatre-vingts caractères, la borne haute' => [str_repeat('a', 80), str_repeat('a', 80)];
        yield 'accents, casse et ponctuation gardés' => ['Céréales Coussins Choco-Noisette 500 g', 'Céréales Coussins Choco-Noisette 500 g'];
        yield 'espaces de bord retirées' => ['  céréales coussins  ', 'céréales coussins'];
        yield 'espaces internes réduites' => ["céréales   \u{00A0}coussins", 'céréales coussins'];
        yield 'espace insécable fine entre deux mots' => ["lait\u{202F}demi", 'lait demi'];
        yield 'espace insécable aux bords' => ["\u{00A0}lait\u{00A0}", 'lait'];
        yield 'espace ordinaire de largeur de cadratin' => ["lait\u{2003}demi", 'lait demi'];
        yield 'quatre-vingts caractères après réduction des espaces' => ['a' . str_repeat(' ', 30) . str_repeat('b', 78), 'a ' . str_repeat('b', 78)];
        yield 'emoji seul compté pour un caractère' => ["🥚🥚", "🥚🥚"];
        yield 'quatre-vingts emoji' => [str_repeat("🥚", 80), str_repeat("🥚", 80)];
        yield 'accent combinant' => ["e\u{0301}ps", "e\u{0301}ps"];
    }

    #[DataProvider('refusedTexts')]
    public function testRefusesAnythingElse(string $text): void
    {
        // Then
        $this->expectExceptionObject(DriveException::searchQueryInvalid());

        // When
        SearchQuery::of($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTexts(): iterable
    {
        yield 'vide' => [''];
        yield 'des espaces seulement' => ['    '];
        yield 'des espaces insécables seulement' => ["\u{00A0}\u{202F}\u{00A0}"];
        yield 'un seul caractère' => ['a'];
        yield 'un seul caractère entouré d\'espaces' => ['  a  '];
        yield 'quatre-vingt-un caractères' => [str_repeat('a', 81)];
        yield 'quatre-vingt-un emoji' => [str_repeat("🥚", 81)];
        yield 'trop long même si les espaces sont réduites' => [str_repeat('a', 40) . ' ' . str_repeat('b', 40)];
        yield 'saut de ligne' => ["céréales\ncoussins"];
        yield 'saut de ligne final' => ["céréales coussins\n"];
        yield 'retour chariot' => ["céréales\rcoussins"];
        yield 'tabulation' => ["céréales\tcoussins"];
        yield 'caractère nul' => ["céréales\0coussins"];
        yield 'échappement' => ["céréales\u{001B}[31m"];
        yield 'contrôle C1' => ["céréales\u{0085}coussins"];
        yield 'espace de largeur nulle' => ["céréales\u{200B}coussins"];
        yield 'marque de sens de lecture' => ["céréales\u{200F}coussins"];
        yield 'inversion du sens de lecture' => ["\u{202E}sniffuor"];
        yield 'trait d\'union conditionnel' => ["cé\u{00AD}réales"];
        yield 'marque d\'ordre des octets' => ["\u{FEFF}céréales"];
        yield 'séparateur de ligne' => ["céréales\u{2028}coussins"];
        yield 'séparateur de paragraphe' => ["céréales\u{2029}coussins"];
        yield 'caractère de tag' => ["céréales\u{E0041}"];
        yield 'UTF-8 invalide' => ["c\xC3\x28réales"];
    }

    public function testLoadRestoresTheStoredValueAsIs(): void
    {
        // When : une valeur déjà normalisée à l'écriture n'est pas recontrôlée
        $searchQuery = SearchQuery::load('céréales coussins');

        // Then
        $this->assertSame('céréales coussins', $searchQuery->value);
    }
}
