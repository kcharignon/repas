<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Recipe as RecipeModel;
use Repas\Repas\Domain\Model\RecipeRow as RecipeRowModel;
use Repas\Repository\RecipeRepository;
use Repas\User\Infrastructure\Entity\User;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
class Recipe
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    public ?string $id = null {
        get {
            return $this->id;
        }
    }

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?int $peopleNumber = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: RecipeType::class)]
    #[ORM\JoinColumn(name: "recipe_type", referencedColumnName: "slug", nullable: false)]
    private ?RecipeType $type = null;

    /**
     * @var Collection<int, RecipeRow>
     */
    #[ORM\OneToMany(targetEntity: RecipeRow::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rows {
        get {
            return $this->rows;
        }
    }

    public function __construct(
        ?string $id,
        ?string $name,
        ?int $peopleNumber,
        ?User $author,
        ?RecipeType $type,
        array $rows
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->peopleNumber = $peopleNumber;
        $this->author = $author;
        $this->type = $type;
        $this->rows = new ArrayCollection($rows);
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

    public function getPeopleNumber(): ?int
    {
        return $this->peopleNumber;
    }

    public function setPeopleNumber(int $peopleNumber): static
    {
        $this->peopleNumber = $peopleNumber;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getType(): ?RecipeType
    {
        return $this->type;
    }

    public function setType(?RecipeType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public static function fromModel(RecipeModel $recipe): static
    {
        return new static(
            $recipe->getId(),
            $recipe->getName(),
            $recipe->getPeopleNumber(),
            User::fromModel($recipe->getAuthor()),
            RecipeType::fromModel($recipe->getType()),
            array_map(fn(RecipeRowModel $recipeRow) => RecipeRow::fromModel($recipeRow, $recipe), $recipe->getRows())
        );
    }

    public function addRow(RecipeRow $row): static
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setRecipe($this);
        }

        return $this;
    }

    public function removeRow(RecipeRow $row): static
    {
        if ($this->rows->removeElement($row)) {
            // set the owning side to null (unless already changed)
            if ($row->getRecipe() === $this) {
                $row->setRecipe(null);
            }
        }

        return $this;
    }
}
