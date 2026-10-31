<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\ShoppingListTypeLoader;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeDTO;
use Repas\Repas\Application\Query\GetShoppingListType\ShoppingListTypeIngredientDTO;
use Repas\Repas\Application\Query\ListShoppingListTypes\ShoppingListTypeSummaryDTO;
use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

/**
 * Lecture SQL des types de liste (ADR 0036) : trois requêtes au plus, quel que soit le nombre de types, de rayons et de produits. Un type se
 * lit par son propriétaire seul (`owner_id = :user`), les produits à part par la règle de visibilité des ingrédients.
 */
final readonly class ShoppingListTypePostgreSQLLoader implements ShoppingListTypeLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function list(string $userId): array
    {
        $connection = $this->entityManager->getConnection();

        $types = $connection->fetchAllAssociative(
            'SELECT id, name FROM shopping_list_type WHERE owner_id = ? ORDER BY created_at, id',
            [$userId],
        );
        if ($types === []) {
            return [];
        }
        $ids = array_column($types, 'id');

        $slugsByType = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT dlt.list_type_id, dlt.department_slug FROM department_list_type dlt '
            . 'INNER JOIN department d ON d.slug = dlt.department_slug WHERE dlt.owner_id = ? AND dlt.list_type_id IN (?) ORDER BY dlt.department_slug',
            [$userId, $ids],
            [1 => ArrayParameterType::STRING],
        ) as $row) {
            $slugsByType[$row['list_type_id']][] = $row['department_slug'];
        }

        $countByType = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT i.list_destination, COUNT(*) AS total FROM ingredient i WHERE ' . IngredientVisibility::ownedSql('i', 'user')
            . ' AND i.list_destination IN (:ids) GROUP BY i.list_destination',
            ['user' => $userId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $countByType[$row['list_destination']] = (int) $row['total'];
        }

        return array_map(
            static fn(array $type): ShoppingListTypeSummaryDTO => new ShoppingListTypeSummaryDTO(
                id: $type['id'],
                name: $type['name'],
                departmentSlugs: $slugsByType[$type['id']] ?? [],
                ingredientCount: $countByType[$type['id']] ?? 0,
            ),
            $types,
        );
    }

    public function get(string $id, string $userId): ShoppingListTypeDTO
    {
        $connection = $this->entityManager->getConnection();

        $type = $connection->fetchAssociative('SELECT id, name FROM shopping_list_type WHERE id = ? AND owner_id = ?', [$id, $userId]);
        if ($type === false) {
            throw ShoppingListTypeException::notFound();
        }

        $departments = array_map(
            static fn(array $row): ShoppingListTypeDepartmentDTO => new ShoppingListTypeDepartmentDTO($row['slug'], $row['name']),
            $connection->fetchAllAssociative(
                'SELECT d.slug, d.name FROM department_list_type dlt INNER JOIN department d ON d.slug = dlt.department_slug '
                . 'WHERE dlt.owner_id = ? AND dlt.list_type_id = ? ORDER BY d.slug',
                [$userId, $id],
            ),
        );

        $ingredients = array_map(
            static fn(array $row): ShoppingListTypeIngredientDTO => new ShoppingListTypeIngredientDTO($row['slug'], $row['name'], $row['department_slug'], $row['department_name']),
            $connection->fetchAllAssociative(
                'SELECT i.slug, i.name, d.slug AS department_slug, d.name AS department_name FROM ingredient i INNER JOIN department d ON d.slug = i.department '
                . 'WHERE ' . IngredientVisibility::ownedSql('i', 'user') . ' AND i.list_destination = :id ORDER BY i.slugged_name, i.slug',
                ['user' => $userId, 'id' => $id],
            ),
        );

        return new ShoppingListTypeDTO($type['id'], $type['name'], $departments, $ingredients);
    }
}
