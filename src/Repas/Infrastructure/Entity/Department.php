<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Department as DepartmentModel;

#[ORM\Entity]
#[ORM\Table(name: "department")]
class Department
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 2048)]
    private ?string $image = null;

    /** Le nom de l'icône du rayon (`DepartmentIcon`), `NULL` sans icône (ING-016 B). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $icon = null;

    public function __construct(?string $slug, ?string $name, ?string $image, ?string $icon = null)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->image = $image;
        $this->icon = $icon;
    }


    public static function fromModel(DepartmentModel $departmentModel): static
    {
        return new static(
            slug: $departmentModel->getSlug(),
            name: $departmentModel->getName(),
            image: $departmentModel->getImage(),
            icon: $departmentModel->getIcon()?->value,
        );
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }
}
