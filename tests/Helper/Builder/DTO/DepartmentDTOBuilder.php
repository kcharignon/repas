<?php

namespace Repas\Tests\Helper\Builder\DTO;


use Repas\Repas\Application\Query\ListDepartments\DepartmentDTO;
use Repas\Repas\Application\Query\ListDepartments\DepartmentListItemDTO;
use Repas\Tests\Helper\Builder\Builder;

class DepartmentDTOBuilder implements Builder
{
    private string $slug;
    private string $name;
    private string $image;
    private ?string $icon = null;
    private ?string $listTypeId = null;

    private function initialize(): void
    {
        $this->slug ??= 'maxi-outils';
        $this->name ??= 'Maxi Outils';
        $this->image ??= 'file://images/maxi-outils.jpg';
    }

    public function withSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withImage(string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function withIcon(?string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function withListTypeId(?string $listTypeId): self
    {
        $this->listTypeId = $listTypeId;
        return $this;
    }

    /**
     * Le rayon tel que la liste des rayons le montre à un utilisateur (avec le type de liste auquel il l'a rattaché).
     */
    public function buildListItem(): DepartmentListItemDTO
    {
        $this->initialize();
        return new DepartmentListItemDTO($this->slug, $this->name, $this->image, $this->icon, $this->listTypeId);
    }

    public function build(): DepartmentDTO
    {
        $this->initialize();
        return new DepartmentDTO($this->slug, $this->name, $this->image, $this->icon);
    }
}
