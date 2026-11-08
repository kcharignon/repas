<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Domain\Model\Recipe;

/**
 * Ce que la copie d'une recette composée fait de ses recettes utilisées (ADR 0040 décisions 33 à 35), décidé par `RecipeCopier` avant la première
 * écriture :
 *  - `$toCopy` : les recettes utilisées à COPIER, créées avant la recette composée, par l'identifiant (déjà choisi) de leur copie, dans l'ordre des positions ;
 *  - `$components` : ce que la copie de la recette composée utilisera, dans l'ordre des positions : l'identifiant de la recette utilisée (la copie qu'on
 *    vient de décider, la copie que le copieur avait déjà, ou, pour l'auteur qui copie sa propre recette, la recette utilisée elle-même) et le multiplicateur.
 *
 * Vide pour une recette sans composant, dont la copie est celle d'avant.
 */
final readonly class CopiedComponents
{
    /**
     * @param array<string, Recipe> $toCopy
     * @param list<array{0: string, 1: float}> $components
     */
    public function __construct(
        public array $toCopy,
        public array $components,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }
}
