<?php

namespace Repas\Tests\Helper;


use Psr\Container\ContainerInterface;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\IngredientAlias;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Domain\Model\Unit;
use Repas\Tests\Helper\Builder\IngredientBuilder;
use Repas\Tests\Helper\Builder\UserBuilder;
use Repas\User\Domain\Interface\UserRepository;
use Repas\User\Domain\Model\User;

/**
 * Enregistre en base de test un modèle construit par un builder ET les agrégats qu'il référence : un repository
 * n'enregistre que son agrégat, alors que le recharger (`findOneById`) relit ses dépendances en base. Les `save()`
 * étant des upserts, enregistrer deux fois la même unité ou le même rayon est sans effet.
 *
 * Les méthodes `save{X}Dependencies()` n'enregistrent PAS l'agrégat lui-même : elles servent aux tests du repository
 * de cet agrégat, qui doivent appeler son `save()` eux-mêmes.
 *
 * Remplace les anciennes fixtures globales dans les tests de base (ADR 0010, règle 11).
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

    public function saveDepartment(Department $department): void
    {
        $this->container->get(DepartmentRepository::class)->save($department);
    }

    public function saveRecipeType(RecipeType $recipeType): void
    {
        $this->container->get(RecipeTypeRepository::class)->save($recipeType);
    }

    public function saveIngredientDependencies(Ingredient $ingredient): void
    {
        $this->saveDepartment($ingredient->getDepartment());
        foreach ([$ingredient->getDefaultCookingUnit(), $ingredient->getDefaultPurchaseUnit(), ...$ingredient->getCompatibleUnits()] as $unit) {
            $this->saveUnit($unit);
        }
    }

    public function saveIngredient(Ingredient $ingredient): void
    {
        $this->saveIngredientDependencies($ingredient);
        $this->container->get(IngredientRepository::class)->save($ingredient);
    }

    /**
     * Un ingrédient de cet utilisateur sous ce slug exact, nommé comme son slug : ce que les écritures du drive exigent d'un slug (un choix ne
     * se pose que sur un ingrédient du compte). Deux utilisateurs ne partagent pas un slug : la clé est le slug.
     */
    public function saveOwnedIngredient(User|string $owner, string $slug): void
    {
        $creator = $owner instanceof User ? $owner : new UserBuilder()->withId($owner)->build();

        $this->saveIngredient(new IngredientBuilder()->isButter()->withName($slug)->withSlug($slug)->withCreator($creator)->build());
    }

    /**
     * Un alias n'a pas de dépendance à enregistrer : aucune clé étrangère, ni l'utilisateur ni l'ingrédient désigné n'ont besoin d'exister.
     */
    public function saveIngredientAlias(IngredientAlias $alias): void
    {
        $this->container->get(IngredientAliasRepository::class)->save($alias);
    }

    public function saveConversionDependencies(Conversion $conversion): void
    {
        $this->saveUnit($conversion->getStartUnit());
        $this->saveUnit($conversion->getEndUnit());
        if (($ingredient = $conversion->getIngredient()) !== null) {
            $this->saveIngredient($ingredient);
        }
    }

    public function saveConversion(Conversion $conversion): void
    {
        $this->saveConversionDependencies($conversion);
        $this->container->get(ConversionRepository::class)->save($conversion);
    }

    public function saveRecipeDependencies(Recipe $recipe): void
    {
        $this->saveRecipeType($recipe->getType());
        /** @var RecipeRow $row */
        foreach ($recipe->getRows() as $row) {
            $this->saveIngredient($row->getIngredient());
            $this->saveUnit($row->getUnit());
        }
    }

    public function saveRecipe(Recipe $recipe): void
    {
        $this->saveRecipeDependencies($recipe);
        $this->container->get(RecipeRepository::class)->save($recipe);
    }
}
