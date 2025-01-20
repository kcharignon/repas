<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Department as DepartmentModel;
use Repas\Repository\DepartmentRepository;

#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
class Department
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 2048)]
    private ?string $image = null;

    public function __construct(?string $slug, ?string $name, ?string $image)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->image = $image;
    }


    public static function fromModel(DepartmentModel $departmentModel): self
    {
        return self::fromData($departmentModel->toArray());
    }

    public function getModel(): DepartmentModel
    {
        return DepartmentModel::load($this->toArray());
    }

    public static function fromData(array $departmentData): self
    {
        return new self($departmentData['slug'], $departmentData['name'], $departmentData['image']);
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

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'image' => $this->image,
        ];
    }
}
