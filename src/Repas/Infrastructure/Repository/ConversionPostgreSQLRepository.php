<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion;
use Repas\Repas\Domain\Model\Conversion as ConversionModel;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Unit;
use Repas\Repas\Infrastructure\Entity\Conversion as ConversionEntity;
use Repas\Repas\Infrastructure\Entity\Ingredient as IngredientEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class ConversionPostgreSQLRepository extends PostgreSQLRepository implements ConversionRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private IngredientRepository $ingredientRepository,
        private UnitRepository $unitRepository,
    ) {
        parent::__construct($registry, ConversionEntity::class);
    }

    /**
     * @throws UnitException
     * @throws IngredientException
     * @throws ConversionException
     */
    public function findById(string $id): ?Conversion
    {
        $entity = $this->entityRepository->find($id) ?? throw ConversionException::notFound($id);
        return $this->convertEntityToModel($entity);
    }


    public function findByIngredientOrCommon(Ingredient $ingredient): Tab
    {
        // Les conversions propres de l'ingrédient d'abord, les universelles ensuite (l'identifiant départage : l'ordre est stable). Il est celui
        // des arêtes du graphe : à longueur égale, le parcours en largeur préfère la conversion de l'ingrédient à l'universelle qui dirait autre chose
        $conversionEntities = $this->entityRepository->createQueryBuilder('c')
            ->addSelect('CASE WHEN c.ingredientSlug IS NULL THEN 1 ELSE 0 END AS HIDDEN universal')
            ->where('c.ingredientSlug = :ingredientSlug')
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->orWhere('c.ingredientSlug is NULL')
            ->orderBy('universal', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $this->preloadUnits($conversionEntities);
        $conversionEntities = Tab::fromArray($conversionEntities);
        return $conversionEntities->map(fn(ConversionEntity $conversion) => $this->convertEntityToModel($conversion));
    }

    public function findByIngredientsOrCommon(array $ingredients): array
    {
        $slugs = array_values(array_unique(array_map(static fn(Ingredient $ingredient): string => $ingredient->getSlug(), $ingredients)));
        if ($slugs === []) {
            return [];
        }

        // Une seule lecture, dans l'ordre de `findByIngredientOrCommon` : les propres d'abord, les universelles ensuite, l'identifiant départage
        $conversionEntities = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(ConversionEntity::class, 'c')
            ->addSelect('CASE WHEN c.ingredientSlug IS NULL THEN 1 ELSE 0 END AS HIDDEN universal')
            ->where('c.ingredientSlug IN (:ingredientSlugs)')
            ->setParameter('ingredientSlugs', $slugs)
            ->orWhere('c.ingredientSlug is NULL')
            ->orderBy('universal', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
        $this->preloadUnits($conversionEntities);

        $own = [];
        $universal = [];
        foreach ($conversionEntities as $entity) {
            $model = $this->convertEntityToModel($entity);
            if ($entity->getIngredientSlug() === null) {
                $universal[] = $model;
            } else {
                $own[$entity->getIngredientSlug()][] = $model;
            }
        }

        $byIngredient = [];
        foreach ($slugs as $slug) {
            $byIngredient[$slug] = new Tab([...($own[$slug] ?? []), ...$universal], Conversion::class);
        }

        return $byIngredient;
    }

    public function findByIngredient(Ingredient $ingredient): Tab
    {
        $conversionEntities = $this->entityRepository->createQueryBuilder('c')
            ->where('c.ingredientSlug = :ingredientSlug')
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->getQuery()
            ->getResult();

        $this->preloadUnits($conversionEntities);
        $conversionEntities = Tab::fromArray($conversionEntities);
        return $conversionEntities->map(fn(ConversionEntity $conversion) => $this->convertEntityToModel($conversion));
    }

    public function findByIngredientAndStartUnitAndEndUnit(Ingredient $ingredient, Unit $startUnit, Unit $endUnit): ?Conversion
    {
        $conversionEntity = $this->entityRepository->createQueryBuilder('c')
            ->where('c.ingredientSlug = :ingredientSlug')
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->andWhere('c.startUnitSlug = :startUnit')
            ->setParameter('startUnit', $startUnit->getSlug())
            ->andWhere('c.endUnitSlug = :endUnit')
            ->setParameter('endUnit', $endUnit->getSlug())
            ->orderBy('c.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($conversionEntity instanceof ConversionEntity) {
            return $this->convertEntityToModel($conversionEntity);
        }
        return null;
    }

    public function findOwnBetween(Ingredient $ingredient, Unit $a, Unit $b): Tab
    {
        // Une seule requête DQL (pas de QueryBuilder du dépôt : la baseline PHPStan ne doit que diminuer), les deux sens d'un coup
        $conversionEntities = $this->entityManager->createQuery(
            'SELECT c FROM ' . ConversionEntity::class . ' c'
            . ' WHERE c.ingredientSlug = :ingredientSlug'
            . ' AND ((c.startUnitSlug = :a AND c.endUnitSlug = :b) OR (c.startUnitSlug = :b AND c.endUnitSlug = :a))'
            . ' ORDER BY c.id ASC',
        )
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->setParameter('a', $a->getSlug())
            ->setParameter('b', $b->getSlug())
            ->getResult();

        $this->preloadUnits($conversionEntities);

        return Tab::fromArray($conversionEntities)->map(fn(ConversionEntity $conversion) => $this->convertEntityToModel($conversion));
    }

    public function ingredientHasAtLeast(Ingredient $ingredient, int $count): bool
    {
        // La `$count`-ième conversion propre existe-t-elle ? Une ligne lue après avoir sauté les `$count - 1` premières
        return $this->entityManager->createQueryBuilder()
            ->select('c.id')
            ->from(ConversionEntity::class, 'c')
            ->where('c.ingredientSlug = :ingredientSlug')
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->setFirstResult(max(0, $count - 1))
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    public function save(Conversion $conversion): void
    {
        $entity = $this->entityRepository->find($conversion->getId());
        if ($entity instanceof ConversionEntity) {
            $entity->updateFromModel($conversion);
        } else {
            $entity = ConversionEntity::fromModel($conversion);
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function delete(Conversion $conversion): void
    {
        $entity = $this->entityRepository->find($conversion->getId());
        if ($entity instanceof ConversionEntity) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function findAll(): Tab
    {
        $entities = new Tab($this->entityRepository->findBy([], ["ingredientSlug" => "ASC", "startUnitSlug" => "ASC"]), ConversionEntity::class);
        $this->preloadUnits($entities->toArray());
        return $entities->map(fn(ConversionEntity $entity) => $this->convertEntityToModel($entity));
    }


    /**
     * Lit en une requête les unités des conversions qui vont être converties en modèles : les lectures unitaires qui
     * suivent sont servies par l'identity map de Doctrine, sans requête (une par unité sinon).
     *
     * @param array<ConversionEntity> $conversionEntities
     */
    private function preloadUnits(array $conversionEntities): void
    {
        $slugs = Tab::newEmptyTyped('string');
        foreach ($conversionEntities as $conversion) {
            $slugs[] = $conversion->getStartUnitSlug();
            $slugs[] = $conversion->getEndUnitSlug();
        }

        $this->unitRepository->findBySlugs($slugs);
    }

    /**
     * @throws UnitException
     * @throws IngredientException
     */
    private function convertEntityToModel(ConversionEntity $conversion): ConversionModel
    {
        $ingredient = $conversion->getIngredientSlug() ? $this->ingredientRepository->findOneBySlug($conversion->getIngredientSlug()) : null;
        return ConversionModel::load([
            'id' => $conversion->getId(),
            'ingredient' => $ingredient,
            'start_unit' => $this->unitRepository->findOneBySlug($conversion->getStartUnitSlug()),
            'end_unit' => $this->unitRepository->findOneBySlug($conversion->getEndUnitSlug()),
            'coefficient' => $conversion->getCoefficient(),
        ]);
    }

    public function deleteByIngredient(Ingredient $ingredient): void
    {
        $this->entityRepository->createQueryBuilder('c')
            ->delete()
            ->where('c.ingredientSlug = :ingredientSlug')
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->getQuery()
            ->execute();
    }

    public function deleteByIngredientCreator(string $creatorId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ConversionEntity::class, 'c')
            ->where('c.ingredientSlug IN (SELECT i.slug FROM ' . IngredientEntity::class . ' i WHERE i.creatorId = :creator)')
            ->setParameter('creator', $creatorId)
            ->getQuery()
            ->execute();
    }
}
