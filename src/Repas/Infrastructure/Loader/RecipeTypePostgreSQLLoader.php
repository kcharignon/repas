<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Infrastructure\Entity\RecipeType;

final readonly class RecipeTypePostgreSQLLoader implements RecipeTypeLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.slug AS slug', 't.name AS name', 't.image AS image', 't.sequence AS sequence')
            ->from(RecipeType::class, 't')
            ->orderBy('t.sequence', 'ASC')
            ->addOrderBy('t.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): RecipeTypeDTO => new RecipeTypeDTO($row['slug'], $row['name'], $row['image'], $row['sequence']),
            $rows,
        );
    }
}
