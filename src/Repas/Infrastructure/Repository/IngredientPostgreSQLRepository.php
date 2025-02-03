<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\DepartmentRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient as IngredientModel;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Repas\Infrastructure\Entity\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\ModelCache;

readonly class IngredientPostgreSQLRepository extends PostgreSQLRepository implements IngredientRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private ModelCache $modelCache,
        private UnitRepository $unitRepository,
        private DepartmentRepository $departmentRepository,
    ) {
        parent::__construct($managerRegistry, IngredientEntity::class);
    }

    /**
     * @throws IngredientException
     */
    public function findOneBySlug(string $slug): IngredientModel
    {
        // On cherche das le cache
        if (($model = $this->modelCache->getModelCache(IngredientModel::class, $slug)) !== null) {
            return $model;
        }

        // On cherche en base de donnée
        if (($entity = $this->entityRepository->find($slug)) !== null) {
            $model = $this->convertEntityToModel($entity);
            // On stock en cache
            $this->modelCache->setModelCache($model);
            return $model;
        }

        throw IngredientException::notFound();
    }

    public function findByDepartment(Department $department): Tab
    {
        $ingredients = new Tab($this->entityRepository->findBy(['departmentSlug' => $department->getId()]), IngredientEntity::class);
        return $ingredients->map(function (IngredientEntity $ingredient) {
            if (($model = $this->modelCache->getModelCache(IngredientModel::class, $ingredient->getSlug())) !== null) {
                return $model;
            }

            $model = $this->convertEntityToModel($ingredient);
            $this->modelCache->setModelCache($model);
            return $model;
        });
    }

    public function save(IngredientModel $ingredient): void
    {
        $this->modelCache->removeModelCache($ingredient);
        $ingredientEntity = $this->entityRepository->find($ingredient->getSlug());
        if ($ingredientEntity) {
            $this->updateEntity($ingredientEntity, $ingredient);
        } else {
            $ingredientEntity = IngredientEntity::fromModel($ingredient);
        }

        $this->entityManager->persist($ingredientEntity);
        $this->entityManager->flush();
        $this->modelCache->setModelCache($ingredient);
    }

    private function updateEntity(IngredientEntity $ingredientEntity, IngredientModel $ingredient): void
    {
        $ingredientEntity
            ->setName($ingredient->getName())
            ->setImage($ingredient->getImage())
            ->setDepartmentSlug($ingredient->getDepartment()->getSlug())
            ->setDefaultCookingUnitSlug($ingredient->getDefaultCookingUnit()->getSlug())
            ->setDefaultPurchaseUnitSlug($ingredient->getDefaultPurchaseUnit()->getSlug())
        ;
    }

    /**
     * @throws IngredientException
     */
    private function convertEntityToModel(IngredientEntity $ingredientEntity): IngredientModel
    {
        return IngredientModel::load([
            "slug" => $ingredientEntity->getSlug(),
            "name" => $ingredientEntity->getName(),
            "image" => $ingredientEntity->getImage(),
            "department" => $this->departmentRepository->findOneBySlug($ingredientEntity->getDepartmentSlug()),
            "default_cooking_unit" => $this->unitRepository->findOneBySlug($ingredientEntity->getDefaultCookingUnitSlug()),
            "default_purchase_unit" => $this->unitRepository->findOneBySlug($ingredientEntity->getDefaultPurchaseUnitSlug()),
        ]);
    }

    public function cachedByRecipe(string $recipeId): void
    {
        // Si la recette est deja en cache
        if ($this->modelCache->isCachedExists(Recipe::class, $recipeId)) {
            return ;
        }

        // Récupérer les ingrédients liés à la recette
        $ingredientEntities = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(IngredientEntity::class, 'i')
            ->innerJoin(RecipeRow::class, 'rr', 'WITH', 'rr.ingredientSlug = i.slug')
            ->where('rr.recipeId = :recipeId')
            ->setParameter('recipeId', $recipeId)
            ->getQuery()
            ->getResult();

        if (empty($ingredientEntities)) {
            return; // Aucun ingrédient à mettre en cache
        }

        // Extraire tous les slugs de départements et unités à récupérer
        $departmentSlugs = Tab::newEmptyTyped('string');
        $unitSlugs = Tab::newEmptyTyped('string');;

        foreach ($ingredientEntities as $ingredient) {
            $departmentSlugs[] = $ingredient->getDepartmentSlug();
            $unitSlugs[] = $ingredient->getDefaultCookingUnitSlug();
            $unitSlugs[] = $ingredient->getDefaultPurchaseUnitSlug();
        }

        // Charger tous les départements nécessaires en une seule requête
        $departments = $this->departmentRepository->findBySlugs($departmentSlugs->unique());

        // Charger toutes les unités nécessaires en une seule requête
        $units = $this->unitRepository->findBySlugs($unitSlugs->unique());

        // Mapper les résultats pour un accès rapide
        $departmentMap = [];
        foreach ($departments as $department) {
            $departmentMap[$department->getSlug()] = $department;
        }

        $unitMap = [];
        foreach ($units as $unit) {
            $unitMap[$unit->getSlug()] = $unit;
        }

        // Convertir chaque ingrédient en modèle et le stocker en cache
        foreach ($ingredientEntities as $ingredientEntity) {
            $slug = $ingredientEntity->getSlug();

            if ($this->modelCache->getModelCache(IngredientModel::class, $slug) !== null) {
                continue; // L'ingrédient est déjà en cache, on l'ignore
            }

            // Récupérer les entités associées depuis les maps
            $department = $departmentMap[$ingredientEntity->getDepartmentSlug()] ?? null;
            $defaultCookingUnit = $unitMap[$ingredientEntity->getDefaultCookingUnitSlug()] ?? null;
            $defaultPurchaseUnit = $unitMap[$ingredientEntity->getDefaultPurchaseUnitSlug()] ?? null;

            if (!$department || !$defaultCookingUnit || !$defaultPurchaseUnit) {
                continue; // Éviter les erreurs si une entité liée est manquante
            }

            // Convertir en modèle
            $model = IngredientModel::load([
                "slug" => $slug,
                "name" => $ingredientEntity->getName(),
                "image" => $ingredientEntity->getImage(),
                "department" => $department,
                "default_cooking_unit" => $defaultCookingUnit,
                "default_purchase_unit" => $defaultPurchaseUnit,
            ]);

            // Stocker en cache
            $this->modelCache->setModelCache($model);
        }
    }
}
