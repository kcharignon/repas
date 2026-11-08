<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Application\Service\FlattenedRecipeRows;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Service\RecipeFlattener;

/**
 * Le service qui aplatit une recette en lignes d'ingrédients pour une liste de courses (ADR 0040, COMP-004), construit sur le double en mémoire des
 * recettes que le test possède déjà : les handlers qui planifient, retirent ou changent les personnes d'un repas le reçoivent.
 */
final class FlatRows
{
    public static function for(RecipeRepository $recipes): FlattenedRecipeRows
    {
        return new FlattenedRecipeRows($recipes, new RecipeFlattener());
    }
}
