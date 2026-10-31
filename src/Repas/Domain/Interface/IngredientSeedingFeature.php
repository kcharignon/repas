<?php

namespace Repas\Repas\Domain\Interface;


/**
 * Interrupteur de l'amorçage AUTOMATIQUE des ingrédients à l'activation d'un compte (ADR 0028, amendement du 2026-10-20, ING-008).
 *
 * Coupé par défaut (variable `INGREDIENT_SEEDING_ENABLED`, y compris quand elle est absente) : tant qu'il l'est, activer un compte ne copie
 * aucun ingrédient et ne coûte aucune requête de plus. L'allumer est une décision humaine, après un déploiement stable, par le script
 * `ecrire-ingredient-seeding.sh`. Il ne commande QUE l'amorçage automatique : la commande de rattrapage `repas:ingredients:seed` marche
 * interrupteur coupé.
 */
interface IngredientSeedingFeature
{
    public function isEnabled(): bool;
}
