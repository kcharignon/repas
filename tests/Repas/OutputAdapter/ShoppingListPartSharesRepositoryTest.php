<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\ShoppingListRepository;
use Repas\Repas\Domain\Model\PartShare;
use Repas\Tests\Helper\Builder\ShoppingListBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Le registre caché des parties d'une liste (`shopping_list.part_shares`, ADR 0038 décision 6) sur la VRAIE base : il voyage avec la liste, ne coûte
 * rien à une liste qui n'en a pas, et se lit comme une entrée non fiable.
 */
class ShoppingListPartSharesRepositoryTest extends DatabaseTestCase
{
    private ShoppingListRepository $shoppingLists;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shoppingLists = static::getContainer()->get(ShoppingListRepository::class);
        $this->owner = new UserBuilder()->build();
        new DataPersister(static::getContainer())->saveUser($this->owner);
    }

    public function testAListWithoutPartsWritesNothingInTheColumn(): void
    {
        // Given / When
        $list = new ShoppingListBuilder()->withOwner($this->owner)->build();
        $list->addRow('oeuf', 'unite', 3);
        $this->shoppingLists->save($list);

        // Then : la colonne reste NULL, et la liste se relit sans registre
        $this->assertNull($this->entityManager->getConnection()->fetchOne('SELECT part_shares FROM shopping_list WHERE id = ?', [$list->getId()]) ?: null);
        $this->assertSame([], $this->shoppingLists->findOneById($list->getId())->getPartShares());
    }

    public function testTheRegistryIsSavedAndReadBackWithTheList(): void
    {
        // Given : 3 œufs + 2 jaunes + 2 blancs, la ligne et le registre dans la même liste
        $list = new ShoppingListBuilder()->withOwner($this->owner)->build();
        $list->addRow('oeuf', 'unite', 3);
        $list->addPart('jaune', 'oeuf', 'unite', 2, 2);
        $list->addPart('demi-gousse', 'vanille', 'gousse', 3, 1.5);

        // When
        $this->shoppingLists->save($list);
        $this->entityManager->clear();
        $actual = $this->shoppingLists->findOneById($list->getId());

        // Then : le registre revient à l'identique, la ligne du parent aussi
        $this->assertEquals(['jaune' => new PartShare('oeuf', 2.0, 2.0), 'demi-gousse' => new PartShare('vanille', 3.0, 1.5)], $actual->getPartShares());
        $this->assertSame(5.0, $actual->findRow('oeuf')->getQuantity());
        $this->assertSame(1.5, $actual->findRow('vanille')->getQuantity());
    }

    public function testAnEntryChangesWithTheListAndTheRegistryEmptiesToNull(): void
    {
        // Given
        $list = new ShoppingListBuilder()->withOwner($this->owner)->build();
        $list->addPart('jaune', 'oeuf', 'unite', 4, 2);
        $this->shoppingLists->save($list);
        $this->entityManager->clear();

        // When : un retrait proportionnel, puis le dernier
        $list = $this->shoppingLists->findOneById($list->getId());
        $list->subtractPart('jaune', 1);
        $this->shoppingLists->save($list);
        $this->entityManager->clear();
        $afterFirst = $this->shoppingLists->findOneById($list->getId());
        $afterFirst->subtractPart('jaune', 3);
        $this->shoppingLists->save($afterFirst);
        $this->entityManager->clear();

        // Then
        $this->assertEquals(new PartShare('oeuf', 3.0, 1.5), $afterFirst->partShareOf('jaune') ?? $list->partShareOf('jaune'));
        $this->assertSame([], $this->shoppingLists->findOneById($list->getId())->getPartShares());
        $this->assertNull($this->entityManager->getConnection()->fetchOne('SELECT part_shares FROM shopping_list WHERE id = ?', [$list->getId()]) ?: null);
        $this->assertNull($this->shoppingLists->findOneById($list->getId())->findRow('oeuf'));
    }

    public function testSavingAnUnchangedListWithPartsDoesNotRewriteTheColumn(): void
    {
        // Given : jsonb rend `2` là où le modèle écrit `2.0` et range les clés à sa façon : ce n'est pas un changement
        $list = new ShoppingListBuilder()->withOwner($this->owner)->build();
        $list->addRow('oeuf', 'unite', 3);
        $list->addPart('jaune', 'oeuf', 'unite', 2, 2);
        $list->addPart('blanc', 'oeuf', 'unite', 2, 2);
        $this->shoppingLists->save($list);
        $this->entityManager->clear();
        $loaded = $this->shoppingLists->findOneById($list->getId());
        $counter = static::getContainer()->get(QueryCounter::class);

        // When : on enregistre la liste lue, sans la changer
        $counter->reset();
        $this->shoppingLists->save($loaded);

        // Then : la version monte (une instruction) mais aucune colonne n'est réécrite
        $updates = array_values(array_filter($counter->statements(), static fn(string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'UPDATE')));
        $this->assertCount(1, $updates, implode("\n", $updates));
        $this->assertStringContainsString('version', $updates[0]);
        $this->assertStringNotContainsString('part_shares', implode(' ', $updates));
    }

    public function testAGarbageRegistryInTheDatabaseIsReadWithoutAnyException(): void
    {
        // Given : une colonne abîmée (une valeur qui n'est pas un objet de parties)
        $list = new ShoppingListBuilder()->withOwner($this->owner)->build();
        $this->shoppingLists->save($list);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shopping_list SET part_shares = '{\"jaune\": {\"parent\": \"oeuf\", \"quantity\": 2, \"equivalent\": 2}, \"abime\": 12, \"negatif\": {\"parent\": \"oeuf\", \"quantity\": -1, \"equivalent\": 1}}' WHERE id = ?",
            [$list->getId()],
        );
        $this->entityManager->clear();

        // When
        $actual = $this->shoppingLists->findOneById($list->getId());

        // Then : seule l'entrée juste est lue
        $this->assertSame(['jaune'], array_keys($actual->getPartShares()));

        // And : une valeur qui n'est même pas un objet se lit comme un registre vide
        $this->entityManager->getConnection()->executeStatement("UPDATE shopping_list SET part_shares = '12' WHERE id = ?", [$list->getId()]);
        $this->entityManager->clear();
        $this->assertSame([], $this->shoppingLists->findOneById($list->getId())->getPartShares());
    }
}
