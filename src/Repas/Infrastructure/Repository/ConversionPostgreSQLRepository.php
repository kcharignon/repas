<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\ConversionRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Conversion as ConversionModel;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Infrastructure\Entity\Conversion as ConversionEntity;
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


    public function findByIngredient(Ingredient $ingredient): Tab
    {
        $conversionEntities = $this->entityRepository->createQueryBuilder('c')
            ->where('c.ingredientSlug = :ingredientSlug')
            ->setParameter('ingredientSlug', $ingredient->getSlug())
            ->orWhere('c.ingredientSlug is NULL')
            ->getQuery()
            ->getResult();

        $conversionEntities = Tab::fromArray($conversionEntities);
        return $conversionEntities->map(fn(ConversionEntity $conversion) => $this->convertEntityToModel($conversion));
    }

    /**
     * @throws UnitException
     * @throws IngredientException
     */
    private function convertEntityToModel(ConversionEntity $conversion): ConversionModel
    {
        return ConversionModel::load([
            'id' => $conversion->getId(),
            'ingredient' => $this->ingredientRepository->findOneBySlug($conversion->getIngredientSlug()),
            'start_unit' => $this->unitRepository->findOneBySlug($conversion->getStartUnitSlug()),
            'end_unit' => $this->unitRepository->findOneBySlug($conversion->getEndUnitSlug()),
            'coefficient' => $conversion->getCoefficient(),
        ]);
    }
}
