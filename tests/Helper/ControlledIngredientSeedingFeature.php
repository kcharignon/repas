<?php

namespace Repas\Tests\Helper;


use Repas\Shared\Application\Interface\IngredientSeedingFeature;

/**
 * Interrupteur de l'amorçage des ingrédients à la main : `new ControlledIngredientSeedingFeature(true)` pour allumer l'amorçage automatique
 * à l'activation dans un test (en test, la variable d'environnement le coupe, comme en production par défaut).
 */
final readonly class ControlledIngredientSeedingFeature implements IngredientSeedingFeature
{
    public function __construct(private bool $enabled = true)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
