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
use Repas\Repas\Infrastructure\Entity\Unit;

/**
 * Les lectures du contenu de la liste de courses ACTIVE d'un utilisateur, écrites une seule fois : `ActiveShoppingListLinesPostgreSQLLoader` (lecture) comme
 * `ActiveShoppingListContentsPostgreSQLRepository` (une écriture qui en tire un choix dans sa transaction, ADR 0035) rendent donc exactement la même
 * chose. Chaque lecture est de deux requêtes : l'identifiant de la liste active (aucune :
 * `null`), puis ses lignes ou ses repas.
 */
final readonly class ActiveShoppingListReader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Les lignes de la liste active (statut `ACTIVE` : une liste envoyée au drive, en pause ou terminée n'en est pas une), chacune avec le nom de
     * son ingrédient, son rayon et le nom de son unité. Les lignes d'une liste sont celles de l'utilisateur : aucun contrôle de visibilité des
     * ingrédients à refaire.
     *
     * @return ?list<ActiveListLine> `null` : l'utilisateur n'a pas de liste active ; une liste active vide donne une liste vide
     */
    public function lines(string $userId): ?array
    {
        $listId = $this->activeListId($userId);
        if ($listId === null) {
            return null;
        }

        /** @var list<array{slug: string, name: string, departmentSlug: string, departmentName: string, quantity: float|int|string, unitSlug: string, unitName: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.ingredientSlug AS slug', 'i.name AS name', 'd.slug AS departmentSlug', 'd.name AS departmentName', 'r.quantity AS quantity', 'u.slug AS unitSlug', 'u.name AS unitName')
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = r.ingredientSlug')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = r.unitSlug')
            ->where('r.shoppingListId = :list')
            ->setParameter('list', $listId)
            ->orderBy('r.ingredientSlug')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): ActiveListLine => new ActiveListLine($row['slug'], $row['name'], $row['departmentSlug'], $row['departmentName'], (float) $row['quantity'], $row['unitSlug'], $row['unitName']),
            $rows,
        );
    }

    /**
     * Les repas de la liste active, avec le nom de leur recette. Une liste ne planifie que les recettes de son propriétaire
     * (`ShoppingList::addMeal`), mais un repas hérité d'avant cette garde peut pointer vers la recette d'un autre : si elle est secrète (ADR 0033) le
     * repas n'est pas rendu, comme celui d'une recette qui n'existe plus.
     *
     * @return ?list<ActiveListMeal> `null` : l'utilisateur n'a pas de liste active ; une liste active sans repas donne une liste vide
     */
    public function meals(string $userId): ?array
    {
        $listId = $this->activeListId($userId);
        if ($listId === null) {
            return null;
        }

        /** @var list<array{recipeId: string, name: string, servings: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.recipeId AS recipeId', 'r.name AS name', 'm.serving AS servings')
            ->from(Meal::class, 'm')
            ->innerJoin(Recipe::class, 'r', Join::WITH, 'r.id = m.recipeId AND (r.secret = false OR r.authorId = :owner)')
            ->where('m.shoppingListId = :list')
            ->setParameter('list', $listId)
            ->setParameter('owner', $userId)
            ->orderBy('m.recipeId')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): ActiveListMeal => new ActiveListMeal($row['recipeId'], $row['name'], (int) $row['servings']),
            $rows,
        );
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

    private function activeListId(string $userId): ?string
    {
        /** @var list<array{id: string}> $lists */
        $lists = $this->entityManager->createQueryBuilder()
            ->select('l.id AS id')
            ->from(ShoppingList::class, 'l')
            ->where('l.ownerId = :owner')
            ->andWhere('l.status = :status')
            ->setParameter('owner', $userId)
            ->setParameter('status', ShoppingListStatus::ACTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $lists === [] ? null : $lists[0]['id'];
    }
}
