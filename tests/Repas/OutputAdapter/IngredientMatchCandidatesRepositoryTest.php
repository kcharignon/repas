<?php

namespace Repas\Tests\Repas\OutputAdapter;


use DateTimeImmutable;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientMatchCandidates;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\IngredientCandidate;
use Repas\Tests\Helper\Builder\DepartmentBuilder;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UnitBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\Tests\Helper\SqlBudget\QueryCounter;
use Repas\User\Domain\Model\User;

/**
 * Les candidats d'un rapprochement à la copie d'une recette (ADR 0028 décision 8), lus sur la vraie base : les ingrédients que
 * l'utilisateur voit, jamais ceux d'un autre, avec de quoi comparer (nom, rayon, unités, origine), en une seule requête.
 * Que la règle de visibilité soit celle de toutes les lectures est aussi prouvé par `IngredientVisibilityTest`.
 */
class IngredientMatchCandidatesRepositoryTest extends DatabaseTestCase
{
    private IngredientMatchCandidates $candidates;
    private IngredientAliasRepository $aliases;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->candidates = static::getContainer()->get(IngredientMatchCandidates::class);
        $this->aliases = static::getContainer()->get(IngredientAliasRepository::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    public function testACandidateCarriesWhatTheComparisonNeeds(): void
    {
        // Given : un ingrédient personnel à l'unité d'achat différente de l'unité de cuisine
        $user = $this->savedUser();
        $butter = $this->saved(new IngredientBuilder()->isButter()->withCreator($user, true)->withAdoptedFrom('beurre'));

        // When
        $candidates = $this->candidates->visibleTo($user->getId());

        // Then
        $candidate = $this->bySlug($candidates, $butter->getSlug());
        $this->assertSame('beurre', $candidate->name);
        $this->assertSame($butter->getDepartment()->getSlug(), $candidate->departmentSlug);
        $this->assertSame('plaquette', $candidate->purchaseUnitSlug);
        $this->assertEqualsCanonicalizing(['plaquette', 'gramme', 'kilo'], $candidate->compatibleUnitSlugs);
        $this->assertSame('beurre', $candidate->adoptedFrom);
        $this->assertSame(['beurre'], $candidate->tokens);
    }

    public function testTheCandidatesAreTheIngredientsOfTheUserAndNeverThoseOfAnotherOrOfTheCatalogOwner(): void
    {
        // Given : le propriétaire du catalogue (ses 2 ingrédients), un compte qui a reçu une copie de la farine, un autre compte
        $owner = $this->savedUser(admin: true);
        $copier = $this->savedUser();
        $other = $this->savedUser();
        $catalogFlour = $this->saved(new IngredientBuilder()->isFlour()->withCreator($owner));
        $catalogButter = $this->saved(new IngredientBuilder()->isButter()->withCreator($owner));
        $copy = $this->saved(new IngredientBuilder()->isFlour()->withCreator($copier, true)->withAdoptedFrom('farine'));
        $this->aliases->save(IngredientAlias::create($copier->getId(), 'farine', $copy->getSlug(), AliasKind::ADOPTION, 'activation', new DateTimeImmutable('2026-10-21 03:00:00')));
        $others = $this->saved(new IngredientBuilder()->isSugar()->withCreator($other, true));

        // When / Then : chacun ne voit que les siens, administrateur compris ; jamais deux fois le même, jamais celui d'un autre
        $this->assertSame([$copy->getSlug()], $this->slugs($copier));
        $this->assertEqualsCanonicalizing([$catalogFlour->getSlug(), $catalogButter->getSlug()], $this->slugs($owner));
        $this->assertSame([$others->getSlug()], $this->slugs($other));
        $this->assertSame([], $this->slugs($this->savedUser()));
    }

    public function testTheCandidatesAreReadInOneQueryWhateverTheNumberOfIngredientsAndSortedBySlug(): void
    {
        // Given
        $user = $this->savedUser();
        foreach (['zeste', 'ail', 'miel', 'thym'] as $name) {
            $this->saved(new IngredientBuilder()->withName($name)->withDepartment(new DepartmentBuilder()->isCereal())->withCreator($user, true));
        }
        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        // When
        $slugs = $this->slugs($user);

        // Then
        $this->assertSame(1, $counter->count());
        $sorted = $slugs;
        sort($sorted);
        $this->assertSame($sorted, $slugs);
        $this->assertCount(4, $slugs);
    }

    /**
     * @return list<string> dans l'ordre rendu par le port
     */
    private function slugs(User $user): array
    {
        return array_map(static fn(IngredientCandidate $candidate): string => $candidate->slug, $this->candidates->visibleTo($user->getId()));
    }

    /**
     * @param list<IngredientCandidate> $candidates
     */
    private function bySlug(array $candidates, string $slug): IngredientCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->slug === $slug) {
                return $candidate;
            }
        }
        $this->fail("Le candidat $slug est absent.");
    }

    private function savedUser(bool $admin = false): User
    {
        $user = $admin ? new UserBuilder()->isAdmin()->build() : new UserBuilder()->build();
        $this->persister->saveUser($user);

        return $user;
    }

    private function saved(IngredientBuilder $builder): Ingredient
    {
        $ingredient = $builder->build();
        $this->persister->saveIngredient($ingredient);

        return $ingredient;
    }
}
