<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Service\ListDestinationResolver;

/**
 * La règle de l'ADR 0036 (décision 3), pure : le choix du produit, sinon le type de son rayon, sinon la liste principale (`null`).
 */
class ListDestinationResolverTest extends TestCase
{
    private const array TYPES = ['butcher', 'market'];

    /**
     * @param list<string> $existingTypeIds
     */
    #[DataProvider('cases')]
    public function testResolves(?string $choice, ?string $departmentTypeId, array $existingTypeIds, ?string $expected): void
    {
        // When
        $destination = new ListDestinationResolver()->resolve($choice, $departmentTypeId, $existingTypeIds);

        // Then
        $this->assertSame($expected, $destination);
    }

    /**
     * @return iterable<string, array{?string, ?string, list<string>, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'aucun choix, rayon sans type : la liste principale' => [null, null, self::TYPES, null];
        yield 'aucun type du tout : la liste principale' => [null, null, [], null];
        yield 'aucun choix, rayon dans un type : le type du rayon' => [null, 'butcher', self::TYPES, 'butcher'];
        yield 'le choix du produit passe avant le type du rayon' => ['market', 'butcher', self::TYPES, 'market'];
        yield 'le choix du produit, rayon sans type' => ['market', null, self::TYPES, 'market'];
        yield '« liste principale » choisie, rayon dans un type : la liste principale' => ['main', 'butcher', self::TYPES, null];
        yield '« liste principale » choisie, rayon sans type' => ['main', null, self::TYPES, null];
        yield '« liste principale » ne dépend pas des types qui existent' => ['main', 'butcher', [], null];
        yield 'le choix désigne un type supprimé : on retombe sur le type du rayon' => ['gone', 'butcher', self::TYPES, 'butcher'];
        yield 'le choix désigne un type supprimé, rayon sans type : la liste principale' => ['gone', null, self::TYPES, null];
        yield 'le type du rayon est supprimé : la liste principale' => [null, 'gone', self::TYPES, null];
        yield 'le choix ET le type du rayon sont supprimés : la liste principale' => ['gone', 'also-gone', self::TYPES, null];
        yield 'le choix est supprimé, le type du rayon existe encore' => ['gone', 'market', self::TYPES, 'market'];
    }
}
