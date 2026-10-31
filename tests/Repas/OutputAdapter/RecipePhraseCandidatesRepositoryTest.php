<?php

namespace Repas\Tests\Repas\OutputAdapter;

use Repas\Repas\Domain\Interface\RecipePhraseCandidates;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Domain\Model\User;

/**
 * Les recettes qu'une phrase prononcée peut désigner (ADR 0029 décision 13, VOX-305), sur la vraie base : celles de l'utilisateur seulement, chacune
 * sous son nom normalisé.
 */
class RecipePhraseCandidatesRepositoryTest extends DatabaseTestCase
{
    private RecipePhraseCandidates $candidates;
    private DataPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->candidates = static::getContainer()->get(RecipePhraseCandidates::class);
        $this->persister = new DataPersister(static::getContainer());
    }

    private function savedRecipe(User $author, string $name): string
    {
        $recipe = new RecipeBuilder()->isPastaCarbonara()->withName($name)->withAuthor($author)->build();
        foreach ($recipe->getRows() as $row) {
            $row->getIngredient()->setCreatorId($author->getId());
        }
        $this->persister->saveRecipe($recipe);

        return $recipe->getId();
    }

    public function testTheCandidatesAreTheRecipesOfTheUserWithTheirOwnNormalizedName(): void
    {
        // Given
        $user = new UserBuilder()->build();
        $other = new UserBuilder()->build();
        $this->persister->saveUser($user);
        $this->persister->saveUser($other);
        $id = $this->savedRecipe($user, 'Œufs à la Coque');
        $this->savedRecipe($other, 'Hachis parmentier');

        // When
        $candidates = $this->candidates->forUser($user->getId());

        // Then : l'identifiant de la recette tient lieu de slug, aucun rayon, aucune habitude
        $this->assertCount(1, $candidates);
        $this->assertSame([$id, 'oeufs-a-la-coque', 'Œufs à la Coque', '', 0], [$candidates[0]->slug, $candidates[0]->sluggedName, $candidates[0]->name, $candidates[0]->departmentName, $candidates[0]->uses12m]);
    }
}
