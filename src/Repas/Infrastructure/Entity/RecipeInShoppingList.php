<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repository\RecipeInShoppingListRepository;

#[ORM\Entity(repositoryClass: RecipeInShoppingListRepository::class)]
#[ORM\Table(name: 'recipe_in_shopping_list')]
class RecipeInShoppingList
{
    #[ORM\Id]
    #[ORM\Column(length: 255, unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(inversedBy: 'recipes')]
    #[ORM\JoinColumn(name: 'shopping_list', nullable: false)]
    private ?ShoppingList $shoppingList = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recipe', nullable: false)]
    private ?Recipe $recipe = null;

    #[ORM\Column]
    private ?int $serving = null;

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
