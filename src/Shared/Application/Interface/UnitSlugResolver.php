<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port qui dit à `Drive` quelles unités existent dans Repas, au moment où il enregistre la contenance d'un produit « dans l'unité de
 * la liste » (DRV-652) : une contenance ne se mémorise que dans une unité que la liste peut réellement employer. Défini ici,
 * implémenté dans `Repas\Infrastructure\Loader` (ADR 0013 règle 8 : `Drive` n'importe pas `Repas`).
 *
 * Il ne s'appelle volontairement pas `…Loader`, pour la même raison que `IngredientDepartmentResolver` : un `CommandHandler` n'appelle
 * pas de `Loader` (Deptrac `no-loader-in-commandhandler`), et `SaveProductChoicesHandler` en a besoin pendant une écriture. Il ne
 * renvoie que des slugs, jamais un agrégat.
 */
interface UnitSlugResolver
{
    /**
     * Une seule requête quel que soit le nombre de slugs.
     *
     * @param list<string> $slugs
     *
     * @return list<string> ceux de ces slugs qui désignent une unité ; un slug inconnu est absent du résultat
     */
    public function existingSlugs(array $slugs): array;
}
