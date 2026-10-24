<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Model\IngredientToSeed;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SeedingDataset;

/**
 * Les méthodes d'AMORÇAGE (ING-008) du dépôt d'écritures en masse sur la VRAIE base : le périmètre (compte existant, amorcé ou non), la
 * source (« de base » ET créateur propriétaire ou vide, plus ce que les lignes du compte référencent) dans les deux formes de la base, un
 * marqueur posé ailleurs inerte, la copie qui naît à « de base » faux. Les méthodes d'ING-003 sont prouvées par
 * `IngredientAdoptionBulkRepositoryTest` et ne changent pas.
 */
class IngredientSeedingRepositoryTest extends DatabaseTestCase
{
    private IngredientAdoptionBulkRepository $repository;
    private SeedingDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = static::getContainer()->get(IngredientAdoptionBulkRepository::class);
        $this->dataset = SeedingDataset::create($this->entityManager, new DataPersister(static::getContainer()));
    }

    public function testTheAccountExistsOrNot(): void
    {
        $this->assertTrue($this->repository->accountExists($this->dataset->freshId));
        $this->assertTrue($this->repository->accountExists($this->dataset->pendingId));
        $this->assertFalse($this->repository->accountExists('00000000-0000-4000-8000-00000000dead'));
    }

    public function testTheSeedingLockIsASeparateAdvisoryLockOfTheTransactionOnThatUser(): void
    {
        // When : le verrou d'ING-003 et celui de l'amorçage du même compte, deux fois chacun
        $connection = $this->dataset->adoption->connection;
        $this->repository->lock($this->dataset->freshId);
        $this->repository->lockSeeding($this->dataset->freshId);
        $this->repository->lockSeeding($this->dataset->freshId);
        $this->repository->lockSeeding($this->dataset->pendingId);

        // Then : une clé par (famille, compte) ; celui qui l'a posé la reprend sans attendre
        $this->assertSame(3, (int) $connection->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"));
    }

    public function testASeededAccountIsOneWithAnAdoptionAliasWhateverItsCopyAndAMergeAliasAloneDoesNotCount(): void
    {
        $connection = $this->dataset->adoption->connection;
        $aliases = static::getContainer()->get(IngredientAliasRepository::class);
        $now = date('Y-m-d H:i:s');

        // Given : un alias d'adoption ORPHELIN (la copie n'existe pas), un alias de fusion seul, un compte sans alias
        $connection->insert('ingredient_alias', ['user_id' => $this->dataset->adoption->bigId, 'alias_slug' => 'beurre', 'ingredient_slug' => 'beurre-disparu', 'kind' => 'adoption', 'source' => 'ancien', 'created_at' => $now]);
        $connection->insert('ingredient_alias', ['user_id' => $this->dataset->adoption->copierId, 'alias_slug' => 'ancien-slug', 'ingredient_slug' => 'autre', 'kind' => 'merge', 'source' => 'merge', 'created_at' => $now]);

        // Then : exactement la définition du port des alias (`isAdopter`), pour chacun des trois cas
        foreach ([[$this->dataset->adoption->bigId, true], [$this->dataset->adoption->copierId, false], [$this->dataset->freshId, false]] as [$userId, $expected]) {
            $this->assertSame($expected, $this->repository->isSeeded($userId), $userId);
            $this->assertSame($aliases->isAdopter($userId), $this->repository->isSeeded($userId), 'même définition que IngredientAliasRepository::isAdopter');
        }
    }

    public function testTheSourceIsTheBaseIngredientsOfTheCatalog(): void
    {
        // When : un compte neuf
        $toSeed = $this->repository->ingredientsToSeed($this->dataset->freshId, $this->dataset->ownerId, []);

        // Then : les huit « de base », triés par slug, aucun des quatre spécifiques
        $this->assertSame(['beurre', 'couche-taille-4', 'farine', 'lait', 'oeuf', 'sel', 'sucre', 'tomate'], $this->slugs($toSeed));
        $this->assertSame([true], array_values(array_unique(array_map(static fn(IngredientToSeed $i): bool => $i->isBase, $toSeed))));
        $this->assertSame('Beurre', $toSeed[0]->name);
        $this->assertNull($toSeed[0]->existingCopySlug);
    }

    public function testAnIngredientCreatedByTheOwnerAfterTheFirstReleaseWithAnAccountSlugIsIncludedWhenMarked(): void
    {
        // Given : le propriétaire crée un ingrédient depuis R1 (slug avec l'identifiant de son compte) et le marque « de base »
        $this->dataset->adoption->personalIngredient($this->dataset->ownerId, 'Basilic frais', 'rayon-a');
        $slug = 'basilic-frais' . $this->dataset->ownerId;
        $this->dataset->markBase([$slug]);
        $this->dataset->adoption->personalIngredient($this->dataset->ownerId, 'Piment du proprietaire', 'rayon-a');

        // When
        $toSeed = $this->slugs($this->repository->ingredientsToSeed($this->dataset->freshId, $this->dataset->ownerId, []));

        // Then : le marqué est copié, pas celui qui ne l'est pas
        $this->assertContains($slug, $toSeed);
        $this->assertNotContains('piment-du-proprietaire' . $this->dataset->ownerId, $toSeed);
        $this->assertCount(9, $toSeed);
    }

    public function testTheBaseMarkerOfAnotherAccountIsInert(): void
    {
        // Given : un utilisateur ordinaire a un ingrédient marqué « de base » (accès direct à la base), un autre administrateur aussi
        $this->dataset->adoption->personalIngredient($this->dataset->adoption->bigId, 'Gadget', 'rayon-a');
        $this->dataset->adoption->personalIngredient($this->dataset->adoption->adminId, 'Gadget admin', 'rayon-a');
        $this->dataset->markBase(['gadget' . $this->dataset->adoption->bigId, 'gadget-admin' . $this->dataset->adoption->adminId]);

        // When
        $toSeed = $this->slugs($this->repository->ingredientsToSeed($this->dataset->freshId, $this->dataset->ownerId, []));

        // Then : jamais copié
        $this->assertSame(['beurre', 'couche-taille-4', 'farine', 'lait', 'oeuf', 'sel', 'sucre', 'tomate'], $toSeed);
    }

    public function testWhenNothingIsMarkedBaseAFreshAccountHasNothingToSeed(): void
    {
        // Given : le classement n'est pas posé
        $this->dataset->adoption->connection->executeStatement('UPDATE ingredient SET is_base = FALSE');

        // Then
        $this->assertSame([], $this->repository->ingredientsToSeed($this->dataset->freshId, $this->dataset->ownerId, []));
    }

    public function testAnAccountWithDataAlsoGetsTheCatalogIngredientsItsLinesReferenceBaseOrNot(): void
    {
        // Given : le compte vide a une liste (beurre, couche..., farine) et un choix du drive sur le beurre ; on lui ajoute une ligne sur un
        // ingrédient spécifique, dans une recette, et le port du drive en donne un autre
        $empty = $this->dataset->adoption->emptyId;
        $this->dataset->adoption->recipe($empty, 'Une recette', ['ingredient-009']);

        // When
        $toSeed = $this->repository->ingredientsToSeed($empty, $this->dataset->ownerId, ['ingredient-010', 'beurre']);

        // Then : les huit de base, plus les deux spécifiques référencés (recette, drive), marqués comme tels
        $this->assertSame(['beurre', 'couche-taille-4', 'farine', 'ingredient-009', 'ingredient-010', 'lait', 'oeuf', 'sel', 'sucre', 'tomate'], $this->slugs($toSeed));
        $byBase = [];
        foreach ($toSeed as $ingredient) {
            $byBase[(int) $ingredient->isBase][] = $ingredient->slug;
        }
        $this->assertSame(['ingredient-009', 'ingredient-010'], $byBase[0]);
        $this->assertCount(8, $byBase[1]);
    }

    public function testTheLinesOfAnotherAccountNeverMakeAnIngredientReferenced(): void
    {
        // Given : le gros compte référence des spécifiques dans ses recettes ; le compte neuf, rien
        // Then
        $this->assertNotContains('ingredient-008', $this->slugs($this->repository->ingredientsToSeed($this->dataset->freshId, $this->dataset->ownerId, [])));
    }

    public function testAnIngredientTheAccountAlreadyAdoptedIsNotOfferedAgainButAnOrphanAliasDoesNotHideIt(): void
    {
        // Given : un alias valide sur le beurre (la copie existe et est à lui), un alias ORPHELIN sur la farine
        $user = $this->dataset->freshId;
        $connection = $this->dataset->adoption->connection;
        $now = date('Y-m-d H:i:s');
        $this->dataset->adoption->personalIngredient($user, 'Beurre de la maison', 'rayon-a');
        $connection->insert('ingredient_alias', ['user_id' => $user, 'alias_slug' => 'beurre', 'ingredient_slug' => 'beurre-de-la-maison' . $user, 'kind' => 'adoption', 'source' => 'ancien', 'created_at' => $now]);
        $connection->insert('ingredient_alias', ['user_id' => $user, 'alias_slug' => 'farine', 'ingredient_slug' => 'farine-disparue', 'kind' => 'adoption', 'source' => 'ancien', 'created_at' => $now]);

        // When
        $toSeed = $this->slugs($this->repository->ingredientsToSeed($user, $this->dataset->ownerId, []));

        // Then
        $this->assertNotContains('beurre', $toSeed);
        $this->assertContains('farine', $toSeed);
        $this->assertCount(7, $toSeed);
    }

    public function testAConformingCopyBornOfTheSourceIsOfferedAsExistingAndAModifiedOneIsNot(): void
    {
        // Given : une copie de recette qui a retenu l'origine et reste conforme au lait ; une autre, renommée, pour le sucre
        $user = $this->dataset->freshId;
        $connection = $this->dataset->adoption->connection;
        $milk = $connection->fetchAssociative("SELECT * FROM ingredient WHERE slug = 'lait'");
        $this->dataset->adoption->insert('ingredient', [...$milk, 'slug' => 'lait' . $user, 'creator' => $user, 'adopted_from' => 'lait', 'is_base' => false]);
        $sugar = $connection->fetchAssociative("SELECT * FROM ingredient WHERE slug = 'sucre'");
        $this->dataset->adoption->insert('ingredient', [...$sugar, 'slug' => 'sucre' . $user, 'name' => 'Sucre roux', 'creator' => $user, 'adopted_from' => 'sucre', 'is_base' => false]);

        // When
        $bySlug = [];
        foreach ($this->repository->ingredientsToSeed($user, $this->dataset->ownerId, []) as $ingredient) {
            $bySlug[$ingredient->slug] = $ingredient->existingCopySlug;
        }

        // Then
        $this->assertSame('lait' . $user, $bySlug['lait']);
        $this->assertNull($bySlug['sucre']);
        $this->assertNull($bySlug['beurre']);
    }

    public function testTheCopiesAreBornNotBaseCarryTheOriginAndTheOwnerAndIgnoreAnythingOutsideTheCatalog(): void
    {
        // Given : le beurre est « de base » ; un ingrédient personnel d'un autre compte est aussi demandé (un appel qui se tromperait)
        $user = $this->dataset->freshId;
        $connection = $this->dataset->adoption->connection;
        $this->dataset->adoption->personalIngredient($this->dataset->adoption->bigId, 'Secret du gros', 'rayon-a');
        $secret = 'secret-du-gros' . $this->dataset->adoption->bigId;
        $before = (int) $connection->fetchOne('SELECT count(*) FROM ingredient');

        // When
        $written = $this->repository->copyCatalogIngredients($user, $this->dataset->ownerId, ['beurre' => 'beurre' . $user, 'tomate' => 'tomate' . $user, $secret => 'secret' . $user]);

        // Then : deux copies seulement
        $this->assertSame(2, $written);
        $this->assertSame($before + 2, (int) $connection->fetchOne('SELECT count(*) FROM ingredient'));
        $copy = $connection->fetchAssociative("SELECT * FROM ingredient WHERE slug = ?", ['beurre' . $user]);
        $source = $connection->fetchAssociative("SELECT * FROM ingredient WHERE slug = 'beurre'");
        $this->assertTrue($source['is_base']);
        $this->assertFalse($copy['is_base'], 'une copie naît à « de base » faux, écrit explicitement');
        $this->assertSame($user, $copy['creator']);
        $this->assertSame('beurre', $copy['adopted_from']);
        foreach (['name', 'image', 'slugged_name', 'department', 'default_cooking_unit', 'default_purchase_unit', 'compatible_units'] as $column) {
            $this->assertSame($source[$column], $copy[$column], $column);
        }
        $this->assertFalse($connection->fetchOne("SELECT 1 FROM ingredient WHERE slug = ?", ['secret' . $user]));
        // La source n'a pas bougé
        $this->assertSame('beurre', $connection->fetchOne("SELECT slug FROM ingredient WHERE slug = 'beurre' AND creator = ? AND is_base AND adopted_from IS NULL", [$this->dataset->ownerId]));
    }

    /**
     * @param list<IngredientToSeed> $toSeed
     *
     * @return list<string>
     */
    private function slugs(array $toSeed): array
    {
        return array_map(static fn(IngredientToSeed $ingredient): string => $ingredient->slug, $toSeed);
    }
}
