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

    #[ORM\Column(name: 'created_at')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?bool $locked = null;

    /**
     * @var Collection<int, Meal>
     */
    #[ORM\OneToMany(targetEntity: Meal::class, mappedBy: 'shoppingList', orphanRemoval: true)]
    private Collection $meals;

    public function __construct(
        string            $id,
        User              $owner,
        DateTimeImmutable $createdAt,
        bool              $locked,
        array             $recipesInShoppingList,
    ) {
        $this->id = $id;
        $this->owner = $owner;
        $this->createdAt = $createdAt;
        $this->locked = $locked;
        $this->meals = new ArrayCollection($recipesInShoppingList);
    }

    public function getModel(): ShoppingListModel
    {
        return ShoppingListModel::load([
            'id' => $this->id,
            'owner' => $this->owner->getModel(),
            'created_at' => $this->createdAt,
            'locked' => $this->locked,
            'meals' => $this->meals->map(fn(Meal $item) => $item->getModel())->toArray(),
        ]);
    }

    public static function fromModel(ShoppingListModel $shoppingListModel): static
    {
        $datas = $shoppingListModel->toArray();
        $datas['recipes_in_shoppings_list'] = $datas['meals'];
        return self::fromData($datas);
    }

    private static function fromData(array $datas): static
    {
        return new self(
            id: $datas['id'],
            owner: User::fromData($datas['owner']),
            createdAt: DateTimeImmutable::createFromFormat(DATE_ATOM, $datas['created_at']),
            locked: $datas['locked'],
            recipesInShoppingList: $datas['recipes_in_shoppings_list'],
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

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
     * @return Collection<int, Meal>
     */
    public function getMeals(): Collection
    {
        return $this->meals;
    }

    public function addRecipe(Meal $recipe): static
    {
        if (!$this->meals->contains($recipe)) {
            $this->meals->add($recipe);
        }

        return $this;
    }

    public function removeRecipe(Meal $recipe): static
    {
        $this->meals->removeElement($recipe);

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
