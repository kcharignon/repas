<?php

namespace Repas\Tests\Drive\OutputAdapter;


use Repas\Drive\Application\Loader\IngredientChoiceLoader;
use Repas\Drive\Domain\Interface\IngredientChoiceRepository;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;

/**
 * La lecture seule des choix de produits du drive vus par ingrédient, pour les rapports d'un autre contexte (rapport de l'amorçage, aperçu de
 * la fusion), sur la vraie base. Elle rend exactement ce que le Repository de l'écriture verra (`IngredientChoiceRepositoryTest`) : un
 * rapport dit ce que l'écriture fera.
 */
class IngredientChoiceLoaderTest extends DatabaseTestCase
{
    private IngredientChoiceLoader $loader;
    private IngredientChoiceRepository $repository;
    private AdoptionDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = static::getContainer()->get(IngredientChoiceLoader::class);
        $this->repository = static::getContainer()->get(IngredientChoiceRepository::class);
        $this->dataset = AdoptionDataset::create($this->entityManager, new DataPersister(static::getContainer()));
    }

    public function testTheFingerprintIsTheOneTheRepositoryGivesToTheWrite(): void
    {
        // When
        $fingerprint = $this->loader->fingerprint($this->dataset->bigId);
        $written = $this->repository->fingerprint($this->dataset->bigId);

        // Then
        $this->assertSame(
            [$written->choices, $written->products, $written->packSizeSum, $written->contentHash, $written->ingredientSlugByChoice, $written->productCountByChoice],
            [$fingerprint['choices'], $fingerprint['products'], $fingerprint['packSizeSum'], $fingerprint['contentHash'], $fingerprint['ingredientSlugByChoice'], $fingerprint['productCountByChoice']],
        );
        $this->assertSame([12, 36], [$fingerprint['choices'], $fingerprint['products']]);
        $none = $this->loader->fingerprint('00000000-0000-4000-8000-00000000dead');
        $this->assertSame([0, 0, '0', md5(''), [], []], [$none['choices'], $none['products'], $none['packSizeSum'], $none['contentHash'], $none['ingredientSlugByChoice'], $none['productCountByChoice']]);
    }

    public function testTheReferencedSlugsAreSortedWithoutDuplicatesAndPerUser(): void
    {
        // When
        $slugs = $this->loader->referencedSlugs($this->dataset->bigId);

        // Then
        $sorted = array_values(array_unique($slugs));
        sort($sorted);
        $this->assertSame($sorted, $slugs);
        $this->assertSame($this->repository->referencedSlugs($this->dataset->bigId), $slugs);
        $this->assertNotSame([], $slugs);
        $this->assertSame([], $this->loader->referencedSlugs('00000000-0000-4000-8000-00000000dead'));
    }

    public function testTheReferencedSlugsOfEveryUserListEachUserWithHisOwnSlugs(): void
    {
        // When
        $every = $this->loader->referencedSlugsOfEveryUser();

        // Then
        $this->assertSame($this->loader->referencedSlugs($this->dataset->bigId), $every[$this->dataset->bigId]);
        $this->assertSame($this->loader->referencedSlugs($this->dataset->singleId), $every[$this->dataset->singleId]);
        $users = array_keys($every);
        $sorted = $users;
        sort($sorted);
        $this->assertSame($sorted, $users);
    }

    public function testTheMergeImpactCountsWhatPassesOverAndWhatIsDroppedPerStore(): void
    {
        // Given : un utilisateur, un autre, et leurs choix sur « tomate » (gardé) et « tomates » (absorbé) : deux magasins d'une enseigne, un
        // magasin d'une autre. Premier magasin : les deux ont un choix ; deuxième : l'absorbé seul ; autre enseigne : l'absorbé seul ET le gardé
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
        // Un autre utilisateur a un choix sur « tomates » : il n'est l'usage de personne
        $merge->choice('legacy', $other, 'tomates', '111111', $leclerc);

        // When
        $impact = $this->loader->mergeImpact($user, 'tomates', 'tomate');
        $none = $this->loader->mergeImpact($user, 'inconnu', 'tomate');

        // Then : deux choix passent sur le gardé (le deuxième magasin, le troisième de l'autre enseigne), deux sont supprimés avec leurs 6 produits
        $this->assertSame(['moved' => 2, 'dropped' => 2, 'products' => 6], $impact);
        $this->assertSame(['moved' => 0, 'dropped' => 0, 'products' => 0], $none);
    }
}
