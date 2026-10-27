<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\IngredientToFixLoader;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientsToFixDTO;
use Repas\Repas\Application\Query\ListIngredientsToFix\IngredientToFixDTO;
use Repas\Repas\Infrastructure\Conversion\IngredientConversionGraphs;

/**
 * Les ingrédients à régler d'un utilisateur, en SQL : aucun modèle de domaine n'est hydraté. DEUX lectures, quel que soit le nombre
 * d'ingrédients : (1) ses ingrédients dont les deux unités diffèrent, avec leur rayon et le nom des unités ; (2) les conversions de ces
 * ingrédients (les leurs, puis les universelles : `IngredientConversionGraphs`). « Se convertit en l'unité d'achat » est jugé par le même
 * `ConversionGraph` que la planification, la lecture d'une recette (`convertible`) et le rapport de santé. Une lecture : rien n'est écrit.
 */
final readonly class IngredientToFixPostgreSQLLoader implements IngredientToFixLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function toFix(string $userId): IngredientsToFixDTO
    {
        $connection = $this->entityManager->getConnection();

        // Les ingrédients de l'utilisateur seul (`creator`, comme `IngredientVisibility::visibleBy` en DQL) ; deux unités identiques : rien à convertir
        $rows = $connection->fetchAllAssociative(
            'SELECT i.slug, i.name, d.name AS department_name,'
            . ' i.default_cooking_unit AS cooking_slug, cooking.name AS cooking_name,'
            . ' i.default_purchase_unit AS purchase_slug, purchase.name AS purchase_name'
            . ' FROM ingredient i'
            . ' JOIN department d ON d.slug = i.department'
            . ' JOIN unit cooking ON cooking.slug = i.default_cooking_unit'
            . ' JOIN unit purchase ON purchase.slug = i.default_purchase_unit'
            . ' WHERE i.creator = :user AND i.default_cooking_unit <> i.default_purchase_unit'
            . ' ORDER BY i.name, i.slug',
            ['user' => $userId],
        );

        $graphs = new IngredientConversionGraphs($connection)->forIngredients(array_column($rows, 'slug'));

        $toFix = [];
        foreach ($rows as $row) {
            if ($graphs[$row['slug']]->connects($row['cooking_slug'], $row['purchase_slug'])) {
                continue;
            }
            $toFix[] = new IngredientToFixDTO(
                $row['slug'],
                $row['name'],
                $row['department_name'],
                $row['cooking_slug'],
                $row['cooking_name'],
                $row['purchase_slug'],
                $row['purchase_name'],
            );
        }

        return new IngredientsToFixDTO(array_slice($toFix, 0, self::LIMIT), count($toFix));
    }
}
