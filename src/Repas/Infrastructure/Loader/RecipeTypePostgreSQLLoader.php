<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\RecipeTypeLoader;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeCountDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\RecipeType;

final readonly class RecipeTypePostgreSQLLoader implements RecipeTypeLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(string $userId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                't.slug AS slug', 't.name AS name', 't.image AS image', 't.sequence AS sequence',
                '(SELECT COUNT(r.id) FROM '.Recipe::class.' r WHERE r.typeSlug = t.slug AND r.authorId = :userId) AS recipeCount',
            )
            ->from(RecipeType::class, 't')
            ->setParameter('userId', $userId)
            ->orderBy('t.sequence', 'ASC')
            ->addOrderBy('t.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): RecipeTypeCountDTO => new RecipeTypeCountDTO($row['slug'], $row['name'], $row['image'], (int) $row['sequence'], (int) $row['recipeCount']),
            $rows,
        );
    }

    public function get(string $slug): RecipeTypeDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('t.slug AS slug', 't.name AS name', 't.image AS image', 't.sequence AS sequence')
            ->from(RecipeType::class, 't')
            ->where('t.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw RecipeException::typeNotFound($slug);
        }

        return new RecipeTypeDTO($row['slug'], $row['name'], $row['image'], (int) $row['sequence']);
    }
}
