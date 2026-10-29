<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;

/**
 * Le port `Shared` qui laisse `Repas` repointer et vérifier les choix du drive (ING-003), sur la vraie base : toutes les enseignes et tous
 * les magasins d'un utilisateur, les produits et la clé unique intacts, et une empreinte qui ne voit pas le slug.
 */
class DriveIngredientReferencesRepositoryTest extends DatabaseTestCase
{
    private DriveIngredientReferences $references;
    private AdoptionDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->references = static::getContainer()->get(DriveIngredientReferences::class);
        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
    }

    public function testTheFingerprintCountsChoicesAndProductsAndKeepsTheSlugOfEveryChoice(): void
    {
        // When
        $fingerprint = $this->references->fingerprint($this->dataset->bigId);

        // Then : 12 choix (5 × 2 magasins, 1 autre enseigne, 1 sur un ingrédient personnel), 3 produits chacun, une seule contenance mémorisée (20)
        $this->assertSame([12, 36], [$fingerprint->choices, $fingerprint->products]);
        $this->assertCount(12, $fingerprint->ingredientSlugByChoice);
        $this->assertSame(array_fill_keys(array_keys($fingerprint->ingredientSlugByChoice), 3), $fingerprint->productCountByChoice);
        $this->assertSame('20.000', $fingerprint->packSizeSum);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint->contentHash);
        $none = $this->references->fingerprint('00000000-0000-4000-8000-00000000dead');
        $this->assertSame([0, 0, '0', md5(''), [], []], [$none->choices, $none->products, $none->packSizeSum, $none->contentHash, $none->ingredientSlugByChoice, $none->productCountByChoice]);
    }

    public function testTheFingerprintSeesTheValuesTheAccountObservedOnItsLines(): void
    {
        // Given
        $user = $this->dataset->bigId;
        $before = $this->references->fingerprint($user);

        // When : un libellé, puis un prix et sa date, sont écrits sur une ligne de produit du compte
        $this->dataset->connection->executeStatement(
            "UPDATE drive_choice_product SET label = 'Libellé du compte' WHERE (choice_id, product_id) IN (SELECT cp.choice_id, cp.product_id FROM drive_choice_product cp JOIN drive_choice c ON c.id = cp.choice_id WHERE c.user_id = ? ORDER BY cp.choice_id, cp.product_id LIMIT 1)",
            [$user],
        );
        $withLabel = $this->references->fingerprint($user);
        $this->dataset->connection->executeStatement(
            "UPDATE drive_choice_product SET price_cents = 149, price_seen_at = '2026-10-28 09:30:00' WHERE (choice_id, product_id) IN (SELECT cp.choice_id, cp.product_id FROM drive_choice_product cp JOIN drive_choice c ON c.id = cp.choice_id WHERE c.user_id = ? ORDER BY cp.choice_id, cp.product_id LIMIT 1)",
            [$user],
        );
        $withPrice = $this->references->fingerprint($user);

        // Then : l'empreinte change à chaque étape, le nombre de choix et de produits non
        $this->assertNotSame($before->contentHash, $withLabel->contentHash);
        $this->assertNotSame($withLabel->contentHash, $withPrice->contentHash);
        $this->assertSame([$before->choices, $before->products], [$withPrice->choices, $withPrice->products]);
    }

    public function testRepointingChangesOnlyTheSlugOfTheChoicesOfThatUserAcrossAllRetailersAndStores(): void
    {
        $dataset = $this->dataset;
        $user = $dataset->bigId;
        $other = $dataset->singleId;
        $before = $this->references->fingerprint($user);
        $otherBefore = $dataset->hashOfUser($other);
        $rows = static fn(): string => md5(implode("\n", $dataset->connection->fetchFirstColumn(
            'SELECT c.id || c.retailer || c.store_id || c.kind || coalesce(c.search_query, \'\') || c.updated_at::text FROM drive_choice c WHERE c.user_id = ? ORDER BY c.id',
            [$user],
        )));
        $stable = $rows();

        // When : deux communs repointés vers deux copies
        $slug = $dataset->commons[0];
        $count = $this->references->repoint($user, [$slug => 'copie-a', $dataset->commons[1] => 'copie-b', 'absent' => 'copie-z']);

        // Then : tous les choix concernés, des deux magasins ET de l'autre enseigne, ont changé de slug et de rien d'autre
        $this->assertGreaterThanOrEqual(5, $count);
        $after = $this->references->fingerprint($user);
        $this->assertSame([$before->choices, $before->products, $before->packSizeSum, $before->contentHash], [$after->choices, $after->products, $after->packSizeSum, $after->contentHash], 'l\'empreinte ne voit pas le slug');
        $this->assertSame($stable, $rows(), 'ni l\'identifiant, ni la date, ni la recherche mémorisée, ni l\'enseigne, ni le magasin');
        $this->assertSame($before->productCountByChoice, $after->productCountByChoice);
        $retailers = $dataset->connection->fetchFirstColumn("SELECT DISTINCT retailer FROM drive_choice WHERE user_id = ? AND ingredient_slug IN ('copie-a', 'copie-b') ORDER BY retailer", [$user]);
        $this->assertEqualsCanonicalizing([Retailer::LECLERC->value, AdoptionDataset::SECOND_RETAILER], $retailers);
        $this->assertSame(0, (int) $dataset->connection->fetchOne('SELECT count(*) FROM drive_choice WHERE user_id = ? AND ingredient_slug IN (?, ?)', [$user, $slug, $dataset->commons[1]]));
        // Un autre compte ne bouge pas
        $this->assertSame($otherBefore, $dataset->hashOfUser($other));
        // Rejoué : plus rien à repointer
        $this->assertSame(0, $this->references->repoint($user, [$slug => 'copie-a']));
        $this->assertSame(0, $this->references->repoint($user, []));
    }

    public function testAChoiceWhoseTargetSlugIsAlreadyTakenInTheSameStoreIsLeftAloneAndReportedByTheCount(): void
    {
        // Given : le gros compte a déjà un choix sous « copie-a » dans le premier magasin
        $dataset = $this->dataset;
        $user = $dataset->bigId;
        $dataset->choice($user, 'copie-a', '900001', Retailer::LECLERC->value, '123456');
        $onTheCommon = (int) $dataset->connection->fetchOne("SELECT count(*) FROM drive_choice WHERE user_id = ? AND ingredient_slug = ? AND store_id = '123456'", [$user, $dataset->commons[0]]);
        $this->assertSame(1, $onTheCommon);

        // When
        $this->references->repoint($user, [$dataset->commons[0] => 'copie-a']);

        // Then : la clé unique est respectée (aucune erreur), le choix du premier magasin est resté sur le commun, ceux des autres magasins ont suivi
        $this->assertSame(1, (int) $dataset->connection->fetchOne("SELECT count(*) FROM drive_choice WHERE user_id = ? AND ingredient_slug = ? AND store_id = '123456' AND retailer = ?", [$user, $dataset->commons[0], Retailer::LECLERC->value]));
        $this->assertSame(1, (int) $dataset->connection->fetchOne("SELECT count(*) FROM drive_choice WHERE user_id = ? AND ingredient_slug = 'copie-a' AND store_id = '654321'", [$user]));
    }

    /**
     * Un utilisateur, un autre, et leurs choix sur « tomate » (gardé) et « tomates » (absorbé) : deux magasins d'une enseigne, un magasin
     * d'une autre. Premier magasin : les deux ont un choix ; deuxième : l'absorbé seul ; autre enseigne (même numéro de magasin) : l'absorbé seul
     * ET le gardé, chacun le sien.
     *
     * @return array{MergeDataset, string, string}
     */
    private function mergeScene(): array
    {
        $merge = new MergeDataset($this->entityManager, new DataPersister(static::getContainer()));
        $user = 'a0000000-0000-4000-8000-000000000001';
        $other = 'b0000000-0000-4000-8000-000000000002';
        $leclerc = Retailer::LECLERC->value;
        $merge->choice('kept-1', $user, 'tomate', '111111', $leclerc, 'tomate ronde');
        $merge->choice('absorbed-1', $user, 'tomates', '111111', $leclerc);
        $merge->choice('absorbed-2', $user, 'tomates', '222222', $leclerc, 'tomates grappe');
        $merge->choice('kept-3', $user, 'tomate', '111111', AdoptionDataset::SECOND_RETAILER);
        $merge->choice('absorbed-3', $user, 'tomates', '111111', AdoptionDataset::SECOND_RETAILER);
        $merge->choice('absorbed-4', $user, 'tomates', '333333', AdoptionDataset::SECOND_RETAILER);
        $merge->choice('other-1', $other, 'tomate', '111111', $leclerc);

        return [$merge, $user, $other];
    }

    public function testTheMergeImpactCountsWhatPassesOverAndWhatIsDroppedPerStore(): void
    {
        // Given
        [, $user] = $this->mergeScene();

        // When
        $impact = $this->references->mergeImpact($user, 'tomates', 'tomate');

        // Then : deux choix passent sur le gardé (le deuxième magasin, le troisième de l'autre enseigne), deux sont supprimés avec leurs 6 produits
        $this->assertSame([2, 2, 6], [$impact->choicesMoved, $impact->choicesDropped, $impact->productsDropped]);
    }

    public function testTheMergeImpactIgnoresTheChoicesOfAnotherUserOnTheAbsorbedIngredient(): void
    {
        // Given : un autre utilisateur a un choix sur « tomates », qui est un ingrédient de l'utilisateur qui fusionne : un choix ne se pose que sur
        // l'ingrédient de son auteur, celui-ci n'est l'usage de personne
        [$merge, $user, $other] = $this->mergeScene();
        $merge->choice('legacy', $other, 'tomates', '111111', Retailer::LECLERC->value);
        $before = $merge->hashOfUser($other);

        // When
        $impact = $this->references->mergeImpact($user, 'tomates', 'tomate');
        $done = $this->references->merge($user, 'tomates', 'tomate');

        // Then : il ne compte ni parmi ce qui est déplacé ni parmi ce qui est supprimé, et la fusion n'y touche pas
        $this->assertSame([2, 2, 6], [$impact->choicesMoved, $impact->choicesDropped, $impact->productsDropped]);
        $this->assertSame([2, 2, 6], [$done->choicesMoved, $done->choicesDropped, $done->productsDropped]);
        $this->assertSame($before, $merge->hashOfUser($other));
    }

    public function testMergingKeepsTheChoiceOfTheKeptIngredientAndMovesTheOthersWithTheirProducts(): void
    {
        // Given
        [$merge, $user, $other] = $this->mergeScene();
        $otherBefore = $merge->hashOfUser($other);
        $impact = $this->references->mergeImpact($user, 'tomates', 'tomate');
        $keptBefore = $merge->connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('kept-1', 'kept-3') ORDER BY choice_id, product_id");
        $movedBefore = $merge->connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('absorbed-2', 'absorbed-4') ORDER BY choice_id, product_id");

        // When
        $done = $this->references->merge($user, 'tomates', 'tomate');
        $this->entityManager->clear();

        // Then : ce que l'aperçu annonçait
        $this->assertEquals($impact->choicesMoved, $done->choicesMoved);
        $this->assertSame([2, 2, 6], [$done->choicesMoved, $done->choicesDropped, $done->productsDropped]);
        // les choix du gardé sont intacts (même recherche, mêmes produits) ; ceux de l'absorbé qui devaient l'être sont supprimés avec leurs produits
        $this->assertSame(
            ['absorbed-2' => 'tomate', 'absorbed-4' => 'tomate', 'kept-1' => 'tomate', 'kept-3' => 'tomate', 'other-1' => 'tomate'],
            // (le jeu de données de la migration, qui est aussi en base, n'est pas celui de cette scène)
            array_filter($merge->choiceSlugs(), static fn(string $slug): bool => str_starts_with($slug, 'tomate')),
        );
        $this->assertSame($keptBefore, $merge->connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('kept-1', 'kept-3') ORDER BY choice_id, product_id"));
        $this->assertSame('tomate ronde', $merge->connection->fetchOne("SELECT search_query FROM drive_choice WHERE id = 'kept-1'"));
        $this->assertSame(0, $merge->count('drive_choice_product', "choice_id IN ('absorbed-1', 'absorbed-3')"));
        // le choix qui passe sur le gardé garde son identifiant, sa recherche mémorisée, sa date, et ses produits (contenance comprise)
        $this->assertSame($movedBefore, $merge->connection->fetchAllAssociative("SELECT * FROM drive_choice_product WHERE choice_id IN ('absorbed-2', 'absorbed-4') ORDER BY choice_id, product_id"));
        $this->assertSame(['tomates grappe', '2026-10-18 10:00:00'], [
            $merge->connection->fetchOne("SELECT search_query FROM drive_choice WHERE id = 'absorbed-2'"),
            $merge->connection->fetchOne("SELECT to_char(updated_at, 'YYYY-MM-DD HH24:MI:SS') FROM drive_choice WHERE id = 'absorbed-2'"),
        ]);
        $this->assertSame('20.000', $merge->connection->fetchOne("SELECT pack_size_value::text FROM drive_choice_product WHERE choice_id = 'absorbed-2' AND product_id = 'absorbed-2-p1'"));
        // un autre utilisateur ne bouge pas
        $this->assertSame($otherBefore, $merge->hashOfUser($other));
    }

    public function testMergingTwiceDoesNothingTheSecondTime(): void
    {
        // Given
        [$merge, $user] = $this->mergeScene();
        $this->references->merge($user, 'tomates', 'tomate');
        $hashes = $merge->tableHashes();

        // When
        $done = $this->references->merge($user, 'tomates', 'tomate');

        // Then
        $this->assertSame([0, 0, 0], [$done->choicesMoved, $done->choicesDropped, $done->productsDropped]);
        $this->assertSame($hashes, $merge->tableHashes());
    }

    public function testMergingNeverViolatesTheUniqueKeyOfTheChoices(): void
    {
        // Given : le gardé a un choix dans CHAQUE magasin où l'absorbé en a un
        [$merge, $user] = $this->mergeScene();
        $merge->choice('kept-2', $user, 'tomate', '222222', Retailer::LECLERC->value);
        $merge->choice('kept-4', $user, 'tomate', '333333', AdoptionDataset::SECOND_RETAILER);

        // When
        $done = $this->references->merge($user, 'tomates', 'tomate');

        // Then : tous les choix de l'absorbé sont supprimés, aucun ne passe, aucune clé unique n'a sauté
        $this->assertSame([0, 4], [$done->choicesMoved, $done->choicesDropped]);
        $this->assertSame(0, $merge->count('drive_choice', "ingredient_slug = 'tomates'"));
    }
}
