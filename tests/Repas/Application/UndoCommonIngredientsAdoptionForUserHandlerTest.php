<?php

namespace Repas\Tests\Repas\Application;


use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserCommand;
use Repas\Repas\Application\Command\AdoptCommonIngredientsForUser\AdoptCommonIngredientsForUserHandler;
use Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser\UndoCommonIngredientsAdoptionForUserCommand;
use Repas\Repas\Application\Command\UndoCommonIngredientsAdoptionForUser\UndoCommonIngredientsAdoptionForUserHandler;
use Repas\Repas\Application\Service\AdoptionVerifier;
use Repas\Repas\Domain\Exception\IngredientAdoptionException;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Shared\Domain\Interface\UuidGenerator;
use Repas\Tests\Helper\FrozenClock;
use Repas\Tests\Helper\InMemoryRepository\DriveIngredientReferencesInMemoryRepository;
use Repas\Tests\Helper\InMemoryRepository\IngredientAdoptionBulkInMemoryRepository;

/**
 * Le retour arrière des données d'un lancement (ING-003, `migration.md` § 10), sans base : il repointe vers les communs, efface les alias
 * du lancement, laisse les copies, ne défait pas une copie que l'utilisateur a modifiée, et passe par les mêmes empreintes que l'adoption.
 */
class UndoCommonIngredientsAdoptionForUserHandlerTest extends TestCase
{
    private const string USER = 'user-1';

    private IngredientAdoptionBulkInMemoryRepository $bulk;
    private DriveIngredientReferencesInMemoryRepository $drive;
    private UndoCommonIngredientsAdoptionForUserHandler $undo;
    private AdoptCommonIngredientsForUserHandler $adopt;

    protected function setUp(): void
    {
        $this->bulk = new IngredientAdoptionBulkInMemoryRepository();
        $this->drive = new DriveIngredientReferencesInMemoryRepository();
        $this->undo = new UndoCommonIngredientsAdoptionForUserHandler($this->bulk, $this->drive, new AdoptionVerifier());
        $uuids = new class implements UuidGenerator {
            private int $next = 0;

            public function generate(): string
            {
                return 'new-conversion-' . ++$this->next;
            }
        };
        $this->adopt = new AdoptCommonIngredientsForUserHandler($this->bulk, $this->drive, new IngredientSlugPolicy(), $uuids, new FrozenClock(new DateTimeImmutable('2026-10-21 03:00:00')), new AdoptionVerifier());

        foreach (['beurre' => 'Beurre', 'farine' => 'Farine', 'tomate' => 'Tomate'] as $slug => $name) {
            $this->bulk->ingredients[$slug] = ['name' => $name, 'department' => 'rayon', 'creator' => null, 'adoptedFrom' => null];
        }
        $row = static fn(string $slug): array => ['ingredient' => $slug, 'quantity' => 1.5, 'unit' => 'gramme'];
        $this->bulk->recipes['r1'] = ['author' => self::USER, 'version' => 3, 'rows' => [$row('beurre'), $row('farine'), $row('tomate')]];
        $this->bulk->lists['l1'] = ['owner' => self::USER, 'version' => 5, 'status' => 'SENT', 'sentAt' => '2026-10-10 12:00:00', 'rows' => [$row('beurre')], 'ingredients' => [$row('farine')]];
        $this->drive->choices['c1'] = ['user' => self::USER, 'slug' => 'beurre', 'products' => 3];

        ($this->adopt)(new AdoptCommonIngredientsForUserCommand(self::USER, 'lancement-a', acceptSent: true));
    }

    public function testUndoRepointsToTheCommonsErasesTheAliasesAndKeepsTheCopies(): void
    {
        // Given : l'état d'après l'adoption
        $this->assertSame(['beurre' . self::USER, 'farine' . self::USER, 'tomate' . self::USER], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $copies = array_filter($this->bulk->ingredients, static fn(array $i): bool => $i['creator'] === self::USER);

        // When
        $result = ($this->undo)(new UndoCommonIngredientsAdoptionForUserCommand(self::USER, 'lancement-a', acceptSent: true));

        // Then : tout est revenu sur les communs, les alias du lancement n'existent plus, les copies sont toujours là
        $this->assertSame(3, $result->undone);
        $this->assertSame(['beurre', 'farine', 'tomate'], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame(['beurre'], array_column($this->bulk->lists['l1']['rows'], 'ingredient'));
        $this->assertSame(['farine'], array_column($this->bulk->lists['l1']['ingredients'], 'ingredient'));
        $this->assertSame('beurre', $this->drive->choices['c1']['slug']);
        $this->assertSame([], $this->bulk->aliases[self::USER]);
        $this->assertSame($copies, array_filter($this->bulk->ingredients, static fn(array $i): bool => $i['creator'] === self::USER), 'les copies ne sont jamais supprimées');
        $this->assertSame('SENT', $this->bulk->lists['l1']['status']);
        // Les versions montent une seconde fois (un client qui avait lu l'état migré le saura)
        $this->assertSame([5, 7], [$this->bulk->recipes['r1']['version'], $this->bulk->lists['l1']['version']]);
    }

    public function testACopyTheUserChangedSinceIsNotUndoneAndIsReported(): void
    {
        // Given : il a renommé la copie de la farine
        $this->bulk->ingredients['farine' . self::USER]['name'] = 'Farine de blé T55';

        // When
        $result = ($this->undo)(new UndoCommonIngredientsAdoptionForUserCommand(self::USER, 'lancement-a', acceptSent: true));

        // Then : les autres sont défaites, la farine reste copie, avec son alias et ses lignes
        $this->assertSame([2, 1], [$result->undone, $result->keptBecauseModified]);
        $this->assertSame(['beurre', 'farine' . self::USER, 'tomate'], array_column($this->bulk->recipes['r1']['rows'], 'ingredient'));
        $this->assertSame(['farine'], array_keys($this->bulk->aliases[self::USER]));
    }

    public function testAnotherRunNameDoesNothingAndASentListNeedsTheAcceptance(): void
    {
        // When : un autre lancement
        $result = ($this->undo)(new UndoCommonIngredientsAdoptionForUserCommand(self::USER, 'un-autre', acceptSent: true));

        // Then
        $this->assertSame(0, $result->undone);
        $this->assertSame(3, count($this->bulk->aliases[self::USER]));

        // When / Then : sans acceptation, la liste envoyée arrête le retour arrière, rien n'est défait
        $this->expectException(IngredientAdoptionException::class);
        $this->expectExceptionMessage('--accept-sent');
        ($this->undo)(new UndoCommonIngredientsAdoptionForUserCommand(self::USER, 'lancement-a'));
    }
}
