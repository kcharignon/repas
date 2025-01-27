<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\Meal as MealModel;
use Repas\Repas\Domain\Model\ShoppingList as ShoppingListModel;
use Repas\Repository\MealRepository;

#[ORM\Entity(repositoryClass: MealRepository::class)]
#[ORM\Table(name: 'meal')]
class Meal
{
    #[ORM\Id]
    #[ORM\Column(length: 255, unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'meals')]
    #[ORM\JoinColumn(name: 'shopping_list', nullable: false)]
    private ?ShoppingList $shoppingList = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recipe', nullable: false)]
    private ?Recipe $recipe = null;

    #[ORM\Column]
    private ?int $serving = null;

    /**
     * @param string|null $id
     * @param ShoppingList|null $shoppingList
     * @param Recipe|null $recipe
     * @param int|null $serving
     */
    public function __construct(?string $id, ?ShoppingList $shoppingList, ?Recipe $recipe, ?int $serving)
    {
        $this->id = $id;
        $this->shoppingList = $shoppingList;
        $this->recipe = $recipe;
        $this->serving = $serving;
    }

    public static function fromData(array $datas, ?ShoppingList $shoppingList = null): static
    {
        return new self(
            id: $datas['id'],
            shoppingList: $shoppingList ?: ShoppingList::fromData($datas['shopping_list']),
            recipe: Recipe::fromData($datas['recipe']),
            serving: $datas['serving']
        );
    }

    public static function fromModel(MealModel $meal, ShoppingList $shoppingList): static
    {
        return new self(
            id: $meal->getId(),
            shoppingList: $shoppingList,
            recipe: Recipe::fromModel($meal->getRecipe()),
            serving: $meal->getServing(),
        );
    }

    public function getModel(): MealModel
    {
        return MealModel::load([
            'id' => $this->id,
            'shopping_list_id' => $this->shoppingList->getId(),
            'recipe' => $this->recipe->getModel(),
            'serving' => $this->serving
        ]);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getShoppingList(): ?ShoppingList
    {
        return $this->shoppingList;
    }

    public function setShoppingList(?ShoppingList $shoppingList): static
    {
        $this->shoppingList = $shoppingList;

        return $this;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): static
    {
        $this->recipe = $recipe;

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
}
