<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\IngredientDuplicateLoader;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;
use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientSideDTO;
use Repas\Repas\Domain\Interface\IngredientDistinctRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\IngredientDistinctPair;
use Repas\Repas\Domain\Service\DuplicateIngredientFinder;
use Repas\Tests\Helper\AdoptionDataset;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\MergeDataset;
use Repas\Tests\Helper\PerformanceReport;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les doublons possibles d'un utilisateur sur la vraie base (ING-006) : chaque règle, puis un jeu de la taille de la production
 * (106 ingrédients personnels et 261 adoptés) pour prouver que la lecture coûte un nombre FIXE de requêtes. Les ingrédients sont écrits en
 * SQL direct (rapide) ; valeurs factices uniquement, aucun nom d'ingrédient personnel réel.
 */
class IngredientDuplicateLoaderTest extends DatabaseTestCase
{
    private IngredientDuplicateLoader $loader;
    private IngredientDistinctRepository $distinct;
    private MergeDataset $dataset;
    private AdoptionDataset $sql;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $persister = new DataPersister(static::getContainer());
        $this->loader = static::getContainer()->get(IngredientDuplicateLoader::class);
        $this->distinct = static::getContainer()->get(IngredientDistinctRepository::class);
        $this->dataset = new MergeDataset($this->entityManager, $persister);
        $this->sql = new AdoptionDataset($this->entityManager, $persister);
        $this->dataset->units();
        $this->user = $this->dataset->user();
        $this->other = new UserBuilder()->build();
        $persister->saveUser($this->other);
        $this->department('fruit-et-legume', 'Fruit & légume');
        $this->department('surgele', 'Surgelé');
        $this->department('epicerie', 'Épicerie');
    }

    private function department(string $slug, string $name): void
    {
        $this->sql->insert('department', ['slug' => $slug, 'name' => $name, 'image' => "images/$slug.png"]);
    }

    /**
     * Un ingrédient en SQL direct, au kilo. `$owner` vide : un commun.
     */
    private function ingredient(string $slug, string $name, string $department = 'fruit-et-legume', ?User $owner = null, ?string $adoptedFrom = null, bool $common = false): string
    {
        $owner ??= $common ? null : $this->user;
        $this->sql->insert('ingredient', [
            'slug' => $slug,
            'name' => $name,
            'image' => 'images/x.png',
            'slugged_name' => $slug,
            'department' => $department,
            'default_cooking_unit' => 'gramme',
            'default_purchase_unit' => 'kilo',
            'creator' => $owner?->getId(),
            'compatible_units' => '["gramme","kilo"]',
            'adopted_from' => $adoptedFrom,
        ]);

        return $slug;
    }

    /**
     * @return list<DuplicateIngredientDTO>
     */
    private function duplicates(?User $user = null): array
    {
        return $this->loader->duplicates(($user ?? $this->user)->getId());
    }

    /**
     * La paire de deux slugs, dans l'ordre des octets (celui de la table) : un identifiant d'utilisateur aléatoire change l'ordre de « pate-<id> » et de « pate-2-<id> ».
     */
    private static function pairOf(string $slug, string $other): string
    {
        return implode('+', IngredientDistinctPair::ordered($slug, $other));
    }

    /**
     * @param list<DuplicateIngredientDTO> $duplicates
     * @return list<string>
     */
    private static function pairs(array $duplicates): array
    {
        return array_map(static fn(DuplicateIngredientDTO $duplicate): string => $duplicate->first->slug . '+' . $duplicate->second->slug, $duplicates);
    }

    public function testTwoIngredientsOfTheSameNameInTheSameDepartmentAreProposedWithEverythingTheCardNeeds(): void
    {
        // Given : « Tomate » dans 3 recettes (dont une avec deux lignes) et 2 listes, « Tomates » dans 1 recette et 1 liste
        $a = $this->ingredient('tomate-u', 'Tomate');
        $b = $this->ingredient('tomates-u', 'Tomates');
        $user = $this->user->getId();
        $this->dataset->recipe('r1', $user, [[$a, 1.0, 'kilo'], [$a, 2.0, 'unite']]);
        $this->dataset->recipe('r2', $user, [[$a, 1.0, 'kilo']]);
        $this->dataset->recipe('r3', $user, [[$a, 1.0, 'kilo'], [$b, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l1', $user, rows: [['x1', $a, 1.0, 'kilo']]);
        $this->dataset->shoppingList('l2', $user, items: [['y2', $a, 2.0, 'kilo']]);
        $this->dataset->shoppingList('l3', $user, rows: [['x3', $b, 1.0, 'kilo'], ['x4', $a, 1.0, 'kilo']]);

        // When
        $duplicates = $this->duplicates();

        // Then
        $this->assertEquals([new DuplicateIngredientDTO(
            first: new DuplicateIngredientSideDTO('tomate-u', 'Tomate', 'fruit-et-legume', 'Fruit & légume', 'kilo', 3, 3),
            second: new DuplicateIngredientSideDTO('tomates-u', 'Tomates', 'fruit-et-legume', 'Fruit & légume', 'kilo', 1, 1),
            score: 1.0,
            level: 'EXACT',
            suggestedKeep: 'tomate-u',
        )], $duplicates);
    }

    public function testTheSuggestedKeepIsTheMostUsedOneThenTheShortestName(): void
    {
        // Given : « Tomates » est plus utilisée ; « Poireau » et « Poireaux » ne le sont pas du tout
        $this->ingredient('tomate-u', 'Tomate');
        $this->ingredient('tomates-u', 'Tomates');
        $this->ingredient('poireau-u', 'Poireau');
        $this->ingredient('poireaux-u', 'Poireaux');
        $this->dataset->recipe('r1', $this->user->getId(), [['tomates-u', 1.0, 'kilo']]);

        // When
        $keeps = [];
        foreach ($this->duplicates() as $duplicate) {
            $keeps[$duplicate->first->slug] = $duplicate->suggestedKeep;
        }

        // Then
        $this->assertSame(['poireau-u' => 'poireau-u', 'tomate-u' => 'tomates-u'], $keeps);
    }

    public function testATypoAndASuggestionCarryTheirLevelAndScoreAndTheBestComeFirst(): void
    {
        // Given
        $this->ingredient('mozzarella-u', 'Mozzarella');
        $this->ingredient('mozzerella-u', 'Mozzerella');
        $this->ingredient('concombre-u', 'Concombre');
        $this->ingredient('concombrre-u', 'Concombrre');
        $this->ingredient('tomate-u', 'Tomate');
        $this->ingredient('tomates-u', 'Tomates');

        // When
        $duplicates = $this->duplicates();

        // Then : exact (1), faute (≥ 0,97), suggestion (de 0,90 à 0,97)
        $this->assertSame(['tomate-u+tomates-u', 'concombre-u+concombrre-u', 'mozzarella-u+mozzerella-u'], self::pairs($duplicates));
        $this->assertSame(['EXACT', 'TYPO', 'SUGGESTION'], array_map(static fn(DuplicateIngredientDTO $d): string => $d->level, $duplicates));
        $this->assertSame(1.0, $duplicates[0]->score);
        $this->assertGreaterThanOrEqual(0.97, $duplicates[1]->score);
        $this->assertGreaterThanOrEqual(0.90, $duplicates[2]->score);
        $this->assertLessThan(0.97, $duplicates[2]->score);
    }

    public function testAQualifiedNameIsNotProposed(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate');
        $this->ingredient('tomate-cerise-u', 'Tomate cerise');
        $this->ingredient('poivre-u', 'Poivre', 'epicerie');
        $this->ingredient('poivre-noir-u', 'Poivre noir', 'epicerie');

        // When / Then
        $this->assertSame([], $this->duplicates());
    }

    public function testIngredientsOfDifferentDepartmentsAreNotProposed(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate', 'fruit-et-legume');
        $this->ingredient('tomates-u', 'Tomates', 'surgele');

        // When / Then
        $this->assertSame([], $this->duplicates());
    }

    public function testACommonIngredientIsNeverProposedNotEvenAgainstTheOnesOfTheUser(): void
    {
        // Given : un commun « Tomate » et un « Tomates » à l'utilisateur ; deux communs entre eux
        $this->ingredient('tomate', 'Tomate', common: true);
        $this->ingredient('tomates-c', 'Tomates', common: true);
        $this->ingredient('tomates-u', 'Tomates');

        // When / Then
        $this->assertSame([], $this->duplicates());
    }

    public function testTheIngredientOfAnotherUserIsNeverProposedAndNeverShown(): void
    {
        // Given : l'autre utilisateur a « Tomate » et « Tomates » ; l'utilisateur a « Tomate »
        $this->ingredient('tomate-o', 'Tomate', owner: $this->other);
        $this->ingredient('tomates-o', 'Tomates', owner: $this->other);
        $this->ingredient('tomate-u', 'Tomate');

        // When / Then : aucune paire pour l'utilisateur, et pour l'autre la sienne seulement
        $this->assertSame([], $this->duplicates());
        $this->assertSame(['tomate-o+tomates-o'], self::pairs($this->duplicates($this->other)));
    }

    public function testTwoAdoptionsOfDifferentCommonsAreNeverProposedButTwoOfTheSameCommonAre(): void
    {
        // Given : l'administrateur a voulu « confiture fraise » et « confiture fraises » distincts ; deux adoptions du même commun (« pate » et « pate-2 »)
        $this->ingredient('confiture-fraise-u', 'Confiture fraise', 'epicerie', adoptedFrom: 'confiture-fraise');
        $this->ingredient('confiture-fraises-u', 'Confiture fraises', 'epicerie', adoptedFrom: 'confiture-fraises');
        $this->ingredient('pate-u', 'Pâte', 'epicerie', adoptedFrom: 'pate');
        $this->ingredient('pate-2-u', 'Pâte', 'epicerie', adoptedFrom: 'pate');

        // When / Then
        $this->assertSame(['pate-2-u+pate-u'], self::pairs($this->duplicates()));
    }

    public function testAnAdoptionAndAnIngredientTheUserCreatedAreProposed(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate', adoptedFrom: 'tomate');
        $this->ingredient('tomates-u', 'Tomates');

        // When / Then
        $this->assertSame(['tomate-u+tomates-u'], self::pairs($this->duplicates()));
    }

    public function testAPairTheUserDismissedIsNeverProposedAgainInAnyOrderEvenAfterARename(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate');
        $this->ingredient('tomates-u', 'Tomates');
        $this->ingredient('courgette-u', 'Courgette');
        $this->assertSame(['tomate-u+tomates-u'], self::pairs($this->duplicates()));

        // When : « Pas le même », dit dans l'ordre inverse de celui de la table
        $this->distinct->save(IngredientDistinctPair::create($this->user->getId(), 'tomates-u', 'tomate-u', new DateTimeImmutable()));

        // Then
        $this->assertSame([], $this->duplicates());

        // When : l'un des deux est renommé en un nom encore plus proche (le slug, lui, ne change pas)
        $ingredients = static::getContainer()->get(IngredientRepository::class);
        $tomatoes = $ingredients->findOneBySlug('tomates-u');
        $tomatoes->update('Tomate', $tomatoes->getImage(), $tomatoes->getDepartment(), $tomatoes->getDefaultCookingUnit(), $tomatoes->getDefaultPurchaseUnit(), $tomatoes->getCompatibleUnits());
        $ingredients->save($tomatoes);
        $this->entityManager->clear();

        // Then : jamais reproposée
        $this->assertSame([], $this->duplicates());
    }

    public function testADismissedPairOnlyHidesThatPairAndOnlyForThatUser(): void
    {
        // Given : trois « Tomate » pour l'utilisateur (trois paires), la même paire pour l'autre ; seule la paire a/b est écartée par l'utilisateur
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->ingredient("tomate-$suffix-u", 'Tomate');
            $this->ingredient("tomate-$suffix-o", 'Tomate', owner: $this->other);
        }
        $this->distinct->save(IngredientDistinctPair::create($this->user->getId(), 'tomate-a-u', 'tomate-b-u', new DateTimeImmutable()));
        $this->distinct->save(IngredientDistinctPair::create($this->other->getId(), 'tomate-a-u', 'tomate-c-u', new DateTimeImmutable()));

        // When / Then
        $this->assertSame(['tomate-a-u+tomate-c-u', 'tomate-b-u+tomate-c-u'], self::pairs($this->duplicates()));
        $this->assertCount(3, $this->duplicates($this->other), 'La mémoire d\'un utilisateur n\'est jamais celle d\'un autre.');
    }

    public function testTheOrderIsTheSameAtEveryCallAndTiesAreBrokenBySlugs(): void
    {
        // Given : quatre « Tomate » = six paires à égalité de score, insérées dans le désordre
        foreach (['d', 'b', 'a', 'c'] as $suffix) {
            $this->ingredient("tomate-$suffix-u", 'Tomate');
        }
        $this->ingredient('concombre-u', 'Concombre');
        $this->ingredient('concombrre-u', 'Concombrre');

        // When
        $first = self::pairs($this->duplicates());

        // Then
        $this->assertSame([
            'tomate-a-u+tomate-b-u', 'tomate-a-u+tomate-c-u', 'tomate-a-u+tomate-d-u', 'tomate-b-u+tomate-c-u', 'tomate-b-u+tomate-d-u', 'tomate-c-u+tomate-d-u',
            'concombre-u+concombrre-u',
        ], $first);
        $this->assertSame($first, self::pairs($this->duplicates()));
    }

    public function testAnIngredientWithoutADuplicateDoesNotAppear(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate');
        $this->ingredient('tomates-u', 'Tomates');
        $this->ingredient('courgette-u', 'Courgette');

        // When
        $slugs = [];
        foreach ($this->duplicates() as $duplicate) {
            $slugs[] = $duplicate->first->slug;
            $slugs[] = $duplicate->second->slug;
        }

        // Then
        $this->assertNotContains('courgette-u', $slugs);
    }

    public function testTheListIsBoundedToFiftyPairs(): void
    {
        // Given : 60 paires « nom / nom au pluriel », des noms à chiffres qui ne se ressemblent pas entre eux
        for ($i = 0; $i < 60; ++$i) {
            $name = 'z' . substr(md5("pair-$i"), 0, 10);
            $this->ingredient("a-$i-u", $name);
            $this->ingredient("b-$i-u", $name . 's');
        }

        // When
        $duplicates = $this->duplicates();

        // Then
        $this->assertCount(DuplicateIngredientFinder::LIMIT, $duplicates);
    }

    public function testAnAccountWithoutAnyIngredientOfItsOwnHasAnEmptyListNotAnError(): void
    {
        // Given : les communs ne sont à personne ; l'administrateur n'a que des communs
        $this->ingredient('tomate', 'Tomate', common: true);
        $this->ingredient('tomates', 'Tomates', common: true);
        $admin = new UserBuilder()->isAdmin()->build();
        $this->dataset->user();
        (new DataPersister(static::getContainer()))->saveUser($admin);

        // When / Then
        $this->assertSame([], $this->duplicates($admin));
        $this->assertSame([], $this->loader->duplicates('un-utilisateur-sans-rien'));
    }

    public function testAUserWithASingleIngredientHasNothingToCompare(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate');

        // When
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // Then : une seule lecture, et pas la peine de lire ses « Pas le même »
        $this->assertSame([], $this->duplicates());
        $this->assertSame(1, $counter->count());
    }

    public function testNothingIsWritten(): void
    {
        // Given
        $this->ingredient('tomate-u', 'Tomate');
        $this->ingredient('tomates-u', 'Tomates');
        $before = $this->sql->tableHashes();

        // When
        $this->duplicates();

        // Then
        $this->assertSame($before, $this->sql->tableHashes());
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient_distinct'));
    }

    // --- Un jeu de la taille de la production ---------------------------------------------------------------------------------------

    /**
     * Un compte comme le plus gros de la production : 106 ingrédients personnels et 261 adoptés (254 communs du catalogue de référence,
     * dont 7 ont une seconde adoption au slug suffixé, « pate » et « pate-2 »), des recettes et des listes. Retourne les paires plantées.
     *
     * @return list<string>
     */
    private function productionLikeAccount(): array
    {
        $this->sql->connection->executeStatement('DELETE FROM department');
        foreach (json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/src/Repas/Infrastructure/DataFixture/Data/department.json'), true, flags: JSON_THROW_ON_ERROR) as $department) {
            $this->department($department['slug'], $department['name']);
        }
        $commons = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/src/Repas/Infrastructure/DataFixture/Data/ingredient.json'), true, flags: JSON_THROW_ON_ERROR);
        $suffixed = ['pate', 'oeuf', 'sel', 'sucre', 'farine', 'beurre', 'lait'];

        $adopted = 0;
        $planted = [];
        foreach (array_slice($commons, 0, 254) as $common) {
            $slug = $common['slug'] . '-' . $this->user->getId();
            $this->ingredient($slug, $common['name'], $common['department'], adoptedFrom: $common['slug']);
            ++$adopted;
            if (in_array($common['slug'], $suffixed, true)) {
                $second = $common['slug'] . '-2-' . $this->user->getId();
                $this->ingredient($second, $common['name'], $common['department'], adoptedFrom: $common['slug']);
                ++$adopted;
                $planted[] = self::pairOf($second, $slug);
            }
        }
        $this->assertSame(261, $adopted, 'Le jeu doit avoir 261 ingrédients adoptés.');

        // Les 106 personnels : des noms à chiffres qui ne se ressemblent pas, dont un pluriel de « Concombre » et une faute de « Aubergine »
        $personal = 0;
        $this->ingredient('concombres-p', 'Concombres', 'fruit-et-legume');
        $this->ingredient('aubergnie-p', 'Aubergnie', 'fruit-et-legume');
        $personal += 2;
        $planted[] = self::pairOf('aubergine-' . $this->user->getId(), 'aubergnie-p');
        $planted[] = self::pairOf('concombre-' . $this->user->getId(), 'concombres-p');
        for ($i = $personal; $i < 106; ++$i) {
            $this->ingredient("perso-$i-u", 'q' . substr(md5("perso-$i"), 0, 9), $commons[$i % count($commons)]['department']);
        }

        $user = $this->user->getId();
        for ($i = 0; $i < 40; ++$i) {
            $rows = [];
            for ($j = 0; $j < 6; ++$j) {
                $rows[] = [$commons[($i * 7 + $j) % 254]['slug'] . '-' . $user, 1.0 + $j, 'kilo'];
            }
            $this->dataset->recipe("rec-$i", $user, $rows);
        }
        for ($i = 0; $i < 12; ++$i) {
            $this->dataset->shoppingList("list-$i", $user, rows: [["row-$i-a", $commons[$i]['slug'] . '-' . $user, 1.0, 'kilo'], ["row-$i-b", 'perso-' . (10 + $i) . '-u', 1.0, 'kilo']]);
        }

        return $planted;
    }

    public function testAProductionSizedAccountGetsItsSevenSuffixedAdoptionsAndTheOtherPlantedPairsAndNothingElse(): void
    {
        // Given
        $planted = $this->productionLikeAccount();
        $this->assertSame(367, (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM ingredient WHERE creator = ?', [$this->user->getId()]));

        // When
        $duplicates = $this->duplicates();

        // Then : 9 paires, pas une de plus (« pate » et « pate-2 » comprises)
        $actual = self::pairs($duplicates);
        sort($actual);
        sort($planted);
        $this->assertSame($planted, $actual);
        $this->assertCount(9, $duplicates);
        $this->assertContains(self::pairOf('pate-' . $this->user->getId(), 'pate-2-' . $this->user->getId()), $actual);
        $byLevel = array_count_values(array_map(static fn(DuplicateIngredientDTO $duplicate): string => $duplicate->level, $duplicates));
        ksort($byLevel);
        $this->assertSame(['EXACT' => 8, 'TYPO' => 1], $byLevel);
    }

    public function testTheReadCostsAFixedNumberOfQueriesWhateverTheNumberOfIngredients(): void
    {
        // Given : le gros compte
        $this->productionLikeAccount();
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $big = $this->duplicates();
        $bigQueries = $counter->count();

        // Then : trois lectures (les ingrédients, les paires écartées, les nombres de recettes et de listes)
        $this->assertNotEmpty($big);
        $this->assertSame(3, $bigQueries, implode("\n", $counter->statements()));

        // Given : un petit compte, avec une paire
        $this->ingredient('tomate-s', 'Tomate', owner: $this->other);
        $this->ingredient('tomates-s', 'Tomates', owner: $this->other);
        $counter->reset();

        // When
        $this->assertCount(1, $this->duplicates($this->other));

        // Then : le même nombre
        $this->assertSame(3, $counter->count());
    }

    public function testTheProductionSizedReadIsFast(): void
    {
        // Given
        $this->productionLikeAccount();
        $this->duplicates();

        // When : le meilleur de cinq lectures (la base et le conteneur sont partagés avec d'autres processus)
        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < 5; ++$i) {
            $start = hrtime(true);
            $this->duplicates();
            $best = min($best, (hrtime(true) - $start) / 1e6);
        }

        // Then : quelques dizaines de millisecondes visées ; la marge protège d'une machine chargée, pas d'une boucle sur tous les couples
        PerformanceReport::line(sprintf("\n[ING-006] 367 ingrédients, doublons possibles : %.1f ms\n", $best));
        $this->assertLessThan(PerformanceReport::allowed(200.0), $best);
    }
}
