<?php

namespace Repas\Voice\Domain\Model;

/**
 * Un choix que le dialogue propose ou retient : un ingrédient de l'utilisateur, un rayon ou une unité, par son slug et son nom.
 * Valeur de transport : le slug est celui du catalogue, jamais cru (les ports vérifient à qui il est).
 *
 * Quand plusieurs ingrédients de l'utilisateur se valent pour une phrase (« tomate » en fruit et légume et en surgelé), chaque proposition
 * porte aussi son rayon (`departmentSlug`, `departmentName`) : c'est ce qui les distingue à voix haute.
 */
final readonly class DialogueOption
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $departmentSlug = null,
        public ?string $departmentName = null,
    ) {
    }

    /** Cette proposition dit son rayon (elle fait partie d'ingrédients à départager). */
    public function hasDepartment(): bool
    {
        return $this->departmentSlug !== null && $this->departmentName !== null;
    }

    /**
     * @return array{slug: string, name: string, departmentSlug?: string, departmentName?: string}
     */
    public function toArray(): array
    {
        $data = ['slug' => $this->slug, 'name' => $this->name];
        if ($this->departmentSlug !== null && $this->departmentName !== null) {
            $data['departmentSlug'] = $this->departmentSlug;
            $data['departmentName'] = $this->departmentName;
        }

        return $data;
    }
}
