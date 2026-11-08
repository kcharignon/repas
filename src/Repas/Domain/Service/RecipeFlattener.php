<?php

namespace Repas\Repas\Domain\Service;

use Repas\Repas\Domain\Model\FlattenedRows;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Shared\Domain\Tool\Tab;
use SplObjectStorage;

/**
 * Aplatit une recette en lignes d'ingrédients pour un nombre de personnes (ADR 0040 décisions 11 et 12) : le SEUL endroit où une recette est mise à
 * l'échelle pour une liste de courses (planifier, retirer, changer les personnes). Pur : la recette et ses recettes utilisées DÉJÀ chargées, aucune
 * lecture.
 *
 * Pour une recette de `s` personnes et un repas de `n` personnes, avec `k = n ÷ s` : les lignes de la recette × `k`, puis, pour chaque composant
 * `(C, m)` dans l'ordre des positions, les lignes de `C` TELLES QU'ELLES SONT ÉCRITES × `m` × `k` (le nombre de personnes de `C` n'intervient pas).
 * Aucun arrondi, aucune fusion : le produit reste un nombre fini (`RecipeRow::multiplyQuantityBy`).
 *
 * Profondeur 1, défensive : seules les lignes d'une recette utilisée sont lues, jamais ses propres composants (une recette utilisée qui en aurait,
 * ce que les écritures interdisent, est ignorée pour eux). Aucune récursion : aucune boucle possible. Un composant dont la recette utilisée manque
 * dans `$components` (supprimée, ou illisible pour le lecteur) est ignoré, jamais une exception (décision 21).
 */
final readonly class RecipeFlattener
{
    /**
     * @param array<string, Recipe> $components les recettes utilisées déjà chargées, par identifiant ; celles que `$recipe` n'utilise pas sont ignorées
     * @param int                   $serving    le nombre de personnes visé (celui d'un repas, ou la différence de deux nombres de personnes)
     */
    public function flatten(Recipe $recipe, array $components, int $serving): FlattenedRows
    {
        $scale = $serving / $recipe->getServing();

        $rows = [];
        /** @var SplObjectStorage<RecipeRow, string> $sources */
        $sources = new SplObjectStorage();
        foreach ($recipe->getRows() as $row) {
            $rows[] = (clone $row)->multiplyQuantityBy($scale);
        }

        foreach ($this->inOrder($recipe) as $component) {
            $used = $components[$component->getComponentId()] ?? null;
            if (!$used instanceof Recipe) {
                continue;
            }

            $coefficient = $component->getMultiplier() * $scale;
            foreach ($used->getRows() as $row) {
                $copy = (clone $row)->multiplyQuantityBy($coefficient);
                $rows[] = $copy;
                $sources[$copy] = $used->getName();
            }
        }

        return new FlattenedRows(new Tab($rows, RecipeRow::class), $sources);
    }

    /**
     * Les composants dans l'ordre de leurs positions, à égalité dans celui de leurs identifiants : toujours le même ordre de produits.
     *
     * @return list<RecipeComponent>
     */
    private function inOrder(Recipe $recipe): array
    {
        $components = array_values($recipe->getComponents()->toArray());
        usort($components, static fn(RecipeComponent $a, RecipeComponent $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        return $components;
    }
}
