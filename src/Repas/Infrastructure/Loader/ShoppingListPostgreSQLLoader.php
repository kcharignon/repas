<?php

namespace Repas\Repas\Infrastructure\Loader;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use IntlDateFormatter;
use Repas\Repas\Application\Loader\ShoppingListLoader;
use Repas\Repas\Application\Query\GetActiveShoppingListSummary\ActiveShoppingListSummaryDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDepartmentDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListDetailDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListMealDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRecipeTypeDTO;
use Repas\Repas\Application\Query\GetShoppingList\ShoppingListRowDTO;
use Repas\Repas\Application\Query\ListOpenShoppingLists\OpenShoppingListDTO;
use Repas\Repas\Application\Query\ListShoppingLists\ShoppingListDTO;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Meal;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\RecipeType;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Repas\Infrastructure\Entity\ShoppingListType;
use Repas\Repas\Infrastructure\Entity\Unit;
use Repas\Repas\Infrastructure\Presentation\ShoppingListDateLabel;

final readonly class ShoppingListPostgreSQLLoader implements ShoppingListLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function listByOwner(string $ownerId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.name AS name', 'l.status AS status', 'l.createdAt AS createdAt', 'l.sentAt AS sentAt', 'l.listTypeId AS listTypeId')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $defaultNameFormatter = $this->dateFormatter();

        return array_map(
            static function (array $row) use ($defaultNameFormatter): ShoppingListDTO {
                /** @var DateTimeImmutable $createdAt */
                $createdAt = $row['createdAt'];
                /** @var ShoppingListStatus $status */
                $status = $row['status'];

                return new ShoppingListDTO(
                    id: $row['id'],
                    displayName: $row['name'] ?? $defaultNameFormatter->format($createdAt),
                    status: $status->value,
                    isActive: $status === ShoppingListStatus::ACTIVE,
                    isPaused: $status === ShoppingListStatus::PAUSED,
                    isCompleted: $status === ShoppingListStatus::COMPLETED,
                    isSent: $status === ShoppingListStatus::SENT,
                    createdAt: $createdAt->format(DATE_ATOM),
                    sentAt: $row['sentAt']?->format(DATE_ATOM),
                    typeId: $row['listTypeId'],
                );
            },
            $rows,
        );
    }

    public function listOpenByOwner(string $ownerId): array
    {
        // Une seule lecture : les listes en cours, le nombre de leurs lignes et celles qui restent à cocher, le type (jointure externe : la
        // liste principale n'en a pas) pour ranger la principale d'abord, puis les types par date de création du type
        $rows = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.name AS name', 'l.createdAt AS createdAt', 'l.listTypeId AS listTypeId', 'l.version AS version')
            ->addSelect('COUNT(r.id) AS rowCount')
            ->addSelect('COALESCE(SUM(CASE WHEN r.checked = false THEN 1 ELSE 0 END), 0) AS uncheckedCount')
            ->from(ShoppingList::class, 'l')
            ->leftJoin(ShoppingListType::class, 't', Join::WITH, 't.id = l.listTypeId')
            ->leftJoin(ShoppingListRow::class, 'r', Join::WITH, 'r.shoppingListId = l.id')
            ->where('l.ownerId = :ownerId')
            ->andWhere('l.status = :status')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', ShoppingListStatus::ACTIVE)
            ->groupBy('l.id', 't.id')
            ->addSelect('CASE WHEN l.listTypeId IS NULL THEN 0 ELSE 1 END AS HIDDEN main')
            ->addSelect('t.createdAt AS HIDDEN typeCreatedAt')
            ->orderBy('main', 'ASC')
            ->addOrderBy('typeCreatedAt', 'ASC')
            ->addOrderBy('l.listTypeId', 'ASC')
            ->addOrderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $defaultNameFormatter = $this->dateFormatter();

        return array_map(
            static function (array $row) use ($defaultNameFormatter): OpenShoppingListDTO {
                /** @var DateTimeImmutable $createdAt */
                $createdAt = $row['createdAt'];

                return new OpenShoppingListDTO(
                    id: $row['id'],
                    name: $row['name'] ?? $defaultNameFormatter->format($createdAt),
                    typeId: $row['listTypeId'],
                    rowCount: (int) $row['rowCount'],
                    uncheckedCount: (int) $row['uncheckedCount'],
                    version: (int) $row['version'],
                );
            },
            $rows,
        );
    }

    public function getActiveSummary(string $ownerId): ActiveShoppingListSummaryDTO
    {
        $active = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :ownerId')
            ->andWhere('l.status = :status')
            ->andWhere('l.listTypeId IS NULL')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', ShoppingListStatus::ACTIVE)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($active === null) {
            return new ActiveShoppingListSummaryDTO(null, [], []);
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.ingredientSlug AS ingredientSlug', 'r.quantity AS quantity')
            ->from(ShoppingListRow::class, 'r')
            ->where('r.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $active['id'])
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $recipeIds = $this->entityManager->createQueryBuilder()
            ->select('m.recipeId AS recipeId')
            ->from(Meal::class, 'm')
            ->where('m.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $active['id'])
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        $quantities = [];
        foreach ($rows as $row) {
            // Une seule ligne par ingrédient dans une liste : la première l'emporte, comme l'ancien `getIngredientQuantity()`
            $quantities[$row['ingredientSlug']] ??= (float) $row['quantity'];
        }

        return new ActiveShoppingListSummaryDTO($active['id'], $quantities, array_values($recipeIds));
    }
    public function get(string $id): ShoppingListDetailDTO
    {
        $list = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.name AS name', 'l.status AS status', 'l.createdAt AS createdAt', 'l.sentAt AS sentAt', 'l.version AS version')
            ->from(ShoppingList::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($list === null) {
            throw ShoppingListException::shoppingListNotFound($id);
        }

        return $this->buildDetail($list);
    }

    public function getCurrent(string $ownerId): ?ShoppingListDetailDTO
    {
        $current = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id')
            ->addSelect('CASE WHEN l.status = :active AND l.listTypeId IS NULL THEN 0 WHEN l.status = :active THEN 1 ELSE 2 END AS HIDDEN priority')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('active', ShoppingListStatus::ACTIVE)
            ->orderBy('priority', 'ASC')
            ->addOrderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $current === null ? null : $this->get($current['id']);
    }

    public function getByMealId(string $mealId): ShoppingListDetailDTO
    {
        $meal = $this->entityManager->createQueryBuilder()
            ->select('m.shoppingListId AS shoppingListId')
            ->from(Meal::class, 'm')
            ->where('m.id = :id')
            ->setParameter('id', $mealId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($meal === null) {
            throw MealException::notFound();
        }

        return $this->get($meal['shoppingListId']);
    }

    /**
     * @param array{id: string, name: ?string, status: ShoppingListStatus, createdAt: DateTimeImmutable, sentAt: ?DateTimeImmutable, version: int} $list
     */
    private function buildDetail(array $list): ShoppingListDetailDTO
    {
        $departments = $this->loadDepartments($list['id']);
        $recipeTypes = $this->loadRecipeTypes($list['id']);
        $dateLabel = $this->dateFormatter()->format($list['createdAt']);
        $status = $list['status'];

        return new ShoppingListDetailDTO(
            id: $list['id'],
            name: $list['name'],
            displayName: $list['name'] ?? 'Liste du ' . $dateLabel,
            dateLabel: $dateLabel,
            createdAt: $list['createdAt']->format(DATE_ATOM),
            status: $status->value,
            isActive: $status === ShoppingListStatus::ACTIVE,
            isPaused: $status === ShoppingListStatus::PAUSED,
            isCompleted: $status === ShoppingListStatus::COMPLETED,
            isSent: $status === ShoppingListStatus::SENT,
            sentAt: $list['sentAt']?->format(DATE_ATOM),
            rowCount: array_sum(array_map(static fn(ShoppingListDepartmentDTO $department): int => count($department->rows), $departments)),
            recipeCount: array_sum(array_map(static fn(ShoppingListRecipeTypeDTO $type): int => count($type->meals), $recipeTypes)),
            departments: $departments,
            recipeTypes: $recipeTypes,
            version: $list['version'],
        );
    }

    /**
     * Rayons triés par slug, lignes de chaque rayon triées par slug d'ingrédient (tri PHP, comme l'ancien modèle de domaine :
     * la collation PostgreSQL ordonne autrement les slugs à tirets).
     *
     * @return list<ShoppingListDepartmentDTO>
     */
    private function loadDepartments(string $shoppingListId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'r.id AS id', 'r.quantity AS quantity', 'r.checked AS checked',
                'i.slug AS ingredientSlug', 'i.name AS ingredientName',
                'd.slug AS departmentSlug', 'd.name AS departmentName', 'd.image AS departmentImage',
                'u.symbol AS unitSymbol', 'u.slug AS unitSlug',
            )
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = r.ingredientSlug')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = r.unitSlug')
            ->where('r.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingListId)
            ->getQuery()
            ->getArrayResult();

        usort($rows, static fn(array $a, array $b): int => $a['ingredientSlug'] <=> $b['ingredientSlug']);

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['departmentSlug']] ??= ['name' => $row['departmentName'], 'image' => $row['departmentImage'], 'rows' => []];
            $groups[$row['departmentSlug']]['rows'][] = new ShoppingListRowDTO(
                id: $row['id'],
                ingredientName: $row['ingredientName'],
                quantity: (float) $row['quantity'],
                unitSymbol: $row['unitSymbol'],
                checked: (bool) $row['checked'],
                ingredientSlug: $row['ingredientSlug'],
                unitSlug: $row['unitSlug'],
                departmentSlug: $row['departmentSlug'],
                departmentName: $row['departmentName'],
            );
        }
        ksort($groups, SORT_STRING);

        return array_values(array_map(
            static fn(string $slug, array $group): ShoppingListDepartmentDTO => new ShoppingListDepartmentDTO($slug, $group['name'], $group['image'], $group['rows']),
            array_keys($groups),
            $groups,
        ));
    }

    /**
     * Types triés par ordre d'affichage, repas de chaque type triés par nom de recette.
     *
     * Une recette secrète (ADR 0033) dont l'auteur n'est pas le propriétaire de la liste n'a ni nom ni type ici : un repas hérité d'avant
     * `ShoppingList::addMeal` (une liste ne planifie plus que les recettes de son propriétaire) qui pointe vers elle n'est pas rendu, comme
     * celui d'une recette qui n'existe plus (jointure interne). Le nom d'une secrète ne sort jamais d'une liste qui n'est pas à son auteur.
     *
     * @return list<ShoppingListRecipeTypeDTO>
     */
    private function loadRecipeTypes(string $shoppingListId): array
    {
        $meals = $this->entityManager->createQueryBuilder()
            ->select(
                'm.id AS id', 'm.serving AS serving',
                'r.id AS recipeId', 'r.name AS recipeName',
                't.slug AS typeSlug', 't.name AS typeName', 't.image AS typeImage',
            )
            ->from(Meal::class, 'm')
            ->innerJoin(ShoppingList::class, 'l', Join::WITH, 'l.id = m.shoppingListId')
            ->innerJoin(Recipe::class, 'r', Join::WITH, 'r.id = m.recipeId AND (r.secret = false OR r.authorId = l.ownerId)')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            ->where('m.shoppingListId = :shoppingListId')
            ->setParameter('shoppingListId', $shoppingListId)
            ->orderBy('t.sequence', 'ASC')
            ->addOrderBy('t.slug', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $groups = [];
        foreach ($meals as $meal) {
            $groups[$meal['typeSlug']] ??= ['name' => $meal['typeName'], 'image' => $meal['typeImage'], 'meals' => []];
            $groups[$meal['typeSlug']]['meals'][] = new ShoppingListMealDTO($meal['id'], $meal['recipeId'], $meal['recipeName'], (int) $meal['serving']);
        }

        return array_values(array_map(
            static fn(string $slug, array $group): ShoppingListRecipeTypeDTO => new ShoppingListRecipeTypeDTO($slug, $group['name'], $group['image'], $group['meals']),
            array_keys($groups),
            $groups,
        ));
    }

    private function dateFormatter(): IntlDateFormatter
    {
        return ShoppingListDateLabel::formatter();
    }
}
