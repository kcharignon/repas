<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Ce qu'UN compte a lui-même observé d'un produit : le libellé tel qu'il l'a vu. Il vit sur la ligne du produit dans la préférence du
 * compte (couple préférence, produit, même mécanisme que la contenance, `PackSize`) : propre au compte, effacé avec la ligne, donc avec
 * le compte. La fiche commune du produit (`Product`) ne sert que de valeur de repli, pour une ligne qui n'a pas encore de libellé à elle.
 *
 * Le prix n'en fait plus partie (DRV-770, amendement de l'ADR 0032) : un prix au drive n'a rien de personnel, il vit sur la fiche
 * commune seule (`Product::seePrice()`), mise à jour par tout compte qui accepte le produit.
 */
final readonly class ProductObservation
{
    private function __construct(
        public ?string $label,
    ) {
    }

    /**
     * Le libellé qu'un compte vient de lire.
     *
     * @throws DriveException `invalidChoice()` si le libellé est vide, trop long ou porte un caractère refusé (mêmes règles que celui
     *                        de la fiche commune)
     */
    public static function seen(string $label): self
    {
        return new self(Product::validLabel($label));
    }

    /**
     * Reconstruction depuis la persistance : aucun contrôle (le libellé a été contrôlé à l'écriture).
     */
    public static function load(?string $label): self
    {
        return new self($label);
    }

    public function isEmpty(): bool
    {
        return $this->label === null;
    }
}
