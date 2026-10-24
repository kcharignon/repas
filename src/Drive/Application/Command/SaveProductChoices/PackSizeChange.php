<?php

namespace Repas\Drive\Application\Command\SaveProductChoices;


use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\PackSize;

/**
 * Ce qu'une intention `ADD_PRODUCT` demande de la contenance du produit (DRV-652) : la remplacer par une réponse, ou l'effacer. Ne
 * pas en parler du tout, c'est garder celle qui est déjà mémorisée POUR LE MÊME PRODUIT : `ProductInput::$packSize` vaut alors
 * `null`, pas un changement.
 */
final readonly class PackSizeChange
{
    private function __construct(
        public ?PackSize $packSize,
    ) {
    }

    public static function set(PackSize $packSize): self
    {
        return new self($packSize);
    }

    public static function clear(): self
    {
        return new self(null);
    }

    /**
     * À appliquer une fois le produit accepté par la préférence (`addPrimary()` ou `addFallback()`).
     */
    public function applyTo(Choice $choice, string $productId): void
    {
        $this->packSize === null ? $choice->forgetPackSize($productId) : $choice->rememberPackSize($productId, $this->packSize);
    }
}
