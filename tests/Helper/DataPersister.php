<?php

namespace Repas\Tests\Helper;


use Psr\Container\ContainerInterface;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\Unit;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

/**
 * Enregistre en base de test un modèle construit par un builder ET les agrégats qu'il référence : un repository
 * n'enregistre que son agrégat, alors que le recharger (`findOneById`) relit ses dépendances en base. Les `save()`
 * étant des upserts, enregistrer deux fois la même unité ou le même rayon est sans effet.
 *
 * Remplace les fixtures globales dans les tests de base (roadmap, « Dette de tests »).
 */
final readonly class DataPersister
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    public function saveUser(User $user): void
    {
        $this->container->get(UserRepository::class)->save($user);
    }

    public function saveUnit(Unit $unit): void
    {
        $this->container->get(UnitRepository::class)->save($unit);
    }

    public function saveIngredient(Ingredient $ingredient): void
    {
        if (($creator = $ingredient->getCreator()) !== null) {
            $this->saveUser($creator);
        }
        $this->container->get(DepartmentRepository::class)->save($ingredient->getDepartment());
        foreach ([$ingredient->getDefaultCookingUnit(), $ingredient->getDefaultPurchaseUnit(), ...$ingredient->getCompatibleUnits()] as $unit) {
            $this->saveUnit($unit);
        }
        $this->container->get(IngredientRepository::class)->save($ingredient);
    }

    public function saveRecipe(Recipe $recipe): void
    {
        $this->saveUser($recipe->getAuthor());
        $this->container->get(RecipeTypeRepository::class)->save($recipe->getType());
        /** @var RecipeRow $row */
        foreach ($recipe->getRows() as $row) {
            $this->saveIngredient($row->getIngredient());
            $this->saveUnit($row->getUnit());
        }
        $this->container->get(RecipeRepository::class)->save($recipe);
    }
}
