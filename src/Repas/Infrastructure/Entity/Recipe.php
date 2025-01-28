<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe as RecipeModel;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
#[ORM\Table(name: 'recipe')]
class Recipe
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    public ?string $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

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
            $recipe->getId(),
            $recipe->getName(),
            $recipe->getServing(),
            $recipe->getAuthor()->getId(),
            $recipe->getType()->getSlug(),
        );
    }
}
