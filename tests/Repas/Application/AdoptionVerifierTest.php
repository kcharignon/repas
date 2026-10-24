<?php

namespace Repas\Tests\Repas\Application;


use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\AggregateSnapshot;
use Repas\Shared\Application\Dto\DriveChoiceFingerprint;

/**
 * Ce que la vérification de la migration d'adoption (ING-003) accepte et refuse : chaque défaut de `migration.md` § 8 est vu, et un
 * repointage correct n'en signale aucun.
 */
class AdoptionVerifierTest extends TestCase
{
    private AdoptionVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AdoptionVerifier();
    }

    public function testACorrectRepointingHasNoDiscrepancy(): void
    {
        // Given : la recette r1 et la liste l1 référençaient un ingrédient surveillé (version +1), la recette r2 non (version inchangée)
        $before = $this->snapshot(recipes: ['r1' => new AggregateSnapshot(3, 'h1', '', true), 'r2' => new AggregateSnapshot(1, 'h2')], lists: ['l1' => new AggregateSnapshot(5, 'h3', 'SENT|date', true, true)], watched: 4);
        $after = $this->snapshot(recipes: ['r1' => new AggregateSnapshot(4, 'h1'), 'r2' => new AggregateSnapshot(1, 'h2')], lists: ['l1' => new AggregateSnapshot(6, 'h3', 'SENT|date', false, true)], watched: 0);

        // When / Then
        $this->assertSame([], $this->verifier->discrepancies($before, $after, $this->drive(['c1' => 'beurre']), $this->drive(['c1' => 'beurre-copie']), ['beurre' => 'beurre-copie']));
    }

    public function testEveryKindOfDiscrepancyIsNamedWithoutAnyIdentifier(): void
    {
        // Given : un peu de tout qui va mal
        $before = $this->snapshot(recipes: ['r1' => new AggregateSnapshot(3, 'h1', '', true)], lists: ['l1' => new AggregateSnapshot(5, 'h3', 'SENT|date', true, true)], watched: 3);
        $after = new AdoptionSnapshot(
            ['recipe_row' => 9, 'shopping_list_row' => 2, 'shopping_list_ingredient' => 1],
            ['recipe_row' => '11.5', 'shopping_list_row' => '3', 'shopping_list_ingredient' => '1'],
            ['recipe_row' => 1, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0],
            ['r1' => new AggregateSnapshot(3, 'autre')],
            ['l1' => new AggregateSnapshot(5, 'h3', 'ACTIVE|', false)],
            '{"changed":true}',
        );

        // When
        $problems = $this->verifier->discrepancies($before, $after, $this->drive(['c1' => 'beurre', 'c2' => 'sel']), new DriveChoiceFingerprint(1, 5, '2.000', 'autre-hash', ['c1' => 'beurre'], ['c1' => 2]), ['beurre' => 'beurre-copie']);

        // Then
        $text = implode("\n", $problems);
        foreach ([
            'recipe_row : 10 ligne(s) avant, 9 après',
            'recipe_row : la somme des quantités a changé',
            '1 ligne(s) référencent encore un ingrédient qui devait être repointé',
            'recettes : le contenu de 1 a changé',
            'recettes : la version de 1 n\'est pas celle attendue',
            'listes : le statut ou la date d\'envoi de 1 a changé',
            'listes : la version de 1 n\'est pas celle attendue',
            'les statistiques du compte ont changé',
            'choix du drive : 2 avant, 1 après',
            'produits des choix du drive : 6 avant, 5 après',
            'contenances mémorisées du drive',
            'le contenu des choix du drive',
            "choix du drive : 2 ne portent pas l'ingrédient attendu",
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}/', $text);
    }

    public function testAVanishedOrNewAggregateIsSeen(): void
    {
        $before = $this->snapshot(recipes: ['r1' => new AggregateSnapshot(3, 'h1')]);
        $after = $this->snapshot(recipes: ['r1' => new AggregateSnapshot(3, 'h1'), 'r2' => new AggregateSnapshot(1, 'h2')]);

        $this->assertStringContainsString('recettes : 1 avant, 2 après', implode("\n", $this->verifier->discrepancies($before, $after, $this->drive([]), $this->drive([]), [])));
    }

    /**
     * @param array<string, AggregateSnapshot> $recipes
     * @param array<string, AggregateSnapshot> $lists
     */
    private function snapshot(array $recipes = [], array $lists = [], int $watched = 0): AdoptionSnapshot
    {
        return new AdoptionSnapshot(
            ['recipe_row' => 10, 'shopping_list_row' => 2, 'shopping_list_ingredient' => 1],
            ['recipe_row' => '10.5', 'shopping_list_row' => '3', 'shopping_list_ingredient' => '1'],
            ['recipe_row' => $watched, 'shopping_list_row' => 0, 'shopping_list_ingredient' => 0],
            $recipes,
            $lists,
            '{}',
        );
    }

    /**
     * @param array<string, string> $slugByChoice
     */
    private function drive(array $slugByChoice): DriveChoiceFingerprint
    {
        return new DriveChoiceFingerprint(count($slugByChoice), 3 * count($slugByChoice), '1.000', 'hash', $slugByChoice, array_map(static fn(): int => 3, $slugByChoice));
    }
}
