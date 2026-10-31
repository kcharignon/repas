<?php

namespace Repas\Repas\Infrastructure\Feature;


use Repas\Repas\Domain\Interface\IngredientSeedingFeature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit l'interrupteur dans la variable d'environnement `INGREDIENT_SEEDING_ENABLED` (booléen). Absente, vide ou invalide : coupé.
 */
final readonly class EnvIngredientSeedingFeature implements IngredientSeedingFeature
{
    public function __construct(
        #[Autowire('%env(bool:default::INGREDIENT_SEEDING_ENABLED)%')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
