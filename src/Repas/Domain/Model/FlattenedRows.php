<?php

namespace Repas\Repas\Domain\Model;

use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Shared\Domain\Tool\Tab;
use SplObjectStorage;

/**
 * Les lignes d'ingrédients qu'un repas ajoute à une liste de courses (ou lui retire) : celles de la recette ET celles de ses recettes utilisées, mises
 * à l'échelle (ADR 0040 décisions 11 et 12), rendues par `RecipeFlattener`. Ce sont des COPIES : la recette garde ses quantités.
 *
 * Aucune fusion : deux lignes du même ingrédient (de la recette et d'une recette utilisée, ou d'unités différentes) restent deux lignes, la liste les
 * additionne à l'ajout. Chaque ligne se souvient de la recette utilisée d'où elle vient (`sourceNameOf`), de quoi dire laquelle ouvrir quand une
 * conversion manque (décision 16).
 */
final readonly class FlattenedRows
{
    /**
     * @param Tab<RecipeRow>                      $rows    dans l'ordre : celles de la recette, puis celles de chaque recette utilisée dans l'ordre de ses positions
     * @param SplObjectStorage<RecipeRow, string> $sources le nom de la recette utilisée, par ligne qui en vient (aucune entrée pour une ligne propre)
     */
    public function __construct(
        private Tab $rows,
        private SplObjectStorage $sources,
    ) {
    }

    /**
     * @return Tab<RecipeRow>
     */
    public function rows(): Tab
    {
        return $this->rows;
    }

    /**
     * Le nom de la recette utilisée d'où vient cette ligne ; `null` pour une ligne de la recette elle-même (et pour une ligne que ces lignes ne
     * contiennent pas). Vaut aussi pour une ligne gardée après un `filter` de `rows()` : c'est le même objet.
     */
    public function sourceNameOf(RecipeRow $row): ?string
    {
        return $this->sources->offsetExists($row) ? $this->sources[$row] : null;
    }

    /**
     * Une conversion manquante pour cette ligne dit d'où elle vient : si elle est celle d'une recette utilisée, l'exception en porte le nom
     * (`IngredientException::conversionRecipeName()`, que la commande vocale dit à son propriétaire). Toute autre erreur, et toute ligne propre,
     * passent telles quelles. Rend l'exception reçue, pour `throw $rows->attribute($exception, $row)`.
     */
    public function attribute(IngredientException $failure, RecipeRow $row): IngredientException
    {
        $source = $this->sourceNameOf($row);

        return $source !== null && $failure->isMissingConversion() ? $failure->inRecipe($source) : $failure;
    }
}
