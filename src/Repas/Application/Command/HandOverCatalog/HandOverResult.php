<?php

namespace Repas\Repas\Application\Command\HandOverCatalog;


/**
 * Ce que la passation a fait. Des nombres et des sommes de contrôle, jamais un identifiant.
 */
final readonly class HandOverResult
{
    /**
     * @param int $handedOver les ingrédients passés au propriétaire
     * @param int $ownConversions les conversions propres qu'ils portent (rattachées par le slug : elles ne changent pas)
     * @param string $ingredientsFingerprint l'empreinte des ingrédients passés, identique avant et après (tout sauf `creator` et `is_base`)
     * @param string $ownerFingerprint l'empreinte du contenu du propriétaire (recettes, listes), identique avant et après
     */
    public function __construct(
        public HandOverOutcome $outcome,
        public int $handedOver = 0,
        public int $ownConversions = 0,
        public string $ingredientsFingerprint = '',
        public string $ownerFingerprint = '',
    ) {
    }

    public function wroteSomething(): bool
    {
        return $this->outcome === HandOverOutcome::HANDED_OVER;
    }
}
