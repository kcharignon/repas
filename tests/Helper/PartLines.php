<?php

namespace Repas\Tests\Helper;


use Repas\Repas\Application\Service\PartAwareLines;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Service\ConversionService;
use Repas\Repas\Domain\Service\PartEquivalence;

/**
 * Le service qui prépare les quantités d'une liste quand un ingrédient est une partie d'un autre (ADR 0038, SUB-003), construit sur les doubles en
 * mémoire que le test possède déjà : les handlers qui posent une recette ou un produit dans une liste le reçoivent.
 */
final class PartLines
{
    public static function for(IngredientRepository $ingredients, ConversionService $conversions): PartAwareLines
    {
        return new PartAwareLines($ingredients, $conversions, new PartEquivalence($conversions));
    }
}
