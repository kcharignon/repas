<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Repas\Repas\Application\Loader\ConversionLoader;
use Repas\Repas\Application\Port\AccountAddressQueryPort;
use Repas\Repas\Application\Query\ListConversions\ConversionDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionDTO;
use Repas\Repas\Application\Query\ListIngredientConversions\IngredientConversionsDTO;
use Repas\Repas\Domain\Exception\ConversionException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Service\ConversionGraph;
use Repas\Repas\Infrastructure\Entity\Conversion;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Unit;

final readonly class ConversionPostgreSQLLoader implements ConversionLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountAddressQueryPort $addresses,
    ) {
    }

    public function list(string $viewerId): array
    {
        return $this->toDTOs($this->query($viewerId)
            ->orderBy('c.ingredientSlug', 'ASC')
            ->addOrderBy('c.startUnitSlug', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult());
    }

    public function get(string $id, string $viewerId): ConversionDTO
    {
        $rows = $this->query($viewerId)
            ->andWhere('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            throw ConversionException::notFound($id);
        }

        return $this->toDTOs($rows)[0];
    }

    public function listOfIngredient(string $slug, string $viewerId): IngredientConversionsDTO
    {
        $ingredient = $this->entityManager->createQueryBuilder()
            ->select('i.creatorId AS creatorId', 'i.defaultCookingUnitSlug AS cookingUnitSlug', 'i.defaultPurchaseUnitSlug AS purchaseUnitSlug')
            ->from(Ingredient::class, 'i')
            ->where('i.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        // L'ingrédient d'un autre n'existe pas pour le lecteur, administrateur compris
        if ($ingredient === null || $ingredient['creatorId'] !== $viewerId) {
            throw IngredientException::notFound($slug);
        }

        // Une seule lecture : les conversions propres de l'ingrédient et les universelles, avec le nom de leurs unités
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'c.id AS id', 'c.coefficient AS coefficient', 'c.ingredientSlug AS ingredientSlug',
                'su.slug AS startUnitSlug', 'su.name AS startUnitName',
                'eu.slug AS endUnitSlug', 'eu.name AS endUnitName',
            )
            ->from(Conversion::class, 'c')
            ->innerJoin(Unit::class, 'su', Join::WITH, 'su.slug = c.startUnitSlug')
            ->innerJoin(Unit::class, 'eu', Join::WITH, 'eu.slug = c.endUnitSlug')
            ->where('c.ingredientSlug = :slug OR c.ingredientSlug IS NULL')
            ->setParameter('slug', $slug)
            ->orderBy('su.name', 'ASC')
            ->addOrderBy('eu.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $own = array_values(array_filter($rows, static fn(array $row): bool => $row['ingredientSlug'] !== null));
        $universal = array_values(array_filter($rows, static fn(array $row): bool => $row['ingredientSlug'] === null));

        // Le graphe de l'ingrédient comme le parcourt `ConversionService` : ses conversions, puis les universelles
        $graph = ConversionGraph::fromConversions(array_map(
            static fn(array $row): array => [$row['startUnitSlug'], $row['endUnitSlug'], (float) $row['coefficient']],
            [...$own, ...$universal],
        ));
        $cooking = $ingredient['cookingUnitSlug'];
        $purchase = $ingredient['purchaseUnitSlug'];

        // Les unités de l'ingrédient : celles des recettes et d'achat, et celles de ses conversions propres ; une universelle qui en touche une
        // est montrée (en lecture seule)
        $units = [$cooking => true, $purchase => true];
        foreach ($own as $row) {
            $units[$row['startUnitSlug']] = true;
            $units[$row['endUnitSlug']] = true;
        }
        $touching = array_values(array_filter(
            $universal,
            static fn(array $row): bool => isset($units[$row['startUnitSlug']]) || isset($units[$row['endUnitSlug']]),
        ));

        $toDto = static fn(array $row): IngredientConversionDTO => new IngredientConversionDTO(
            id: $row['id'],
            startUnitSlug: $row['startUnitSlug'],
            startUnitName: $row['startUnitName'],
            endUnitSlug: $row['endUnitSlug'],
            endUnitName: $row['endUnitName'],
            coefficient: (float) $row['coefficient'],
        );

        return new IngredientConversionsDTO(
            member: array_map($toDto, $own),
            common: array_map($toDto, $touching),
            totalItems: count($own),
            convertible: $graph->coefficient($cooking, $purchase) !== null,
            cookingUnitSlug: $cooking,
            purchaseUnitSlug: $purchase,
        );
    }

    /**
     * Les conversions générales et celles des ingrédients du lecteur : l'ingrédient d'un autre n'existe pas pour lui, administrateur compris.
     */
    private function query(string $viewerId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'c.id AS id', 'c.coefficient AS coefficient',
                'i.slug AS ingredientSlug', 'i.name AS ingredientName', 'i.creatorId AS creatorId',
                'su.slug AS startUnitSlug', 'su.name AS startUnitName',
                'eu.slug AS endUnitSlug', 'eu.name AS endUnitName',
                'd.slug AS departmentSlug', 'd.name AS departmentName',
            )
            ->from(Conversion::class, 'c')
            ->leftJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = c.ingredientSlug')
            ->leftJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'su', Join::WITH, 'su.slug = c.startUnitSlug')
            ->innerJoin(Unit::class, 'eu', Join::WITH, 'eu.slug = c.endUnitSlug')
            ->where('c.ingredientSlug IS NULL OR i.creatorId = :viewer')
            ->setParameter('viewer', $viewerId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ConversionDTO>
     */
    private function toDTOs(array $rows): array
    {
        $emails = $this->addresses->addressesOf(array_values(array_unique(array_filter(array_column($rows, 'creatorId')))));

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
                departmentSlug: $row['departmentSlug'],
                departmentName: $row['departmentName'],
            ),
            $rows,
        );
    }
}
