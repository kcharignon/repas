<?php

namespace Repas\Tests\Repas\OutputAdapter;


use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Shared\Application\Interface\CommandBusInterface;
use Repas\Tests\Helper\Builder\RecipeBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\Tests\Helper\DatabaseTestCase;
use Repas\Tests\Helper\DataPersister;
use Repas\User\Application\DeleteUser\DeleteUserCommand;
use Repas\User\Domain\Model\User;

/**
 * Supprimer un compte efface les recettes qu'une de ses recettes utilise (ADR 0040 décision 37), par le VRAI cas d'usage `DeleteUser` : les arêtes de
 * composants n'ont aucune colonne qui désigne un utilisateur (`UserOwnedDataCoverageTest` ne les voit pas), elles partent donc avec les recettes de
 * l'auteur, AVANT elles. Les arêtes de l'autre compte sont intactes, et ses copies des recettes du compte supprimé restent à lui, avec leurs propres
 * arêtes.
 */
class RecipeComponentsArePurgedWithTheUserTest extends DatabaseTestCase
{
    private DataPersister $persister;
    private User $departing;
    private User $staying;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->persister = new DataPersister(static::getContainer());
        $this->departing = new UserBuilder()->build();
        $this->staying = new UserBuilder()->build();
        $this->admin = new UserBuilder()->isAdmin()->build();
        foreach ([$this->departing, $this->staying, $this->admin] as $user) {
            $this->persister->saveUser($user);
        }
    }

    public function testDeletingAnAccountRemovesTheEdgesOfItsRecipesAndLeavesTheOthersIntact(): void
    {
        // Given : chez chacun des deux comptes, une tarte qui utilise une pâte et une crème ; l'autre compte a copié la tarte du partant
        $departingDough = $this->saved($this->departing, new RecipeBuilder()->isBasqueCake());
        $departingCream = $this->saved($this->departing, new RecipeBuilder()->isSoftBoiledEggs());
        $departingPie = $this->saved($this->departing, new RecipeBuilder()->isPastaCarbonara()->withComponent($departingDough, 2.0)->withComponent($departingCream, 0.5));
        $stayingDough = $this->saved($this->staying, new RecipeBuilder()->isBasqueCake());
        $stayingPie = $this->saved($this->staying, new RecipeBuilder()->isSoftBoiledEggs()->withComponent($stayingDough, 3.0));
        $copyOfTheDeparting = $this->saved($this->staying, new RecipeBuilder()->isPastaCarbonara()->withOriginalId($departingPie->getId())->withComponent($stayingDough, 1.0));
        $this->assertSame(2, $this->countWhere('recipe_component', 'recipe', $departingPie->getId()));

        // When : l'administrateur supprime le compte du partant, par le cas d'usage
        static::getContainer()->get(CommandBusInterface::class)->dispatch(new DeleteUserCommand($this->departing->getId(), $this->admin->getId()));
        $this->entityManager->clear();

        // Then : plus une arête de ses recettes (ni comme recette composée, ni comme recette utilisée), plus une seule de ses recettes
        $this->assertSame(0, $this->countWhere('recipe_component', 'recipe', $departingPie->getId()));
        $this->assertSame(0, $this->countWhere('recipe_component', 'component', $departingDough->getId()));
        $this->assertSame(0, $this->countWhere('recipe_component', 'component', $departingCream->getId()));
        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM recipe_component rc WHERE NOT EXISTS (SELECT 1 FROM recipe r WHERE r.id = rc.recipe)"), 'Aucune arête orpheline.');
        $this->assertSame(0, $this->scalar('SELECT COUNT(*) FROM recipe WHERE author = ?', $this->departing->getId()));
        // et celles de l'autre compte sont telles quelles, y compris la copie de la tarte du partant, devenue originale
        $recipes = static::getContainer()->get(RecipeRepository::class);
        $this->assertSame([$stayingDough->getId()], $recipes->findOneById($stayingPie->getId())->componentIds());
        $copy = $recipes->findOneById($copyOfTheDeparting->getId());
        $this->assertSame([$stayingDough->getId(), null], [$copy->componentIds()[0], $copy->getOriginalId()]);
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM recipe_component WHERE recipe IN (?, ?)', $stayingPie->getId(), $copyOfTheDeparting->getId()));
    }

    private function saved(User $author, RecipeBuilder $builder): Recipe
    {
        $recipe = $builder->withAuthor($author)->build();
        $this->persister->saveRecipe($recipe);

        return $recipe;
    }

    private function countWhere(string $table, string $column, string $value): int
    {
        return $this->scalar("SELECT COUNT(*) FROM $table WHERE $column = ?", $value);
    }

    private function scalar(string $sql, string ...$params): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
    }
}
