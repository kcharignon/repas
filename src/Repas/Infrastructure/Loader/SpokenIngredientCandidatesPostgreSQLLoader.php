<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Psr\Clock\ClockInterface;
use Repas\Repas\Application\Loader\SpokenIngredientCandidates;
use Repas\Repas\Domain\Model\SpokenCandidate;
use Repas\Repas\Infrastructure\Entity\Department;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\RecipeRow;
use Repas\Repas\Infrastructure\Entity\ShoppingList;
use Repas\Repas\Infrastructure\Entity\ShoppingListRow;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

/**
 * Les ingrédients de l'utilisateur et son habitude de chacun, en trois requêtes quel que soit leur nombre : les ingrédients visibles
 * (`IngredientVisibility::visibleBy`), puis deux agrégats par ingrédient (lignes de ses listes, lignes de ses recettes) ; la jointure
 * se fait en PHP sur le slug.
 *
 * `uses12m` additionne :
 *  - les lignes (`shopping_list_row`) des listes de courses de l'utilisateur créées depuis douze mois (`shopping_list.created_at`, la seule
 *    date d'une liste ; une ligne ajoutée aujourd'hui à une très vieille liste ne compte donc pas) ;
 *  - les lignes (`recipe_row`) de ses recettes **sans limite de date** : une recette n'a ni date de création ni de modification dans le
 *    schéma. La fenêtre de douze mois ne s'applique donc qu'aux listes.
 */
final readonly class SpokenIngredientCandidatesPostgreSQLLoader implements SpokenIngredientCandidates
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function forUser(string $userId): array
    {
        /** @var list<array{slug: string, sluggedName: string, name: string, departmentName: string}> $ingredients */
        $ingredients = $this->entityManager->createQueryBuilder()
            ->select('i.slug AS slug', 'i.sluggedName AS sluggedName', 'i.name AS name', 'd.name AS departmentName')
            ->from(Ingredient::class, 'i')
            ->innerJoin(Department::class, 'd', Join::WITH, 'd.slug = i.departmentSlug')
            ->where(IngredientVisibility::visibleBy('i', 'viewer'))
            ->setParameter('viewer', $userId)
            ->orderBy('i.slug')
            ->getQuery()
            ->getArrayResult();
        if ($ingredients === []) {
            return [];
        }

        $uses = $this->listUses($userId);
        foreach ($this->recipeUses($userId) as $slug => $count) {
            $uses[$slug] = ($uses[$slug] ?? 0) + $count;
        }

        return array_map(
            static fn(array $row): SpokenCandidate => new SpokenCandidate($row['slug'], $row['sluggedName'], $row['name'], $row['departmentName'], $uses[$row['slug']] ?? 0),
            $ingredients,
        );
    }

    /**
     * @return array<string, int> le nombre de lignes de listes des douze derniers mois, par slug d'ingrédient
     */
    private function listUses(string $userId): array
    {
        $since = $this->clock->now()->modify('-12 months');

        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.ingredientSlug AS slug', 'COUNT(r.id) AS uses')
            ->from(ShoppingListRow::class, 'r')
            ->innerJoin(ShoppingList::class, 'l', Join::WITH, 'l.id = r.shoppingListId')
            ->where('l.ownerId = :viewer')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('viewer', $userId)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->groupBy('r.ingredientSlug')
            ->getQuery()
            ->getArrayResult();

        return $this->indexUses($rows);
    }

    /**
     * @return array<string, int> le nombre de lignes de recettes de l'utilisateur, par slug d'ingrédient
     */
    private function recipeUses(string $userId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.ingredientSlug AS slug', 'COUNT(r.id) AS uses')
            ->from(RecipeRow::class, 'r')
            ->innerJoin(Recipe::class, 'p', Join::WITH, 'p.id = r.recipeId')
            ->where('p.authorId = :viewer')
            ->setParameter('viewer', $userId)
            ->groupBy('r.ingredientSlug')
            ->getQuery()
            ->getArrayResult();

        return $this->indexUses($rows);
    }

    /**
     * @param list<array{slug: string, uses: int|string}> $rows
     *
     * @return array<string, int>
     */
    private function indexUses(array $rows): array
    {
        $uses = [];
        foreach ($rows as $row) {
            $uses[$row['slug']] = (int) $row['uses'];
        }

        return $uses;
    }
}
