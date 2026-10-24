<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Infrastructure\Entity\Conversion;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Unit;

final readonly class ConversionPostgreSQLLoader implements ConversionLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(): array
    {
        return $this->toDTOs($this->query()
            ->orderBy('c.ingredientSlug', 'ASC')
            ->addOrderBy('c.startUnitSlug', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult());
    }

    public function get(string $id): ConversionDTO
    {
        $rows = $this->query()
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            throw ConversionException::notFound($id);
        }

        return $this->toDTOs($rows)[0];
    }

    private function query(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'c.id AS id', 'c.coefficient AS coefficient',
                'i.slug AS ingredientSlug', 'i.name AS ingredientName', 'i.creatorId AS creatorId',
                'su.slug AS startUnitSlug', 'su.name AS startUnitName',
                'eu.slug AS endUnitSlug', 'eu.name AS endUnitName',
            )
            ->from(Conversion::class, 'c')
            ->leftJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = c.ingredientSlug')
            ->innerJoin(Unit::class, 'su', Join::WITH, 'su.slug = c.startUnitSlug')
            ->innerJoin(Unit::class, 'eu', Join::WITH, 'eu.slug = c.endUnitSlug');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ConversionDTO>
     */
    private function toDTOs(array $rows): array
    {
        $emails = $this->emailsOf(array_values(array_unique(array_filter(array_column($rows, 'creatorId')))));

        return array_map(
            static fn(array $row): ConversionDTO => new ConversionDTO(
                id: $row['id'],
                ingredientSlug: $row['ingredientSlug'],
                ingredientName: $row['ingredientName'],
                creatorEmail: $row['creatorId'] === null ? null : ($emails[$row['creatorId']] ?? null),
                startUnitSlug: $row['startUnitSlug'],
                startUnitName: $row['startUnitName'],
                endUnitSlug: $row['endUnitSlug'],
                endUnitName: $row['endUnitName'],
                coefficient: (float) $row['coefficient'],
            ),
            $rows,
        );
    }

    /**
     * Les emails des créateurs viennent de la table du contexte `User`, lue en SQL pour ne pas importer son entité
     * (ADR 0001 : un contexte n'importe pas l'infrastructure d'un autre).
     *
     * @param list<string> $userIds
     * @return array<string, string>
     */
    private function emailsOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->entityManager->getConnection()
            ->executeQuery('SELECT id, email FROM "user" WHERE id IN (?)', [$userIds], [ArrayParameterType::STRING])
            ->fetchAllKeyValue();
    }
}
