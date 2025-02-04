<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Recipe as RecipeModel;
use Repas\Shared\Domain\Tool\StringTool;

#[ORM\Entity]
#[ORM\Table(name: 'recipe')]
class Recipe
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?int $serving = null;

    #[ORM\Column(name: 'author', nullable: false)]
    private ?string $authorId = null;

    #[ORM\Column(name: "type", nullable: false)]
    private ?string $typeSlug = null;

    public function __construct(
        ?string $id,
        ?string $name,
        ?int    $serving,
        ?string $authorId,
        ?string $typeSlug,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = StringTool::slugify($name);
        $this->serving = $serving;
        $this->authorId = $authorId;
        $this->typeSlug = $typeSlug;
    }

    public function getId(): ?string
    {
        return $this->id;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): Recipe
    {
        $this->slug = $slug;
        return $this;
    }

    public function getServing(): ?int
    {
        return $this->serving;
    }

    public function setServing(int $serving): static
    {
        $this->serving = $serving;

        return $this;
    }

    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    public function setAuthorId(?string $authorId): static
    {
        $this->authorId = $authorId;

        return $this;
    }

    public function getTypeSlug(): ?string
    {
        return $this->typeSlug;
    }

    public function setTypeSlug(?string $typeSlug): static
    {
        $this->typeSlug = $typeSlug;

        return $this;
    }

    public static function fromModel(RecipeModel $recipe): static
    {
        return new static(
            id: $recipe->getId(),
            name: $recipe->getName(),
            serving: $recipe->getServing(),
            authorId: $recipe->getAuthor()->getId(),
            typeSlug: $recipe->getType()->getSlug(),
        );
    }

    public function updateFromModel(RecipeModel $recipe): void
    {
        $this->setName($recipe->getName());
        $this->setServing($recipe->getServing());
        $this->setTypeSlug($recipe->getType()->getSlug());
    }
}
