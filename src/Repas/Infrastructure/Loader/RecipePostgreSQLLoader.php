<?php

namespace Repas\Repas\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Repas\Repas\Application\Loader\RecipeLoader;
use Repas\Repas\Application\Query\GetRecipe\RecipeDTO;
use Repas\Repas\Application\Query\GetRecipe\RecipeRowDTO;
use Repas\Repas\Application\Query\ListOtherPeopleRecipes\OtherPeopleRecipeDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeSummaryDTO;
use Repas\Repas\Application\Query\ListRecipesByType\RecipeTypeRecipesDTO;
use Repas\Repas\Application\Query\ListRecipeTypes\RecipeTypeDTO;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Infrastructure\Conversion\IngredientConversionGraphs;
use Repas\Repas\Infrastructure\Entity\Ingredient;
use Repas\Repas\Infrastructure\Entity\Recipe;
use Repas\Repas\Infrastructure\Entity\RecipeRow;
use Repas\Repas\Infrastructure\Entity\RecipeType;
use Repas\Repas\Infrastructure\Entity\Unit;

final readonly class RecipePostgreSQLLoader implements RecipeLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Les lignes sont dans l'ordre de la recette (colonne `position`, écrite dans l'ordre de la saisie à chaque enregistrement).
     *
     * Chaque ligne dit si son unité se convertit en l'unité d'achat de son ingrédient (`convertible`) : les conversions propres des ingrédients
     * de la recette et les universelles sont lues en UNE requête de plus (le nombre de requêtes ne dépend pas du nombre de lignes) et passées
     * au graphe pur de `ConversionService`, qui donne la même réponse que la planification. La recette d'un autre utilisateur se lit de la même
     * façon, sur les ingrédients de son auteur : on n'en apprend rien de plus que ce booléen et l'unité d'achat, une donnée publique.
     */
    public function get(string $id): RecipeDTO
    {
        $recipe = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name', 'r.serving AS serving', 't.slug AS typeSlug', 't.name AS typeName', 'r.version AS version')
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($recipe === null) {
            throw RecipeException::notFound($id);
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'i.slug AS ingredientSlug', 'i.name AS ingredientName', 'i.image AS ingredientImage',
                'rr.quantity AS quantity', 'u.slug AS unitSlug', 'u.symbol AS unitSymbol', 'u.name AS unitName',
                'pu.slug AS purchaseUnitSlug', 'pu.name AS purchaseUnitName',
            )
            ->from(RecipeRow::class, 'rr')
            ->innerJoin(Ingredient::class, 'i', Join::WITH, 'i.slug = rr.ingredientSlug')
            ->innerJoin(Unit::class, 'u', Join::WITH, 'u.slug = rr.unitSlug')
            ->innerJoin(Unit::class, 'pu', Join::WITH, 'pu.slug = i.defaultPurchaseUnitSlug')
            ->where('rr.recipeId = :id')
            ->setParameter('id', $id)
            ->orderBy('rr.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Le même graphe que la planification et que le rapport de santé des conversions (`repas:conversions:check`)
        $graphs = new IngredientConversionGraphs($this->entityManager->getConnection())->forIngredients(array_values(array_unique(array_column($rows, 'ingredientSlug'))));

        return new RecipeDTO(
            id: $recipe['id'],
            name: $recipe['name'],
            serving: (int) $recipe['serving'],
            typeSlug: $recipe['typeSlug'],
            typeName: $recipe['typeName'],
            rows: array_map(
                static fn(array $row): RecipeRowDTO => new RecipeRowDTO(
                    $row['ingredientSlug'], $row['ingredientName'], $row['ingredientImage'], (float) $row['quantity'], $row['unitSlug'], $row['unitSymbol'],
                    $row['unitName'], $row['purchaseUnitSlug'], $row['purchaseUnitName'],
                    $graphs[$row['ingredientSlug']]->connects($row['unitSlug'], $row['purchaseUnitSlug']),
                ),
                $rows,
            ),
            version: (int) $recipe['version'],
        );
    }

    public function listByAuthorAndType(string $authorId, string $typeSlug): RecipeTypeRecipesDTO
    {
        $type = $this->entityManager->createQueryBuilder()
            ->select('t.slug AS slug', 't.name AS name', 't.image AS image', 't.sequence AS sequence')
            ->from(RecipeType::class, 't')
            ->where('t.slug = :slug')
            ->setParameter('slug', $typeSlug)
            ->getQuery()
            ->getOneOrNullResult();

        if ($type === null) {
            throw RecipeException::typeNotFound($typeSlug);
        }

        $recipes = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name')
            ->from(Recipe::class, 'r')
            ->where('r.authorId = :authorId')
            ->andWhere('r.typeSlug = :typeSlug')
            ->setParameter('authorId', $authorId)
            ->setParameter('typeSlug', $typeSlug)
            ->orderBy('r.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return new RecipeTypeRecipesDTO(
            new RecipeTypeDTO($type['slug'], $type['name'], $type['image'], (int) $type['sequence']),
            array_map(static fn(array $recipe): RecipeSummaryDTO => new RecipeSummaryDTO($recipe['id'], $recipe['name']), $recipes),
        );
    }

    public function listOfOtherPeople(string $userId): array
    {
        $recipes = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id', 'r.name AS name', 't.name AS typeName')
            ->from(Recipe::class, 'r')
            ->innerJoin(RecipeType::class, 't', Join::WITH, 't.slug = r.typeSlug')
            ->where('r.authorId != :userId')
            ->andWhere('r.originalId IS NULL')
            ->andWhere(sprintf('NOT EXISTS (SELECT c.id FROM %s c WHERE c.authorId = :userId AND c.originalId = r.id)', Recipe::class))
            ->setParameter('userId', $userId)
            ->orderBy('r.slug', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $recipe): OtherPeopleRecipeDTO => new OtherPeopleRecipeDTO($recipe['id'], $recipe['name'], $recipe['typeName']),
            $recipes,
        );
    }
}
