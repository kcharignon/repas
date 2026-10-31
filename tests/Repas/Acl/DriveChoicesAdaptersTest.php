<?php

namespace Repas\Tests\Repas\Acl;


use PHPUnit\Framework\TestCase;
use Repas\Drive\Application\OpenHostService\IngredientChoiceCommandOHS;
use Repas\Drive\Application\OpenHostService\IngredientChoiceQueryOHS;
use Repas\Repas\Domain\Model\DriveMergeImpact;
use Repas\Repas\Infrastructure\Acl\DriveChoicesCommandAdapter;
use Repas\Repas\Infrastructure\Acl\DriveChoicesQueryAdapter;
use Repas\Tests\Helper\InMemoryLoader\IngredientChoiceInMemoryLoader;
use Repas\Tests\Helper\InMemoryRepository\IngredientChoiceInMemoryRepository;

/**
 * La transposition de ce que le contexte `Drive` publie (choix de produits vus par ingrédient) en réponses du contexte `Repas` (ADR 0035),
 * avec les vrais Open Host Services branchés sur les doubles en mémoire du contexte `Drive`. Les handlers d'écriture de `Repas` exercent aussi
 * l'adapter d'écriture, par transitivité ; le SQL est prouvé sur la vraie base (`tests/Drive/OutputAdapter`).
 */
class DriveChoicesAdaptersTest extends TestCase
{
    private const string USER = 'user-1';

    private IngredientChoiceInMemoryRepository $drive;
    private DriveChoicesCommandAdapter $command;
    private DriveChoicesQueryAdapter $query;

    protected function setUp(): void
    {
        $this->drive = new IngredientChoiceInMemoryRepository();
        $this->drive->choices = [
            'c1' => ['user' => self::USER, 'slug' => 'tomates', 'products' => 2, 'store' => 'shop-1'],
            'c2' => ['user' => self::USER, 'slug' => 'tomate', 'products' => 1, 'store' => 'shop-1'],
            'c3' => ['user' => self::USER, 'slug' => 'tomates', 'products' => 3, 'store' => 'shop-2'],
            'c4' => ['user' => 'other-user', 'slug' => 'beurre', 'products' => 4],
        ];
        $this->command = new DriveChoicesCommandAdapter(new IngredientChoiceCommandOHS($this->drive));
        $this->query = new DriveChoicesQueryAdapter(new IngredientChoiceQueryOHS(new IngredientChoiceInMemoryLoader($this->drive)));
    }

    public function testBothAdaptersGiveTheFingerprintOfTheAccountInTheTypeOfRepas(): void
    {
        // When
        $written = $this->command->fingerprint(self::USER);
        $read = $this->query->fingerprint(self::USER);

        // Then : la même empreinte par les deux côtés
        $this->assertEquals($written, $read);
        $this->assertSame([3, 6], [$written->choices, $written->products]);
        $this->assertSame(['c1' => 'tomates', 'c2' => 'tomate', 'c3' => 'tomates'], $written->ingredientSlugByChoice);
        $this->assertSame(['c1' => 2, 'c2' => 1, 'c3' => 3], $written->productCountByChoice);
        $this->assertSame([0, []], [$this->query->fingerprint('unknown-user')->choices, $this->query->fingerprint('unknown-user')->ingredientSlugByChoice]);
    }

    public function testTheReferencedSlugsAreThoseOfTheAccountAndOfEveryAccount(): void
    {
        // Then
        $this->assertSame(['tomate', 'tomates'], $this->command->referencedSlugs(self::USER));
        $this->assertSame(['tomate', 'tomates'], $this->query->referencedSlugs(self::USER));
        $this->assertSame([], $this->query->referencedSlugs('unknown-user'));
        $this->assertSame(['other-user' => ['beurre'], self::USER => ['tomate', 'tomates']], $this->query->referencedSlugsOfEveryUser());
    }

    public function testTheMergeImpactIsReadWithoutWritingAnything(): void
    {
        // When
        $impact = $this->query->mergeImpact(self::USER, 'tomates', 'tomate');

        // Then : le choix du premier magasin est supprimé avec ses 2 produits (le gardé y a le sien), celui du second passe sur le gardé
        $this->assertEquals(new DriveMergeImpact(1, 1, 2), $impact);
        $this->assertSame('tomates', $this->drive->choices['c1']['slug']);
        $this->assertSame([], $this->drive->mergeCalls);
    }

    public function testTheCommandAdapterRepointsAndMergesTheChoicesOfTheAccountOnly(): void
    {
        // When
        $repointed = $this->command->repoint(self::USER, ['tomate' => 'tomate-copie', 'beurre' => 'beurre-copie']);
        $this->command->merge(self::USER, 'tomates', 'tomate-copie');

        // Then : le choix d'un autre compte n'est jamais touché ; la fusion garde le choix du gardé et fait passer l'autre
        $this->assertSame(1, $repointed);
        $this->assertSame(['c2' => 'tomate-copie', 'c3' => 'tomate-copie', 'c4' => 'beurre'], array_map(static fn(array $choice): string => $choice['slug'], $this->drive->choices));
        $this->assertSame([['user' => self::USER, 'absorbed' => 'tomates', 'kept' => 'tomate-copie']], $this->drive->mergeCalls);
    }
}
