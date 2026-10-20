<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Application\Loader\IngredientLoader;
use Repas\Repas\Application\Query\ListAvailableIngredients\IngredientOptionDTO;
use Repas\Repas\Application\Query\ListIngredientsByDepartment\IngredientRowDTO;
use Repas\Repas\Application\Query\SearchIngredients\IngredientSearchResultDTO;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * La règle de visibilité des ingrédients (ADR 0028 décision 3, `IngredientVisibility`) sur la vraie base, pour TOUTES les lectures qui
 * la portent : un commun que l'utilisateur a adopté est masqué pour lui et pour lui seul ; il reste visible de tous les autres, des
 * administrateurs (qui n'ont aucun alias) et de qui n'a rien adopté. Les communs sont `tomate`, `oignon` et `carotte`, dans le même rayon.
 *
 *  - `adopter` a adopté `tomate` : il a sa copie, et l'alias ;
 *  - `orphan` a un alias pour `oignon` dont la copie n'existe plus (l'ancien code l'a supprimée) : `oignon` n'est pas masqué ;
 *  - `stranger` a un alias pour `carotte` vers la ligne de quelqu'un d'autre : donnée incohérente, `carotte` n'est pas masquée ;
 *  - `plain` et `admin` n'ont aucun alias.
 */
class IngredientVisibilityTest extends DatabaseTestCase
{
    private IngredientLoader $ingredientLoader;
    private IngredientRepository $ingredientRepository;
    private IngredientAliasRepository $aliasRepository;
    private IngredientMatchCandidates $matchCandidates;
    private DataPersister $persister;

    private User $adopter;
    private User $orphan;
    private User $stranger;
    private User $plain;
    private User $admin;

    private Ingredient $tomate;
    private Ingredient $oignon;
    private Ingredient $carotte;
    private Ingredient $adopterCopy;
    private Ingredient $adopterOwn;
    private Ingredient $plainOwn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientLoader = static::getContainer()->get(IngredientLoader::class);
        $this->ingredientRepository = static::getContainer()->get(IngredientRepository::class);
        $this->aliasRepository = static::getContainer()->get(IngredientAliasRepository::class);
        $this->matchCandidates = static::getContainer()->get(IngredientMatchCandidates::class);
        $this->persister = new DataPersister(static::getContainer());

        $this->adopter = $this->savedUser();
        $this->orphan = $this->savedUser();
        $this->stranger = $this->savedUser();
        $this->plain = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);

        $this->tomate = $this->savedCommon('tomate');
        $this->oignon = $this->savedCommon('oignon');
        $this->carotte = $this->savedCommon('carotte');
        $this->adopterCopy = $this->savedOwn('tomate', $this->adopter, adoptedFrom: 'tomate');
        $this->adopterOwn = $this->savedOwn('poireau', $this->adopter);
        $this->plainOwn = $this->savedOwn('radis', $this->plain);
        $this->alias($this->adopter, 'tomate', $this->adopterCopy->getSlug());
        $this->alias($this->orphan, 'oignon', 'oignon-disparu-' . $this->orphan->getId());
        $this->alias($this->stranger, 'carotte', $this->plainOwn->getSlug());
    }

    public function testTheAdopterNoLongerSeesTheCommonHeAdoptedButSeesItsCopyAndTheOthers(): void
    {
        // Then : le rayon, le choix pour une recette, la recherche et le port de domaine disent la même chose
        $expected = [$this->carotte->getSlug(), $this->oignon->getSlug(), $this->adopterOwn->getSlug(), $this->adopterCopy->getSlug()];
        $this->assertVisibleSlugs($expected, $this->adopter);
        $this->assertNotContains($this->tomate->getSlug(), $this->visibleInTheDepartment($this->adopter));
    }

    public function testSearchingTheAdoptedIngredientGivesOneResultForTheAdopterAndOneForEveryoneElse(): void
    {
        // When
        $forTheAdopter = $this->ingredientLoader->search('tomate', $this->adopter->getId(), false);
        $forAnother = $this->ingredientLoader->search('tomate', $this->plain->getId(), false);
        $forTheAdmin = $this->ingredientLoader->search('tomate', $this->admin->getId(), true);

        // Then : sa copie pour celui qui a adopté, le commun pour les autres (jamais les deux)
        $slugOf = static fn(array $results): array => array_map(static fn(IngredientSearchResultDTO $r): string => $r->slug, $results);
        $this->assertSame([$this->adopterCopy->getSlug()], $slugOf($forTheAdopter));
        $this->assertSame([$this->tomate->getSlug()], $slugOf($forAnother));
        $this->assertSame([$this->tomate->getSlug()], $slugOf($forTheAdmin));
        $this->assertTrue($forTheAdopter[0]->canEdit, 'la copie est modifiable par son propriétaire');
        $this->assertFalse($forAnother[0]->canEdit);
        $this->assertTrue($forTheAdmin[0]->canEdit, 'un administrateur modifie les communs');
    }

    public function testEveryoneElseKeepsTheCommonsAndNeverSeesTheCopyOfAnother(): void
    {
        // Then
        $commons = [$this->carotte->getSlug(), $this->oignon->getSlug(), $this->tomate->getSlug()];
        $this->assertVisibleSlugs([...$commons, $this->plainOwn->getSlug()], $this->plain);
        $this->assertVisibleSlugs($commons, $this->admin);
    }

    public function testAnAliasWhoseCopyNoLongerExistsHidesNothing(): void
    {
        // Then : le commun reste visible et utilisable, comme avant l'adoption
        $this->assertVisibleSlugs([$this->carotte->getSlug(), $this->oignon->getSlug(), $this->tomate->getSlug()], $this->orphan);
        $this->assertSame($this->oignon->getDefaultPurchaseUnit()->getSlug(), $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->oignon->getSlug(), $this->orphan->getId()));
    }

    public function testAnAliasToTheIngredientOfSomeoneElseHidesNothing(): void
    {
        // Then
        $this->assertContains($this->carotte->getSlug(), $this->visibleInTheDepartment($this->stranger));
        $this->assertNotContains($this->plainOwn->getSlug(), $this->visibleInTheDepartment($this->stranger));
    }

    public function testTheSheetOfAnAdoptedCommonIsStillReadableInReadOnly(): void
    {
        // When : une page ouverte avant l'adoption doit pouvoir s'afficher
        $sheet = $this->ingredientLoader->get($this->tomate->getSlug(), $this->adopter->getId(), false);

        // Then
        $this->assertSame([$this->tomate->getSlug(), false], [$sheet->slug, $sheet->canEdit]);
        $this->assertNull($sheet->creatorId);
    }

    public function testACommonAdoptedByAnotherUserIsStillUsableByTheOthers(): void
    {
        // Then : poser `tomate` dans la liste de quelqu'un qui ne l'a pas adopté reste possible, pas dans celle de l'adoptant
        $unit = $this->tomate->getDefaultPurchaseUnit()->getSlug();
        $this->assertSame($unit, $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->tomate->getSlug(), $this->plain->getId()));
        $this->assertSame($unit, $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->tomate->getSlug(), $this->admin->getId()));
        $this->assertSame($unit, $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->adopterCopy->getSlug(), $this->adopter->getId()));

        try {
            $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->tomate->getSlug(), $this->adopter->getId());
            $this->fail("Un commun adopté n'est plus utilisable tel quel : le cas d'usage résout le slug avant.");
        } catch (IngredientException $exception) {
            $this->assertEquals(IngredientException::notFound($this->tomate->getSlug()), $exception);
        }
    }

    public function testTheCopyOfAnotherUserIsNeverUsable(): void
    {
        // Then
        $this->expectExceptionObject(IngredientException::notFound($this->adopterCopy->getSlug()));

        // When
        $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->adopterCopy->getSlug(), $this->plain->getId());
    }

    public function testUsableByCostsOneQueryEvenThoughTheRuleLooksAtTheAliases(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->tomate->getSlug(), $this->plain->getId());

        // Then
        $this->assertSame(1, $counter->count());
    }

    public function testFindCommonSlugsKeepsOnlyTheCommonsInOneQuery(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $commons = $this->ingredientRepository->findCommonSlugs([$this->tomate->getSlug(), $this->adopterCopy->getSlug(), 'inconnu', $this->carotte->getSlug(), $this->tomate->getSlug()]);

        // Then : ni un ingrédient personnel (même une adoption), ni un slug inconnu
        sort($commons);
        $this->assertSame([$this->carotte->getSlug(), $this->tomate->getSlug()], $commons);
        $this->assertSame(1, $counter->count());
        $this->assertSame([], $this->ingredientRepository->findCommonSlugs([]));
    }

    public function testTheRuleAgreesWithTheAliasResolutionForEveryUserAndEveryCommon(): void
    {
        // Given : la règle de visibilité (DQL) et la résolution des alias (SQL) définissent un alias valide chacune de leur côté
        $commons = [$this->tomate->getSlug(), $this->oignon->getSlug(), $this->carotte->getSlug()];

        foreach ([$this->adopter, $this->orphan, $this->stranger, $this->plain, $this->admin] as $user) {
            // When
            $masked = array_keys($this->aliasRepository->resolve($user->getId(), $commons));
            $visibleCommons = array_values(array_intersect($this->visibleInTheDepartment($user), $commons));

            // Then
            sort($visibleCommons);
            $expected = array_values(array_diff($commons, $masked));
            sort($expected);
            $this->assertSame($expected, $visibleCommons, 'les communs visibles sont ceux sans alias valide, pour ' . $user->getId());
        }
    }

    public function testWithoutAnyAliasEveryReadGivesExactlyWhatTheOldRuleGave(): void
    {
        // Given : le jeu de données sans aucun alias (la situation de la production avant la migration), des ingrédients de plusieurs utilisateurs
        $this->entityManager->getConnection()->executeStatement('DELETE FROM ingredient_alias');

        foreach ([$this->adopter, $this->orphan, $this->plain, $this->admin] as $user) {
            // When
            $old = $this->fetchSlugs('SELECT slug FROM ingredient WHERE creator = ? OR creator IS NULL', [$user->getId()]);
            $oldInTheDepartment = $this->fetchSlugs("SELECT slug FROM ingredient WHERE department = 'cereale' AND (creator = ? OR creator IS NULL)", [$user->getId()]);

            // Then : « creator = moi ou vide », ni plus ni moins, partout
            $this->assertSame($old, $this->slugsOfOptions($this->ingredientLoader->listAvailable($user->getId())), 'GET /ingredients');
            $this->assertSame($oldInTheDepartment, $this->visibleInTheDepartment($user), 'rayon');
            $this->assertSame($old, $this->slugsOfRepository($this->ingredientRepository->findByOwner($user->getId())), 'findByOwner');
            $this->assertSame($old, $this->slugsOfCandidates($user), 'candidats du rapprochement');
            $department = new DepartmentBuilder()->isCereal()->build();
            $this->assertSame($oldInTheDepartment, $this->slugsOfRepository($this->ingredientRepository->findByDepartmentAndOwner($department, $user->getId())), 'findByDepartmentAndOwner');
            foreach (['tomate', 'oignon', 'carotte', 'poireau', 'radis'] as $needle) {
                $searched = array_map(static fn(IngredientSearchResultDTO $r): string => $r->slug, $this->ingredientLoader->search($needle, $user->getId(), $user === $this->admin));
                $oldSearch = array_values(array_filter($old, static fn(string $slug): bool => str_starts_with($slug, $needle)));
                sort($searched);
                $this->assertSame($oldSearch, $searched, 'recherche « ' . $needle . ' »');
            }
        }
    }

    /**
     * Ce que le rayon, le choix pour une recette, la recherche et le port de domaine voient, comparés au même ensemble attendu.
     *
     * @param list<string> $expected
     */
    private function assertVisibleSlugs(array $expected, User $user): void
    {
        sort($expected);
        $department = new DepartmentBuilder()->isCereal()->build();

        $this->assertSame($expected, $this->visibleInTheDepartment($user), 'rayon');
        $this->assertSame($expected, $this->slugsOfOptions($this->ingredientLoader->listAvailable($user->getId())), 'choix pour une recette');
        $this->assertSame($expected, $this->slugsOfRepository($this->ingredientRepository->findByOwner($user->getId())), 'findByOwner');
        $this->assertSame($expected, $this->slugsOfRepository($this->ingredientRepository->findByDepartmentAndOwner($department, $user->getId())), 'findByDepartmentAndOwner');
        $this->assertSame($expected, $this->slugsOfCandidates($user), 'candidats du rapprochement à la copie d\'une recette');
        $found = [];
        foreach (['tomate', 'oignon', 'carotte', 'poireau', 'radis'] as $needle) {
            foreach ($this->ingredientLoader->search($needle, $user->getId(), $user === $this->admin) as $result) {
                $found[$result->slug] = true;
            }
        }
        $found = array_keys($found);
        sort($found);
        $this->assertSame($expected, $found, 'recherche');
    }

    /**
     * @return list<string> les slugs du rayon, triés
     */
    private function visibleInTheDepartment(User $user): array
    {
        $slugs = array_map(
            static fn(IngredientRowDTO $row): string => $row->slug,
            $this->ingredientLoader->listByDepartment('cereale', $user->getId(), $user === $this->admin)->ingredients,
        );
        sort($slugs);

        return $slugs;
    }

    /**
     * @param list<IngredientOptionDTO> $options
     * @return list<string>
     */
    private function slugsOfOptions(array $options): array
    {
        $slugs = array_map(static fn(IngredientOptionDTO $option): string => $option->slug, $options);
        sort($slugs);

        return $slugs;
    }

    /**
     * @param iterable<Ingredient> $ingredients
     * @return list<string>
     */
    private function slugsOfRepository(iterable $ingredients): array
    {
        $slugs = [];
        foreach ($ingredients as $ingredient) {
            $slugs[] = $ingredient->getSlug();
        }
        sort($slugs);

        return $slugs;
    }

    /**
     * @return list<string>
     */
    private function slugsOfCandidates(User $user): array
    {
        $slugs = array_map(static fn(\Repas\Repas\Domain\Model\IngredientCandidate $candidate): string => $candidate->slug, $this->matchCandidates->visibleTo($user->getId()));
        sort($slugs);

        return $slugs;
    }

    /**
     * @param list<string> $parameters
     * @return list<string>
     */
    private function fetchSlugs(string $sql, array $parameters): array
    {
        $slugs = $this->entityManager->getConnection()->fetchFirstColumn($sql, $parameters);
        sort($slugs);

        return $slugs;
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function savedCommon(string $name): Ingredient
    {
        return $this->saved(new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isCereal()));
    }

    private function savedOwn(string $name, User $creator, ?string $adoptedFrom = null): Ingredient
    {
        return $this->saved(new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($creator, true)->withAdoptedFrom($adoptedFrom));
    }

    private function saved(IngredientBuilder $builder): Ingredient
    {
        $ingredient = $builder->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function alias(User $user, string $aliasSlug, string $ingredientSlug): void
    {
        $this->aliasRepository->save(IngredientAlias::create($user->getId(), $aliasSlug, $ingredientSlug, AliasKind::ADOPTION, 'first-use', new DateTimeImmutable('2026-10-21 03:00:00')));
    }
}
