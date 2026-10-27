<?php

namespace Repas\Repas\Application\Query\GetConversionHealth;

use Repas\Shared\Application\Interface\QueryInterface;

/**
 * Le rapport de santé des conversions (`repas:conversions:check`) : les conversions universelles de référence présentes ou manquantes, les
 * conversions propres qui répètent une paire universelle (et celles qu'une ancienne version a détournées), et, par compte, les ingrédients et les
 * lignes de recettes dont l'unité ne se convertit pas en l'unité d'achat. Lecture seule, des nombres et des unités seulement.
 */
final readonly class GetConversionHealthQuery implements QueryInterface
{
}
