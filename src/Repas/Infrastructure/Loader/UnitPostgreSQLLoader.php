<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\UnitLoader;
use Repas\Repas\Application\Query\ListUnits\UnitDTO;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Infrastructure\Entity\Unit;

final readonly class UnitPostgreSQLLoader implements UnitLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('u.slug AS slug', 'u.name AS name', 'u.symbol AS symbol', 'u.dimension AS dimension', 'u.baseFactor AS baseFactor')
            ->from(Unit::class, 'u')
            ->orderBy('u.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): UnitDTO => new UnitDTO($row['slug'], $row['name'], $row['symbol'], $row['dimension']?->value, $row['baseFactor']),
            $rows,
        );
    }

    public function get(string $slug): UnitDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('u.slug AS slug', 'u.name AS name', 'u.symbol AS symbol', 'u.dimension AS dimension', 'u.baseFactor AS baseFactor')
            ->from(Unit::class, 'u')
            ->where('u.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            throw UnitException::notFound($slug);
        }

        return new UnitDTO($row['slug'], $row['name'], $row['symbol'], $row['dimension']?->value, $row['baseFactor']);
    }
}
