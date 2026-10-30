<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Loader\PlannedMealsInActiveList;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Repas\Infrastructure\Entity\Meal;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Shared\Application\Dto\ListedMeal;

/**
 * Les repas de la liste active de l'utilisateur en deux requêtes : l'identifiant de la liste active (aucune : `null`), puis ses repas avec le nom de
 * leur recette. Une liste ne planifie que les recettes de son propriétaire (`ShoppingList::addMeal`), mais un repas hérité d'avant cette garde peut
 * pointer vers la recette d'un autre : si elle est secrète (ADR 0033) le repas n'est pas rendu, comme celui d'une recette qui n'existe plus.
 */
final readonly class PlannedMealsInActiveListPostgreSQLLoader implements PlannedMealsInActiveList
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function forUser(string $userId): ?array
    {
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
        if ($lists === []) {
            return null;
        }

        /** @var list<array{recipeId: string, name: string, servings: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.recipeId AS recipeId', 'r.name AS name', 'm.serving AS servings')
            ->from(Meal::class, 'm')
            ->innerJoin(Recipe::class, 'r', Join::WITH, 'r.id = m.recipeId AND (r.secret = false OR r.authorId = :owner)')
            ->where('m.shoppingListId = :list')
            ->setParameter('list', $lists[0]['id'])
            ->setParameter('owner', $userId)
            ->orderBy('m.recipeId')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): ListedMeal => new ListedMeal($row['recipeId'], $row['name'], (int) $row['servings']),
            $rows,
        );
    }
}
