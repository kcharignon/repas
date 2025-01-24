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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'author', nullable: false)]
    private ?User $author = null;

    #[ORM\ManyToOne(targetEntity: RecipeType::class)]
    #[ORM\JoinColumn(name: "type", referencedColumnName: "slug", nullable: false)]
    private ?RecipeType $type = null;

    /**
     * @var Collection<int, RecipeRow>
     */
    #[ORM\OneToMany(targetEntity: RecipeRow::class, mappedBy: 'recipe', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rows;

    public function __construct(
        ?string     $id,
        ?string     $name,
        ?int        $serving,
        ?User       $author,
        ?RecipeType $type,
        array       $rows
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->serving = $serving;
        $this->author = $author;
        $this->type = $type;
        $this->rows = new ArrayCollection($rows);
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
            $recipe->getServing(),
            User::fromModel($recipe->getAuthor()),
            RecipeType::fromModel($recipe->getType()),
            array_map(fn(RecipeRowModel $recipeRow) => RecipeRow::fromModel($recipeRow, $recipe), $recipe->getRows())
        );
    }

    public function getModel(): RecipeModel
    {
        return RecipeModel::load([
            'id' => $this->id,
            'name' => $this->name,
            'serving' => $this->serving,
            'author' => $this->author->getModel(),
            'type' => $this->type->getModel(),
            'rows' => array_map(fn(RecipeRow $recipeRow) => $recipeRow->getModel(), $this->rows->toArray()),
        ]);
    }

    public function getRows(): Collection
    {
        return $this->rows;
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
