<?php

namespace Repas\Drive\Domain\Model;


/**
 * Nombre de produits à mettre au panier pour une ligne : un entier de 1 à 99, ou `null` quand il ne se calcule pas.
 */
final readonly class PackResult
{
    public function __construct(
        public ?int $packs,
        public PackStatus $status,
    ) {
    }

    public static function computed(int $packs): self
    {
        return new self($packs, PackStatus::COMPUTED);
    }

    public static function toCheck(?int $packs): self
    {
        return new self($packs, PackStatus::TO_CHECK);
    }
}
