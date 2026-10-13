<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\MealException;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Meal;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Shared\Application\Interface\OwnershipLoader;

final readonly class OwnershipPostgreSQLLoader implements OwnershipLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function ingredientCreatorId(string $slug): ?string
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('i.creatorId AS owner')
            ->from(Ingredient::class, 'i')
            ->where('i.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? throw IngredientException::notFound($slug) : $row['owner'];
    }

    public function shoppingListOwnerId(string $shoppingListId): string
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('l.ownerId AS owner')
            ->from(ShoppingList::class, 'l')
            ->where('l.id = :id')
            ->setParameter('id', $shoppingListId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? throw ShoppingListException::shoppingListNotFound($shoppingListId) : $row['owner'];
    }

    public function recipeAuthorId(string $recipeId): string
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('r.authorId AS owner')
            ->from(Recipe::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $recipeId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? throw RecipeException::notFound($recipeId) : $row['owner'];
    }

    public function mealOwnerId(string $mealId): string
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('l.ownerId AS owner')
            ->from(Meal::class, 'm')
            ->innerJoin(ShoppingList::class, 'l', Join::WITH, 'l.id = m.shoppingListId')
            ->where('m.id = :id')
            ->setParameter('id', $mealId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? throw MealException::notFound() : $row['owner'];
    }
}
