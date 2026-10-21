<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\ListDuplicateIngredients\DuplicateIngredientDTO;

interface IngredientDuplicateLoader
{
    /**
     * Les paires d'ingrédients de cet utilisateur (les siens, jamais ceux d'un autre) que l'application propose de
     * fusionner : du même rayon, qui se ressemblent (`DuplicateIngredientFinder`), que l'utilisateur n'a pas écartées par « Pas le même ».
     * Triées de la plus probable à la moins probable (`DuplicateIngredientFinder::LIMIT` au plus), le même résultat à chaque appel. Un
     * nombre FIXE de lectures, quel que soit le nombre d'ingrédients. Vide pour qui n'a aucun ingrédient personnel (un administrateur).
     *
     * @return list<DuplicateIngredientDTO>
     */
    public function duplicates(string $userId): array;
}
