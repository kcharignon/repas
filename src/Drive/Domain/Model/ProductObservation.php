<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;
use Repas\Drive\Domain\Exception\DriveException;

/**
 * Ce qu'UN compte a lui-même observé d'un produit : le libellé tel qu'il l'a vu et le prix tel qu'il l'a lu, avec la date de la lecture.
 * Ces valeurs vivent sur la ligne du produit dans la préférence du compte (couple préférence, produit, même mécanisme que la
 * contenance, `PackSize`) : propres au compte, effacées avec la ligne, donc avec le compte. La fiche commune du produit
 * (`Product`) ne sert plus que de valeur de repli, pour une ligne qui n'a pas encore de valeur à elle.
 *
 * Le libellé et le prix sont indépendants : une ligne peut avoir l'un sans l'autre (le prix d'un produit lu sans prix reste vide, un
 * rafraîchissement de prix sur une ligne sans libellé n'en invente pas). Le prix et sa date vont ensemble. Un produit refusé n'a pas de
 * prix (il n'est jamais acheté, donc jamais estimé) : seulement un libellé.
 */
final readonly class ProductObservation
{
    private function __construct(
        public ?string $label,
        public ?int $priceCents,
        public ?DateTimeImmutable $priceSeenAt,
    ) {
    }

    /**
     * Le libellé qu'un compte vient de lire, avec le prix s'il l'a lu.
     *
     * @throws DriveException `invalidChoice()` si le libellé est vide, trop long ou porte un caractère refusé (mêmes règles que celui
     *                        de la fiche commune)
     */
    public static function seen(string $label, ?ProductPrice $price, DateTimeImmutable $now): self
    {
        return new self(Product::validLabel($label), $price?->cents, $price === null ? null : $now);
    }

    /**
     * Le prix qu'un compte vient de lire, sur une ligne dont il ne sait rien d'autre.
     */
    public static function priced(ProductPrice $price, DateTimeImmutable $now): self
    {
        return new self(null, $price->cents, $now);
    }

    /**
     * Reconstruction depuis la persistance : aucun contrôle (les valeurs ont été contrôlées à l'écriture). Un prix sans date, ou une
     * date sans prix, est une ligne à moitié écrite : le prix est ignoré (l'estimation retombe alors sur la fiche).
     */
    public static function load(?string $label, ?int $priceCents, ?DateTimeImmutable $priceSeenAt): self
    {
        $complete = $priceCents !== null && $priceSeenAt !== null;

        return new self($label, $complete ? $priceCents : null, $complete ? $priceSeenAt : null);
    }

    /**
     * Le compte revoit le produit : le libellé lu remplace l'ancien ; le prix lu remplace l'ancien, et sans prix lu l'ancien reste.
     */
    public function seenAgain(string $label, ?ProductPrice $price, DateTimeImmutable $now): self
    {
        $seen = self::seen($label, $price, $now);

        return new self($seen->label, $seen->priceCents ?? $this->priceCents, $seen->priceSeenAt ?? $this->priceSeenAt);
    }

    /**
     * Le compte relit seulement le prix : le libellé reste ce qu'il était.
     */
    public function repriced(ProductPrice $price, DateTimeImmutable $now): self
    {
        return new self($this->label, $price->cents, $now);
    }

    public function withoutPrice(): self
    {
        return new self($this->label, null, null);
    }

    public function hasPrice(): bool
    {
        return $this->priceCents !== null;
    }

    public function isEmpty(): bool
    {
        return $this->label === null && $this->priceCents === null;
    }
}
