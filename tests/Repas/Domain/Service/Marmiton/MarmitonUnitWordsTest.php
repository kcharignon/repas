<?php

namespace Repas\Tests\Repas\Domain\Service\Marmiton;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\Marmiton\MarmitonUnitWords;

/**
 * Le tableau des mots d'unité de Marmiton (ADR 0037 décision 7) : chaque unité qu'il désigne existe dans les données de référence.
 */
class MarmitonUnitWordsTest extends TestCase
{
    public function testEveryUnitOfTheTableExistsInTheReferenceData(): void
    {
        // Given
        $referenceSlugs = $this->referenceUnitSlugs();

        // When / Then : `unit.json` (ou la seule unité ajoutée par ce chantier, `pincee`)
        foreach (MarmitonUnitWords::WORDS as $word => $slug) {
            $this->assertTrue(
                in_array($slug, $referenceSlugs, true) || $slug === MarmitonUnitWords::PINCEE,
                "« $word » désigne l'unité « $slug », qui n'est pas une unité de référence",
            );
        }
    }

    public function testPinceeIsAReferenceUnit(): void
    {
        // Then : la pincée est dans les données de développement (la migration est faite en MAR-003)
        $this->assertContains(MarmitonUnitWords::PINCEE, $this->referenceUnitSlugs());
    }

    public function testEveryKeyIsInTheNormalisedForm(): void
    {
        // When / Then : une clé est un mot normalisé : elle se retrouve en normalisant sa propre écriture
        foreach (array_keys(MarmitonUnitWords::WORDS) as $key) {
            $this->assertSame($key, implode('-', array_map(MarmitonUnitWords::normalise(...), explode('-', $key))), $key);
        }
    }

    public function testEveryUnitOfTheReferenceDataThatMarmitonUsesIsReachable(): void
    {
        // Given : les unités du ticket, avec au moins un mot chacune
        $reached = array_unique(array_values(MarmitonUnitWords::WORDS));

        // Then
        foreach (['gramme', 'kilo', 'litre', 'centilitre', 'millilitre', 'cuillere-a-soupe', 'cuillere-a-cafe', 'tasse-a-cafe', 'verre', 'bol',
                     'plaquette', 'tablette', 'tranche', 'paquet', 'boite', 'sachet', 'bouteille', 'conserve', 'cube', 'brique', 'pot',
                     'gousse', 'flacon', 'botte', 'pincee'] as $slug) {
            $this->assertContains($slug, $reached, $slug);
        }
    }

    /**
     * @return iterable<string, array{list<string>, string|null, int}>
     */
    public static function phrases(): iterable
    {
        yield 'un mot' => [['g', 'de', 'farine'], 'gramme', 1];
        yield 'un pluriel' => [['Cuillères', 'à', 'soupe', 'de'], 'cuillere-a-soupe', 3];
        yield 'la forme la plus longue l\'emporte' => [['tasse', 'à', 'café', 'de'], 'tasse-a-cafe', 3];
        yield 'la forme courte' => [['tasses', 'de', 'thé'], 'tasse-a-cafe', 1];
        yield 'abrégé avec points' => [['c.', 'à', 's.', 'de'], 'cuillere-a-soupe', 3];
        yield 'abrégé soudé' => [['càc'], 'cuillere-a-cafe', 1];
        yield 'avec accent' => [['pincées'], 'pincee', 1];
        yield 'pas une unité' => [['citrons', 'de', 'taille'], null, 0];
        yield 'rien' => [[], null, 0];
        yield 'un mot trop long' => [[str_repeat('g', 50)], null, 0];
        yield 'seulement de la ponctuation' => [['.', 'g'], null, 0];
    }

    /**
     * @param list<string> $words
     */
    #[DataProvider('phrases')]
    public function testMatch(array $words, ?string $slug, int $count): void
    {
        // When
        $match = MarmitonUnitWords::match($words);

        // Then
        $this->assertSame($slug === null ? null : ['slug' => $slug, 'words' => $count], $match);
    }

    /**
     * @return list<string>
     */
    private function referenceUnitSlugs(): array
    {
        $items = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/src/Repas/Infrastructure/DataFixture/Data/unit.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return array_map(static fn(array $item): string => $item['slug'], $items);
    }
}
