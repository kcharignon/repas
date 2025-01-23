<?php

namespace Repas\Repas\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;
use Repas\Repository\ShoppingListRepository;
use Repas\User\Infrastructure\Entity\User;

#[ORM\Entity(repositoryClass: ShoppingListRepository::class)]
#[ORM\Table(name: 'shopping_list')]
class ShoppingList
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private ?string $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\Column]
    private ?DateTimeImmutable $date = null;

    #[ORM\Column]
    private ?bool $locked = null;

    /**
     * @var Collection<int, RecipeInShoppingList>
     */
    #[ORM\OneToMany(targetEntity: RecipeInShoppingList::class, mappedBy: 'shoppingList', orphanRemoval: true)]
    private Collection $recipes;

    public function __construct(
        string $id,
        User $owner,
        DateTimeImmutable $date,
        bool $locked,
        array $recipes,
    ) {
        $this->id = $id;
        $this->owner = $owner;
        $this->date = $date;
        $this->locked = $locked;
        $this->recipes = new ArrayCollection($recipes);
    }

    public static function fromModel(ShoppingListModel $shoppingListModel): static
    {
        return self::fromData($shoppingListModel->toArray());
    }

    private static function fromData(array $datas): static
    {
        return new self(
            $datas['id'],
            User::fromData($datas['owner']),
            DateTimeImmutable::createFromFormat(DATE_ATOM, $datas['date']),
            $datas['locked'],
            $datas['recipes']
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function isLocked(): ?bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    /**
     * @return Collection<int, RecipeInShoppingList>
     */
    public function getRecipes(): Collection
    {
        return $this->recipes;
    }

    public function addRecipe(RecipeInShoppingList $recipe): static
    {
        if (!$this->recipes->contains($recipe)) {
            $this->recipes->add($recipe);
        }

        return $this;
    }

    public function removeRecipe(RecipeInShoppingList $recipe): static
    {
        $this->recipes->removeElement($recipe);

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
