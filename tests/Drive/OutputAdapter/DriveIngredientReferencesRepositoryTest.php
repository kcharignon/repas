<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Domain\Model\Retailer;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;

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
}
