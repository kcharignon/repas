<?php

namespace Repas\Repas\Infrastructure\Reader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Domain\Model\ActiveListLine;
use Repas\Repas\Domain\Model\ActiveListMeal;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Meal;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Repas\Infrastructure\Entity\ShoppingListType;
use Repas\Repas\Infrastructure\Entity\Unit;

/**
 * Les lectures du contenu des listes de courses EN COURS d'un utilisateur, écrites une seule fois : `ActiveShoppingListLinesPostgreSQLLoader` (lecture)
 * comme `ActiveShoppingListContentsPostgreSQLRepository` (une écriture qui en tire un choix dans sa transaction, ADR 0035) rendent donc exactement la
 * même chose. Chaque lecture est de deux requêtes : les listes en cours (aucune : `null`), puis leurs lignes ou leurs repas.
 *
 * Les listes en cours sont la liste principale et, depuis les types de liste (ADR 0036, LST-008), la liste en cours de chaque type : la principale
 * d'abord, puis les types dans l'ordre de leur création. Un compte sans type n'en a qu'une, et lit exactement ce qu'il lisait avant.
 */
final readonly class ActiveShoppingListReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Les lignes des listes en cours (statut `ACTIVE` : une liste envoyée au drive, en pause ou terminée n'en est pas une), chacune avec le nom de
     * son ingrédient, son rayon, le nom de son unité et la liste qui la porte. Les lignes d'une liste sont celles de l'utilisateur : aucun contrôle de
     * visibilité des ingrédients à refaire. Un même ingrédient a une ligne par liste qui le porte, la liste principale d'abord.
     *
     * @return ?list<ActiveListLine> `null` : l'utilisateur n'a pas de liste en cours ; une liste en cours vide donne une liste vide
     */
    public function lines(string $userId): ?array
    {
        $lists = $this->activeLists($userId);
        if ($lists === null) {
            return null;
        }

        /** @var list<array{listId: string, slug: string, name: string, departmentSlug: string, departmentName: string, quantity: float|int|string, unitSlug: string, unitName: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.shoppingListId AS listId', 'r.ingredientSlug AS slug', 'i.name AS name', 'd.slug AS departmentSlug', 'd.name AS departmentName', 'r.quantity AS quantity', 'u.slug AS unitSlug', 'u.name AS unitName')
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = r.ingredientSlug')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = r.unitSlug')
            ->where('r.shoppingListId IN (:lists)')
            ->setParameter('lists', array_keys($lists))
            ->orderBy('r.ingredientSlug')
            ->getQuery()
            ->getArrayResult();

        // Par ingrédient, la liste principale d'abord (l'ordre des listes est celui de `activeLists`)
        $rank = array_flip(array_keys($lists));
        usort($rows, static fn(array $a, array $b): int => [$a['slug'], $rank[$a['listId']]] <=> [$b['slug'], $rank[$b['listId']]]);

        return array_map(
            static fn(array $row): ActiveListLine => new ActiveListLine($row['slug'], $row['name'], $row['departmentSlug'], $row['departmentName'], (float) $row['quantity'], $row['unitSlug'], $row['unitName'], $lists[$row['listId']]),
            $rows,
        );
    }

    /**
     * Les repas des listes en cours, avec le nom de leur recette. Une recette éclatée entre plusieurs listes (ADR 0036 décision 9) a un repas dans
     * chacune : elle n'est rendue qu'une fois, avec le nombre de personnes de la liste qui passe en premier (celui du groupe : il est le même partout).
     * Une liste ne planifie que les recettes de son propriétaire (`ShoppingList::addMeal`), mais un repas hérité d'avant cette garde peut pointer vers
     * la recette d'un autre : si elle est secrète (ADR 0033) le repas n'est pas rendu, comme celui d'une recette qui n'existe plus.
     *
     * @return ?list<ActiveListMeal> `null` : l'utilisateur n'a pas de liste en cours ; des listes en cours sans repas donnent une liste vide
     */
    public function meals(string $userId): ?array
    {
        $lists = $this->activeLists($userId);
        if ($lists === null) {
            return null;
        }

        /** @var list<array{listId: string, recipeId: string, name: string, servings: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.shoppingListId AS listId', 'm.recipeId AS recipeId', 'r.name AS name', 'm.serving AS servings')
            ->from(Meal::class, 'm')
            ->innerJoin(Recipe::class, 'r', Join::WITH, 'r.id = m.recipeId AND (r.secret = false OR r.authorId = :owner)')
            ->where('m.shoppingListId IN (:lists)')
            ->setParameter('lists', array_keys($lists))
            ->setParameter('owner', $userId)
            ->orderBy('m.recipeId')
            ->getQuery()
            ->getArrayResult();

        $rank = array_flip(array_keys($lists));
        usort($rows, static fn(array $a, array $b): int => [$a['recipeId'], $rank[$a['listId']]] <=> [$b['recipeId'], $rank[$b['listId']]]);

        $meals = [];
        foreach ($rows as $row) {
            $meals[$row['recipeId']] ??= new ActiveListMeal($row['recipeId'], $row['name'], (int) $row['servings']);
        }

        return array_values($meals);
    }

    /**
     * L'utilisateur a une liste envoyée au drive (statut `SENT`) : de quoi dire « ta liste est déjà partie » plutôt que « pas de liste en cours »
     * quand il n'en a pas d'active. Une seule requête ; la liste envoyée n'est jamais lue.
     */
    public function hasSentList(string $userId): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('l.id')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :owner')
            ->andWhere('l.status = :status')
            ->setParameter('owner', $userId)
            ->setParameter('status', ShoppingListStatus::SENT)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult() !== [];
    }

    /**
     * Les listes en cours de l'utilisateur, la principale d'abord puis les types par date de création (comme `GET /shopping-lists/open`) : leur
     * identifiant, et le nom qu'on leur donne à voix haute (`null` pour la liste principale, sinon le nom de la liste, à défaut celui de son type).
     *
     * @return ?array<string, ?string> `null` : aucune liste en cours
     */
    private function activeLists(string $userId): ?array
    {
        /** @var list<array{id: string, listTypeId: string|null, name: string|null, typeName: string|null}> $lists */
        $lists = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id', 'l.listTypeId AS listTypeId', 'l.name AS name', 't.name AS typeName')
            ->addSelect('CASE WHEN l.listTypeId IS NULL THEN 0 ELSE 1 END AS HIDDEN typed')
            ->from(ShoppingList::class, 'l')
            ->leftJoin(ShoppingListType::class, 't', Join::WITH, 't.id = l.listTypeId')
            ->where('l.ownerId = :owner')
            ->andWhere('l.status = :status')
            ->setParameter('owner', $userId)
            ->setParameter('status', ShoppingListStatus::ACTIVE)
            ->orderBy('typed', 'ASC')
            ->addOrderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        if ($lists === []) {
            return null;
        }

        $named = [];
        foreach ($lists as $list) {
            $named[$list['id']] = $list['listTypeId'] === null ? null : ($list['name'] ?? $list['typeName'] ?? 'une autre liste');
        }

        return $named;
    }
}
