<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\IngredientPartLinkRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Adoption\PartLinkSql;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\Tests\Helper\DatabaseTestCase;

/**
 * La liaison des sous-ingrédients sur la VRAIE base (ADR 0038 décision 21, SUB-002) : une copie ne recopie jamais `parent_slug`, une seule
 * instruction relie les copies d'un compte par provenance. Le catalogue ci-dessous est celui du propriétaire (« Œuf » a deux parties, « Jaune d'œuf »
 * au ratio 2 et « Blanc d'œuf » au ratio 1) ; les comptes sont `account-a` et `account-b`. Aucun nom réel : des valeurs factices.
 */
class IngredientPartLinkTest extends DatabaseTestCase
{
    private const string OWNER = 'owner';
    private const string A = 'account-a';
    private const string B = 'account-b';

    private IngredientPartLinkRepository $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->links = static::getContainer()->get(IngredientPartLinkRepository::class);

        // Le catalogue du propriétaire : « Œuf » et ses deux parties (le jaune compte pour 2 unités de partie par œuf)
        $this->put('oeuf', self::OWNER);
        $this->put('jaune', self::OWNER, parent: 'oeuf', ratio: 2.0);
        $this->put('blanc', self::OWNER, parent: 'oeuf', ratio: 1.0);
    }

    public function testBothCopiesAreLinkedByProvenanceWithTheRatioOfTheOrigin(): void
    {
        // Given : le compte a reçu les trois copies, sans lien (une copie ne recopie jamais le parent)
        $this->copiesOfTheEggFor(self::A);

        // When
        $linked = $this->links->linkCopies(self::A);

        // Then : ses deux parties pointent vers SA copie d'« Œuf », jamais vers le slug du catalogue ; le ratio est celui de l'origine
        $this->assertSame(2, $linked);
        $this->assertSame(['oeuf-a', 2.0], $this->parentOf('jaune-a'));
        $this->assertSame(['oeuf-a', 1.0], $this->parentOf('blanc-a'));
        $this->assertSame([null, null], $this->parentOf('oeuf-a'));
        $this->assertSame([null, null], $this->parentOf('oeuf'), 'le catalogue est intact');
        $this->assertSame(['oeuf', 2.0], $this->parentOf('jaune'));
    }

    public function testNothingIsLinkedWhenTheAccountDoesNotHaveTheParent(): void
    {
        // Given : le compte a ses deux parties mais pas « Œuf »
        $this->put('jaune-a', self::A, from: 'jaune');
        $this->put('blanc-a', self::A, from: 'blanc');
        $before = $this->snapshot();

        // When
        $linked = $this->links->linkCopies(self::A);

        // Then : elles restent des ingrédients ordinaires, aucun ingrédient n'est copié « pour compléter »
        $this->assertSame(0, $linked);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, $this->scalar('SELECT count(*) FROM ingredient WHERE creator = ?', [self::A]));
    }

    public function testNothingIsLinkedWhenTheOriginHasNoParent(): void
    {
        // Given : le compte a deux ingrédients ordinaires du catalogue, qui n'ont aucun lien entre eux
        $this->put('sel', self::OWNER);
        $this->put('poivre', self::OWNER);
        $this->put('sel-a', self::A, from: 'sel');
        $this->put('poivre-a', self::A, from: 'poivre');

        // Then
        $this->assertSame(0, $this->links->linkCopies(self::A));
    }

    public function testALinkTheUserMadeIsNeverOverwritten(): void
    {
        // Given : l'utilisateur a découpé SON jaune d'œuf comme partie d'un « Œuf extra » à lui (ratio 3) ; la copie de l'« Œuf » du catalogue est là aussi
        $this->copiesOfTheEggFor(self::A);
        $this->put('oeuf-extra-a', self::A);
        $this->setParent('jaune-a', 'oeuf-extra-a', 3.0);

        // When
        $linked = $this->links->linkCopies(self::A);

        // Then : son lien gagne ; le blanc, lui, est relié
        $this->assertSame(1, $linked);
        $this->assertSame(['oeuf-extra-a', 3.0], $this->parentOf('jaune-a'));
        $this->assertSame(['oeuf-a', 1.0], $this->parentOf('blanc-a'));
    }

    public function testALinkAlreadyMadeIsNotWrittenAgain(): void
    {
        // Given : le jaune est déjà relié à la copie du catalogue, avec un ratio que l'utilisateur a corrigé
        $this->copiesOfTheEggFor(self::A);
        $this->setParent('jaune-a', 'oeuf-a', 4.0);

        // When
        $this->links->linkCopies(self::A);

        // Then : le ratio de l'utilisateur est intact
        $this->assertSame(['oeuf-a', 4.0], $this->parentOf('jaune-a'));
    }

    public function testACopyOfACopyIsLinkedThroughTheOriginOfItsOrigin(): void
    {
        // Given : le compte B a copié la recette de A : ses copies viennent de celles de A, mais retiennent l'origine du catalogue (D7)
        $this->put('oeuf-b', self::B, from: 'oeuf');
        $this->put('jaune-b', self::B, from: 'jaune');

        // When
        $linked = $this->links->linkCopies(self::B);

        // Then
        $this->assertSame(1, $linked);
        $this->assertSame(['oeuf-b', 2.0], $this->parentOf('jaune-b'));
    }

    public function testTheParentOfTheOriginMayItselfBeACopyAndIsMatchedThroughItsOwnOrigin(): void
    {
        // Given : le catalogue du propriétaire a lui-même reçu « Beurre » d'un autre compte (origine « beurre-racine ») et découpé « Beurre clarifié »
        $this->put('beurre', self::OWNER, from: 'beurre-racine');
        $this->put('beurre-clarifie', self::OWNER, parent: 'beurre', ratio: 1.0);
        // Le compte a la copie de la racine et celle du beurre clarifié du catalogue
        $this->put('beurre-a', self::A, from: 'beurre-racine');
        $this->put('beurre-clarifie-a', self::A, from: 'beurre-clarifie');

        // Then : le parent du compte est celui qui vient de la racine du parent du catalogue
        $this->assertSame(1, $this->links->linkCopies(self::A));
        $this->assertSame(['beurre-a', 1.0], $this->parentOf('beurre-clarifie-a'));
    }

    public function testAnotherUsersDecouplingTravelsWithTheCopiesOfHisIngredients(): void
    {
        // Given : un utilisateur (hors catalogue) a découpé ses propres ingrédients ; B a copié sa recette
        $this->put('pate-c', 'account-c');
        $this->put('pate-fine-c', 'account-c', parent: 'pate-c', ratio: 0.5);
        $this->put('pate-b', self::B, from: 'pate-c');
        $this->put('pate-fine-b', self::B, from: 'pate-fine-c');

        // When
        $linked = $this->links->linkCopies(self::B);

        // Then : B retrouve le découpage, avec ses propres slugs ; l'utilisateur d'origine n'est pas touché
        $this->assertSame(1, $linked);
        $this->assertSame(['pate-b', 0.5], $this->parentOf('pate-fine-b'));
        $this->assertSame(['pate-c', 0.5], $this->parentOf('pate-fine-c'));
    }

    public function testAParentKeepsAtMostTenPartsCountingThoseTheSameStatementGives(): void
    {
        // Given : un parent du catalogue avec douze parties ; le compte a la copie du parent, huit parties déjà reliées par lui et quatre copies à relier
        $this->put('gateau', self::OWNER);
        $this->put('gateau-a', self::A, from: 'gateau');
        foreach (range(1, 12) as $n) {
            $this->put(sprintf('part-%02d', $n), self::OWNER, parent: 'gateau', ratio: 1.0);
        }
        foreach (range(1, 8) as $n) {
            $this->put(sprintf('part-%02d-a', $n), self::A, from: sprintf('part-%02d', $n), parent: 'gateau-a', ratio: 1.0);
        }
        foreach (range(9, 12) as $n) {
            $this->put(sprintf('part-%02d-a', $n), self::A, from: sprintf('part-%02d', $n));
        }

        // When
        $linked = $this->links->linkCopies(self::A);

        // Then : deux de plus seulement (les premières par slug), jamais plus de dix
        $this->assertSame(2, $linked);
        $this->assertSame(10, $this->scalar('SELECT count(*) FROM ingredient WHERE parent_slug = ?', ['gateau-a']));
        $this->assertSame(['gateau-a', 1.0], $this->parentOf('part-09-a'));
        $this->assertSame(['gateau-a', 1.0], $this->parentOf('part-10-a'));
        $this->assertSame([null, null], $this->parentOf('part-11-a'));
        $this->assertSame(0, $this->links->linkCopies(self::A), 'un parent plein ne reçoit plus rien');
    }

    public function testAFreshParentLinksTenOfTwelvePartsInSlugOrder(): void
    {
        // Given : le compte reçoit en une fois le parent et ses douze parties
        $this->put('gateau', self::OWNER);
        $this->put('gateau-a', self::A, from: 'gateau');
        foreach (range(1, 12) as $n) {
            $this->put(sprintf('part-%02d', $n), self::OWNER, parent: 'gateau', ratio: 1.0);
            $this->put(sprintf('part-%02d-a', $n), self::A, from: sprintf('part-%02d', $n));
        }

        // When
        $linked = $this->links->linkCopies(self::A);

        // Then
        $this->assertSame(10, $linked);
        $this->assertSame([null, null], $this->parentOf('part-11-a'));
        $this->assertSame([null, null], $this->parentOf('part-12-a'));
    }

    public function testNoChainIsEverMade(): void
    {
        // Given : le compte a déjà fait de sa copie d'« Œuf » une partie d'un « Plat » à lui, et son jaune a une partie à lui
        $this->copiesOfTheEggFor(self::A);
        $this->put('plat-a', self::A);
        $this->setParent('oeuf-a', 'plat-a', 1.0);
        $this->put('moitie-de-jaune-a', self::A, parent: 'jaune-a', ratio: 2.0);

        // When
        $linked = $this->links->linkCopies(self::A);

        // Then : « Œuf » est une partie, il ne devient pas le parent du blanc ; « Jaune » a une partie, il ne devient pas une partie
        $this->assertSame(0, $linked);
        $this->assertSame([null, null], $this->parentOf('blanc-a'));
        $this->assertSame([null, null], $this->parentOf('jaune-a'));
    }

    public function testACatalogChainIsNeverCopiedIntoAChain(): void
    {
        // Given : le catalogue (anormal) a trois niveaux : « Quart » est une partie de « Jaune », lui-même partie d'« Œuf » ; le compte a les quatre copies
        $this->put('quart', self::OWNER, parent: 'jaune', ratio: 4.0);
        $this->copiesOfTheEggFor(self::A);
        $this->put('quart-a', self::A, from: 'quart');

        // When
        $this->links->linkCopies(self::A);

        // Then : le troisième niveau n'est pas relié, et chaque ingrédient du compte est parent ou partie, jamais les deux
        $this->assertSame([null, null], $this->parentOf('quart-a'));
        $this->assertSame(['oeuf-a', 2.0], $this->parentOf('jaune-a'));
        $this->assertSame(0, $this->scalar(
            'SELECT count(*) FROM ingredient c JOIN ingredient p ON p.slug = c.parent_slug WHERE c.creator = ? AND p.parent_slug IS NOT NULL',
            [self::A],
        ));
        $this->assertSame(0, $this->scalar('SELECT count(*) FROM ingredient c WHERE c.creator = ? AND c.parent_slug IS NOT NULL AND c.parent_slug = c.slug', [self::A]));
    }

    public function testAnIngredientIsNeverItsOwnParentEvenWhenTheOriginPointsAtItself(): void
    {
        // Given : une donnée abîmée, un ingrédient du catalogue qui est sa propre partie, et le compte qui en a la copie
        $this->put('boucle', self::OWNER, parent: 'boucle', ratio: 1.0);
        $this->put('boucle-a', self::A, from: 'boucle');

        // Then
        $this->assertSame(0, $this->links->linkCopies(self::A));
    }

    public function testACopyWithALineInAnOpenListIsNotLinkedUntilTheListIsCompleted(): void
    {
        // Given : le compte a ses copies et une liste en cours qui porte une ligne de « Jaune d'œuf » (une partie n'est jamais une ligne d'une liste en cours)
        $this->copiesOfTheEggFor(self::A);
        $listId = $this->list(self::A, 'ACTIVE', ['jaune-a']);

        // When
        $first = $this->links->linkCopies(self::A);

        // Then : le blanc est relié, pas le jaune
        $this->assertSame(1, $first);
        $this->assertSame([null, null], $this->parentOf('jaune-a'));
        $this->assertSame(['oeuf-a', 1.0], $this->parentOf('blanc-a'));

        // And : la liste terminée, la liaison suivante le relie
        $this->entityManager->getConnection()->executeStatement("UPDATE shopping_list SET status = 'COMPLETED' WHERE id = ?", [$listId]);
        $this->assertSame(1, $this->links->linkCopies(self::A));
        $this->assertSame(['oeuf-a', 2.0], $this->parentOf('jaune-a'));
    }

    public function testALineOfTheParentInAnOpenListDoesNotPreventTheLink(): void
    {
        // Given : la liste en cours porte « Œuf » (le parent), jamais une partie
        $this->copiesOfTheEggFor(self::A);
        $this->list(self::A, 'ACTIVE', ['oeuf-a']);

        // Then
        $this->assertSame(2, $this->links->linkCopies(self::A));
    }

    public function testTwoCopiesOfTheParentGiveTheSameParentEveryTime(): void
    {
        // Given : un doublon (deux copies d'« Œuf », l'une des deux à un slug qui passe avant)
        $this->copiesOfTheEggFor(self::A);
        $this->put('oeuf-2-a', self::A, from: 'oeuf');

        // When
        $this->links->linkCopies(self::A);

        // Then : le plus petit slug (déterministe), pour les deux parties
        $this->assertSame(['oeuf-2-a', 2.0], $this->parentOf('jaune-a'));
        $this->assertSame(['oeuf-2-a', 1.0], $this->parentOf('blanc-a'));
    }

    public function testReplayingWritesNothing(): void
    {
        // Given
        $this->copiesOfTheEggFor(self::A);
        $this->assertSame(2, $this->links->linkCopies(self::A));
        $after = $this->snapshot();

        // When : un second appel
        $second = $this->links->linkCopies(self::A);

        // Then : zéro lien, et aucune ligne n'a même été réécrite (la place physique de chaque ligne est la même)
        $this->assertSame(0, $second);
        $this->assertSame($after, $this->snapshot());
    }

    public function testTheIngredientsOfAnotherAccountAreNeverTouched(): void
    {
        // Given : B a aussi ses copies sans lien ; on relie A
        $this->copiesOfTheEggFor(self::A);
        $this->copiesOfTheEggFor(self::B);
        $beforeB = $this->snapshot(self::B);

        // When
        $this->links->linkCopies(self::A);

        // Then : B n'a rien reçu, A a ses liens, et aucun lien de A ne désigne un ingrédient de B
        $this->assertSame($beforeB, $this->snapshot(self::B));
        $this->assertSame(['oeuf-a', 2.0], $this->parentOf('jaune-a'));
        $this->assertSame(0, $this->scalar("SELECT count(*) FROM ingredient WHERE creator = ? AND parent_slug LIKE '%-b'", [self::A]));
        $this->assertSame([null, null], $this->parentOf('jaune-b'));
    }

    public function testTheReservedMarmitonOwnerIsNeverLinked(): void
    {
        // Given : les copies du catalogue qu'il a faites pour ses recettes neutres (ADR 0037)
        $this->put('oeuf-m', Recipe::MARMITON_OWNER_ID, from: 'oeuf');
        $this->put('jaune-m', Recipe::MARMITON_OWNER_ID, from: 'jaune');

        // Then : l'ingrédient d'une neutre n'a jamais de parent
        $this->assertSame(0, $this->links->linkCopies(Recipe::MARMITON_OWNER_ID));
        $this->assertSame([null, null], $this->parentOf('jaune-m'));
        // …et la requête de santé ne le compte pas
        $this->assertSame(0, $this->pendingLinksOfEveryAccount());
    }

    public function testAnOriginThatNoLongerExistsLinksNothing(): void
    {
        // Given : la copie d'un jaune dont l'origine a été supprimée
        $this->put('oeuf-a', self::A, from: 'oeuf');
        $this->put('jaune-a', self::A, from: 'jaune-disparu');

        // Then
        $this->assertSame(0, $this->links->linkCopies(self::A));
    }

    public function testTheHealthQueryAndTheDryRunCountExactlyWhatTheWriteDoes(): void
    {
        // Given : A et B ont leurs copies sans lien
        $this->copiesOfTheEggFor(self::A);
        $this->copiesOfTheEggFor(self::B);

        // Then : tous comptes (la requête de santé de --check), puis un compte, avant et après l'écriture
        $this->assertSame(4,$this->pendingLinksOfEveryAccount());
        $this->assertSame(2, $this->pendingLinksOf(self::A));
        $this->assertSame(2, $this->links->linkCopies(self::A));
        $this->assertSame(0, $this->pendingLinksOf(self::A));
        $this->assertSame(2, $this->pendingLinksOfEveryAccount());
    }

    public function testTheDryRunImaginesTheCopiesTheAccountIsAboutToReceive(): void
    {
        // Given : le compte n'a que la copie d'« Œuf » ; il va recevoir les deux parties
        $this->put('oeuf-a', self::A, from: 'oeuf');

        // Then : la requête à blanc dit deux liens, l'écriture n'en pose aucun avant les copies (elles n'existent pas encore)
        $this->assertSame(2, $this->scalar('SELECT count(*) FROM (' . PartLinkSql::ofOneAccount(true) . ') x', ['u' => self::A, 'max' => Ingredient::MAX_PARTS, 'virtual' => json_encode(['jaune', 'blanc'])]));
        $this->assertSame(0, $this->links->linkCopies(self::A));
        // …puis les copies arrivent, et l'écriture pose les deux liens que le rapport annonçait
        $this->put('jaune-a', self::A, from: 'jaune');
        $this->put('blanc-a', self::A, from: 'blanc');
        $this->assertSame(2, $this->links->linkCopies(self::A));
    }

    // --- Aides

    private function copiesOfTheEggFor(string $account): void
    {
        $suffix = $account === self::A ? '-a' : '-b';
        $this->put('oeuf' . $suffix, $account, from: 'oeuf');
        $this->put('jaune' . $suffix, $account, from: 'jaune');
        $this->put('blanc' . $suffix, $account, from: 'blanc');
    }

    private function put(string $slug, string $creator, ?string $from = null, ?string $parent = null, ?float $ratio = null): void
    {
        $this->entityManager->getConnection()->insert('ingredient', [
            'slug' => $slug,
            'name' => $slug,
            'image' => '',
            'slugged_name' => $slug,
            'department' => 'divers',
            'default_cooking_unit' => 'unite',
            'default_purchase_unit' => 'unite',
            'creator' => $creator,
            'compatible_units' => '["unite"]',
            'adopted_from' => $from,
            'is_base' => 0,
            'parent_slug' => $parent,
            'parent_ratio' => $ratio,
        ]);
    }

    private function setParent(string $slug, string $parent, float $ratio): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE ingredient SET parent_slug = ?, parent_ratio = ? WHERE slug = ?', [$parent, $ratio, $slug]);
    }

    /**
     * @return array{0: ?string, 1: ?float}
     */
    private function parentOf(string $slug): array
    {
        $row = $this->entityManager->getConnection()->fetchNumeric('SELECT parent_slug, parent_ratio FROM ingredient WHERE slug = ?', [$slug]);
        $this->assertNotFalse($row, $slug);

        return [$row[0], $row[1] === null ? null : (float) $row[1]];
    }

    /**
     * L'état physique des lignes (`ctid` change à chaque réécriture, même par la même valeur).
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(?string $creator = null): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT slug, parent_slug, parent_ratio, ctid::text AS version FROM ingredient WHERE (?::text IS NULL OR creator = ?) ORDER BY slug',
            [$creator, $creator],
        );
    }

    /**
     * @param list<string> $ingredients
     */
    private function list(string $owner, string $status, array $ingredients): string
    {
        $connection = $this->entityManager->getConnection();
        $id = UuidGenerator::new();
        $connection->insert('shopping_list', ['id' => $id, 'owner' => $owner, 'created_at' => '2026-11-08 10:00:00', 'status' => $status, 'name' => 'Liste', 'version' => 1]);
        foreach ($ingredients as $ingredient) {
            $connection->insert('shopping_list_row', ['id' => UuidGenerator::new(), 'shopping_list' => $id, 'ingredient' => $ingredient, 'quantity' => 2.0, 'unit' => 'unite', 'checked' => 'false']);
        }

        return $id;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function scalar(string $sql, array $params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }

    private function pendingLinksOf(string $account): int
    {
        return $this->scalar('SELECT count(*) FROM (' . PartLinkSql::ofOneAccount() . ') x', ['u' => $account, 'max' => Ingredient::MAX_PARTS]);
    }

    private function pendingLinksOfEveryAccount(): int
    {
        return $this->scalar('SELECT count(*) FROM (' . PartLinkSql::ofEveryAccount() . ') x', ['marmiton' => Recipe::MARMITON_OWNER_ID, 'max' => Ingredient::MAX_PARTS]);
    }
}
