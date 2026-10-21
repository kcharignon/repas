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
 * LA règle de visibilité des ingrédients (ADR 0028, amendement du 2026-10-20, `IngredientVisibility`) sur la vraie base, pour TOUTES les
 * lectures qui la portent : un ingrédient est visible par son créateur, et par lui seul. Aucun ingrédient commun, aucun passe-droit
 * d'administrateur, aucun masquage par alias.
 *
 *  - `owner` est le propriétaire du catalogue (administrateur) : `tomate`, `oignon` et `carotte` sont à lui, au slug du nom seul ;
 *  - `copier` a reçu une copie de `tomate` (et son alias) et a créé `poireau` ;
 *  - `plain` a créé `radis`, `admin` est un autre administrateur qui a créé `navet` ;
 *  - `empty` n'a rien.
 */
class IngredientVisibilityTest extends DatabaseTestCase
{
    private IngredientLoader $ingredientLoader;
    private IngredientRepository $ingredientRepository;
    private IngredientAliasRepository $aliasRepository;
    private IngredientMatchCandidates $matchCandidates;
    private DataPersister $persister;

    private User $owner;
    private User $copier;
    private User $plain;
    private User $admin;
    private User $empty;

    private Ingredient $tomate;
    private Ingredient $oignon;
    private Ingredient $carotte;
    private Ingredient $copierTomate;
    private Ingredient $copierPoireau;
    private Ingredient $plainRadis;
    private Ingredient $adminNavet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredientLoader = static::getContainer()->get(IngredientLoader::class);
        $this->ingredientRepository = static::getContainer()->get(IngredientRepository::class);
        $this->aliasRepository = static::getContainer()->get(IngredientAliasRepository::class);
        $this->matchCandidates = static::getContainer()->get(IngredientMatchCandidates::class);
        $this->persister = new DataPersister(static::getContainer());

        $this->owner = $this->savedUser(admin: true);
        $this->copier = $this->savedUser();
        $this->plain = $this->savedUser();
        $this->admin = $this->savedUser(admin: true);
        $this->empty = $this->savedUser();

        $this->tomate = $this->saved('tomate', $this->owner, ownSlug: false);
        $this->oignon = $this->saved('oignon', $this->owner, ownSlug: false);
        $this->carotte = $this->saved('carotte', $this->owner, ownSlug: false);
        $this->copierTomate = $this->saved('tomate', $this->copier, adoptedFrom: 'tomate');
        $this->copierPoireau = $this->saved('poireau', $this->copier);
        $this->plainRadis = $this->saved('radis', $this->plain);
        $this->adminNavet = $this->saved('navet', $this->admin);
        $this->alias($this->copier, 'tomate', $this->copierTomate->getSlug());
    }

    public function testEachAccountSeesItsOwnIngredientsAndNothingElse(): void
    {
        // Then : le rayon, le choix pour une recette, la recherche, le port de domaine et les candidats du rapprochement disent la même chose
        $this->assertVisibleSlugs([$this->tomate->getSlug(), $this->oignon->getSlug(), $this->carotte->getSlug()], $this->owner);
        $this->assertVisibleSlugs([$this->copierTomate->getSlug(), $this->copierPoireau->getSlug()], $this->copier);
        $this->assertVisibleSlugs([$this->plainRadis->getSlug()], $this->plain);
        $this->assertVisibleSlugs([$this->adminNavet->getSlug()], $this->admin);
        $this->assertVisibleSlugs([], $this->empty);
    }

    public function testAnAdministratorSeesOnlyHisOwnIngredientsLikeAnyone(): void
    {
        // Then : ni le propriétaire du catalogue ni l'autre administrateur ne voient ceux des autres comptes, ni l'inverse
        $this->assertNotContains($this->tomate->getSlug(), $this->visibleInTheDepartment($this->admin));
        $this->assertNotContains($this->adminNavet->getSlug(), $this->visibleInTheDepartment($this->owner));
        $this->assertNotContains($this->copierPoireau->getSlug(), $this->visibleInTheDepartment($this->admin));
        $this->assertNotContains($this->tomate->getSlug(), $this->visibleInTheDepartment($this->copier));
    }

    public function testSearchingGivesOneResultPerAccountAndNeverTheIngredientOfAnother(): void
    {
        // When
        $slugOf = static fn(array $results): array => array_map(static fn(IngredientSearchResultDTO $r): string => $r->slug, $results);

        // Then : « tomate » existe chez le propriétaire et chez le compte qui en a reçu une copie, chacun voit le sien, une seule fois
        $this->assertSame([$this->tomate->getSlug()], $slugOf($this->ingredientLoader->search('tomate', $this->owner->getId())));
        $this->assertSame([$this->copierTomate->getSlug()], $slugOf($this->ingredientLoader->search('tomate', $this->copier->getId())));
        $this->assertSame([], $slugOf($this->ingredientLoader->search('tomate', $this->plain->getId())));
        $this->assertSame([], $slugOf($this->ingredientLoader->search('tomate', $this->admin->getId())));
        $this->assertTrue($this->ingredientLoader->search('tomate', $this->copier->getId())[0]->canEdit, 'tout ce qui est visible est modifiable par son propriétaire');
    }

    public function testTheSheetOfAnIngredientOfAnotherAccountIsUnknownEvenForAnAdministrator(): void
    {
        // Then : 404 pour tout autre compte (D3), le sien se lit
        foreach ([[$this->tomate, $this->admin], [$this->tomate, $this->copier], [$this->copierPoireau, $this->owner], [$this->plainRadis, $this->admin]] as [$ingredient, $viewer]) {
            try {
                $this->ingredientLoader->get($ingredient->getSlug(), $viewer->getId());
                $this->fail('La fiche de l\'ingrédient d\'un autre est inconnue : ' . $ingredient->getSlug());
            } catch (IngredientException $exception) {
                $this->assertEquals(IngredientException::notFound($ingredient->getSlug()), $exception);
            }
        }
        $sheet = $this->ingredientLoader->get($this->tomate->getSlug(), $this->owner->getId());
        $this->assertSame([$this->tomate->getSlug(), true, $this->owner->getId()], [$sheet->slug, $sheet->canEdit, $sheet->creatorId]);
    }

    public function testAnOldSlugIsNoLongerReadableByTheAccountThatReceivedACopy(): void
    {
        // When / Then : l'ancien commun « tomate » est maintenant celui du propriétaire ; le compte qui en a reçu une copie ne le lit plus
        $this->expectExceptionObject(IngredientException::notFound($this->tomate->getSlug()));
        $this->ingredientLoader->get($this->tomate->getSlug(), $this->copier->getId());
    }

    public function testAnIngredientIsUsableByItsOwnerOnly(): void
    {
        // Then
        $unit = $this->tomate->getDefaultPurchaseUnit()->getSlug();
        $this->assertSame($unit, $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->tomate->getSlug(), $this->owner->getId()));
        $this->assertSame($unit, $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->copierTomate->getSlug(), $this->copier->getId()));

        foreach ([[$this->tomate, $this->admin], [$this->tomate, $this->copier], [$this->copierTomate, $this->owner], [$this->copierTomate, $this->plain]] as [$ingredient, $user]) {
            try {
                $this->ingredientRepository->findPurchaseUnitSlugUsableBy($ingredient->getSlug(), $user->getId());
                $this->fail('L\'ingrédient d\'un autre est inconnu : ' . $ingredient->getSlug());
            } catch (IngredientException $exception) {
                $this->assertEquals(IngredientException::notFound($ingredient->getSlug()), $exception);
            }
        }
    }

    public function testUsableByCostsOneQuery(): void
    {
        // Given
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $this->ingredientRepository->findPurchaseUnitSlugUsableBy($this->tomate->getSlug(), $this->owner->getId());

        // Then
        $this->assertSame(1, $counter->count());
    }

    public function testAnAliasHidesNothingAndGivesNothing(): void
    {
        // Given : un alias d'adoption vers la copie (déjà là), un alias dont la copie n'existe plus, et un alias vers l'ingrédient d'un autre
        $this->alias($this->plain, 'oignon', 'oignon-disparu-' . $this->plain->getId());
        $this->alias($this->empty, 'radis', $this->plainRadis->getSlug());

        // Then : la visibilité ne regarde que le créateur, les alias ne servent qu'à résoudre les anciens slugs
        $this->assertVisibleSlugs([$this->plainRadis->getSlug()], $this->plain);
        $this->assertVisibleSlugs([], $this->empty);
        $this->assertVisibleSlugs([$this->copierTomate->getSlug(), $this->copierPoireau->getSlug()], $this->copier);
    }

    public function testEveryReadAgreesWithTheSingleDefinitionForEveryAccount(): void
    {
        foreach ([$this->owner, $this->copier, $this->plain, $this->admin, $this->empty] as $user) {
            // When : la définition en SQL brut, « créateur = moi »
            $expected = $this->fetchSlugs('SELECT slug FROM ingredient WHERE creator = ?', [$user->getId()]);
            $expectedInTheDepartment = $this->fetchSlugs("SELECT slug FROM ingredient WHERE department = 'cereale' AND creator = ?", [$user->getId()]);

            // Then : chaque lecture la donne, ni plus ni moins
            $this->assertSame($expected, $this->slugsOfOptions($this->ingredientLoader->listAvailable($user->getId())), 'GET /ingredients');
            $this->assertSame($expectedInTheDepartment, $this->visibleInTheDepartment($user), 'rayon');
            $this->assertSame($expected, $this->slugsOfRepository($this->ingredientRepository->findByOwner($user->getId())), 'findByOwner');
            $this->assertSame($expected, $this->slugsOfCandidates($user), 'candidats du rapprochement');
            $department = new DepartmentBuilder()->isCereal()->build();
            $this->assertSame($expectedInTheDepartment, $this->slugsOfRepository($this->ingredientRepository->findByDepartmentAndOwner($department, $user->getId())), 'findByDepartmentAndOwner');
            foreach (['tomate', 'oignon', 'carotte', 'poireau', 'radis', 'navet'] as $needle) {
                $searched = array_map(static fn(IngredientSearchResultDTO $r): string => $r->slug, $this->ingredientLoader->search($needle, $user->getId()));
                $oldSearch = array_values(array_filter($expected, static fn(string $slug): bool => str_starts_with($slug, $needle)));
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
        foreach (['tomate', 'oignon', 'carotte', 'poireau', 'radis', 'navet'] as $needle) {
            foreach ($this->ingredientLoader->search($needle, $user->getId()) as $result) {
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
            $this->ingredientLoader->listByDepartment('cereale', $user->getId())->ingredients,
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

    /**
     * Un ingrédient du rayon des céréales. `$ownSlug` faux : le slug du nom seul, comme un ancien commun passé au propriétaire du catalogue.
     */
    private function saved(string $name, User $creator, ?string $adoptedFrom = null, bool $ownSlug = true): Ingredient
    {
        $builder = new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($creator, $ownSlug)->withAdoptedFrom($adoptedFrom);
        if (!$ownSlug) {
            $builder->withSlug($name);
        }
        $ingredient = $builder->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }

    private function alias(User $user, string $aliasSlug, string $ingredientSlug): void
    {
        $this->aliasRepository->save(IngredientAlias::create($user->getId(), $aliasSlug, $ingredientSlug, AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-10-21 03:00:00')));
    }
}
