<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\RecipeType as RecipeTypeModel;
use Repas\Repas\Infrastructure\Repository\RecipeTypePostgreSQLRepository;

#[ORM\Entity(repositoryClass: RecipeTypePostgreSQLRepository::class)]
#[ORM\Table(name: "recipe_type")]
class RecipeType
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 2048)]
    private ?string $image = null;

    #[ORM\Column]
    private ?int $sequence = null;

    public function __construct(?string $slug, ?string $name, ?string $image, ?int $sequence)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->image = $image;
        $this->sequence = $sequence;
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

    public function setImage(string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getSequence(): ?int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    public static function fromModel(RecipeTypeModel $recipeType): self
    {
        return new self(
            slug: $recipeType->getSlug(),
            name: $recipeType->getName(),
            image: $recipeType->getImage(),
            sequence: $recipeType->getOrder(),
        );
    }
}
