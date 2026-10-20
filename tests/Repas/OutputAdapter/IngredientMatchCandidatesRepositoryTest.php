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
        $this->assertSame($user->getId(), $candidate->creatorId);
        $this->assertSame('beurre', $candidate->adoptedFrom);
        $this->assertSame(['beurre'], $candidate->tokens);
        $this->assertFalse($candidate->isCommon());
    }

    public function testACommonIsACandidateUntilTheUserAdoptsItAndNeverTheIngredientOfAnother(): void
    {
        // Given
        $adopter = $this->savedUser();
        $plain = $this->savedUser();
        $other = $this->savedUser();
        $common = $this->saved(new IngredientBuilder()->isFlour());
        $adopted = $this->saved(new IngredientBuilder()->isFlour()->withCreator($adopter, true)->withAdoptedFrom('farine'));
        $this->aliases->save(IngredientAlias::create($adopter->getId(), 'farine', $adopted->getSlug(), AliasKind::ADOPTION, 'first-use', new DateTimeImmutable('2026-10-21 03:00:00')));
        $others = $this->saved(new IngredientBuilder()->isSugar()->withCreator($other, true));
        $mine = $this->saved(new IngredientBuilder()->isButter()->withCreator($plain, true));

        // When / Then : celui qui n'a rien adopté voit le commun et le sien
        $this->assertEqualsCanonicalizing([$common->getSlug(), $mine->getSlug()], $this->slugs($plain));
        $this->assertTrue($this->bySlug($this->candidates->visibleTo($plain->getId()), $common->getSlug())->isCommon());
        // L'adoptant voit sa copie à la place du commun, pas celle d'un autre
        $this->assertSame([$adopted->getSlug()], $this->slugs($adopter));
        $this->assertNotContains($others->getSlug(), $this->slugs($adopter));
        $this->assertNotContains($others->getSlug(), $this->slugs($plain));
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

    private function savedUser(): User
    {
        $user = new UserBuilder()->build();
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
