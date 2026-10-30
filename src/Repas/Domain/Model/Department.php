<?php

namespace Repas\Repas\Domain\Model;


use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\StringTool;

final class Department implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $slug,
        private string $name,
        private string $image,
        private ?DepartmentIcon $icon = null,
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getId(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    public function update(string $name, string $image): void
    {
        $this->name = $name;
        $this->image = $image;
    }

    /**
     * L'icône du rayon (ING-016 B), `null` quand il n'en a pas. Distinguer deux ingrédients de même nom dans deux rayons est son seul rôle.
     */
    public function getIcon(): ?DepartmentIcon
    {
        return $this->icon;
    }

    /**
     * Règle l'icône du rayon ; `null` l'efface. Distinct de `update()` : une modification du nom ou de l'image ne touche jamais l'icône.
     */
    public function changeIcon(?DepartmentIcon $icon): void
    {
        $this->icon = $icon;
    }

    public static function load(array $datas): self
    {
        return new self(
            slug: $datas['slug'],
            name: $datas['name'],
            image: $datas['image'],
            // Une valeur inconnue du code (écrite par une version plus récente) se lit comme « pas d'icône » : `load()` ne juge rien
            icon: DepartmentIcon::tryFrom((string) ($datas['icon'] ?? '')),
        );
    }

    public static function create(string $name, string $image, ?DepartmentIcon $icon = null): self
    {
        return new self(
            StringTool::slugify($name),
            $name,
            $image,
            $icon,
        );
    }
}
