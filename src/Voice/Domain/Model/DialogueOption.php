<?php

namespace Repas\Voice\Domain\Model;

/**
 * Un choix que le dialogue propose ou retient : un ingrédient de l'utilisateur, un rayon ou une unité, par son slug et son nom.
 * Valeur de transport : le slug est celui du catalogue, jamais cru (les ports vérifient à qui il est).
 */
final readonly class DialogueOption
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }

    /**
     * @return array{slug: string, name: string}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'name' => $this->name];
    }
}
