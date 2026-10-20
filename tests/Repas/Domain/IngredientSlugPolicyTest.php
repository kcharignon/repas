<?php

namespace Repas\Tests\Repas\Domain;


use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;

/**
 * Le slug d'un ingrédient adopté (ADR 0028 décision 1, `docs/ingredients/migration.md` § 5) : le motif des ingrédients personnels,
 * puis `-2`, `-3`… jusqu'au premier libre. La fonction est pure : la disponibilité d'un slug est donnée par l'appelant.
 */
class IngredientSlugPolicyTest extends TestCase
{
    private const string USER = '3f2c1a9e-1111-4222-8333-444455556666';

    /**
     * @param list<string> $taken
     */
    #[DataProvider('cases')]
    public function testForAdoption(string $name, array $taken, string $expected): void
    {
        // When
        $slug = new IngredientSlugPolicy()->forAdoption($name, self::USER, $taken);

        // Then
        $this->assertSame($expected, $slug);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function cases(): iterable
    {
        yield 'libre' => ['Tomate', [], 'tomate' . self::USER];
        yield 'le commun lui-même et ceux des autres ne comptent pas' => ['Tomate', ['tomate', 'tomate' . 'other-user'], 'tomate' . self::USER];
        yield 'pris' => ['Tomate', ['tomate' . self::USER], 'tomate-2' . self::USER];
        yield 'pris deux fois' => ['Tomate', ['tomate' . self::USER, 'tomate-2' . self::USER], 'tomate-3' . self::USER];
        yield 'le trou est comblé par le premier libre' => ['Tomate', ['tomate' . self::USER, 'tomate-3' . self::USER], 'tomate-2' . self::USER];
        yield 'un nom avec accents et espaces' => ['Pâte feuilletée', [], 'pate-feuilletee' . self::USER];
        yield 'un nom avec accents, pris' => ['Pâte feuilletée', ['pate-feuilletee' . self::USER], 'pate-feuilletee-2' . self::USER];
        // Le slug que `Ingredient::create()` produit pour ce nom : le symbole final sépare le nom de l'identifiant
        yield 'un nom qui finit par un symbole' => ['couche (taille 4)', [], 'couche-taille-4-' . self::USER];
        yield 'un nom qui finit par un symbole, pris' => ['couche (taille 4)', ['couche-taille-4-' . self::USER], 'couche-taille-4-2' . self::USER];
    }

    public function testTheTakenSlugsMayBeAskedThroughAFunction(): void
    {
        // Given
        $asked = [];
        $taken = static function (string $slug) use (&$asked): bool {
            $asked[] = $slug;

            return in_array($slug, ['tomate' . self::USER, 'tomate-2' . self::USER], true);
        };

        // When
        $slug = new IngredientSlugPolicy()->forAdoption('Tomate', self::USER, $taken);

        // Then : une question par candidat, dans l'ordre, et on s'arrête au premier libre
        $this->assertSame('tomate-3' . self::USER, $slug);
        $this->assertSame(['tomate' . self::USER, 'tomate-2' . self::USER, 'tomate-3' . self::USER], $asked);
    }

    #[DataProvider('names')]
    public function testTheFirstCandidateIsExactlyTheSlugOfAnIngredientCreatedWithTheSameName(string $name): void
    {
        // Given
        $created = Ingredient::create(
            $name,
            '',
            new DepartmentBuilder()->isBakery()->build(),
            new UnitBuilder()->isGramme()->build(),
            new UnitBuilder()->isGramme()->build(),
            self::USER,
        );

        // When
        $slug = new IngredientSlugPolicy()->forAdoption($name, self::USER, []);

        // Then : aucune règle de slug nouvelle, un ingrédient adopté ressemble à un ingrédient créé
        $this->assertSame($created->getSlug(), $slug);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        foreach (['Tomate', 'Pâte feuilletée', 'Œufs', 'couche (taille 4)', "Crème d'amande", 'Jus d\'orange 100 %', 'Poivre noir moulu'] as $name) {
            yield $name => [$name];
        }
    }
}
