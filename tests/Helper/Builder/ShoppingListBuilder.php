<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Repas\Domain\Model\Meal;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\ShoppingList;
use Repas\Repas\Domain\Model\ShoppingListIngredient;
use Repas\Repas\Domain\Model\ShoppingListRow;
use Repas\Repas\Domain\Model\ShoppingListStatus;
use Repas\Shared\Domain\Interface\UuidGenerator as UuidGeneratorInterface;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class ShoppingListBuilder implements Builder
{
    /**
     * Date fixe par défaut : deux listes construites à une seconde d'écart ne doivent pas différer par leur date (un test compare une liste attendue à une liste construite plus tôt).
     */
    public const string DEFAULT_DATE = '2026-01-15T10:00:00+00:00';

    private ?string $id = null;
    private UserBuilder|User|null $owner = null;
    private ?DateTimeImmutable $createdAt = null;
    private ?ShoppingListStatus $status = null;
    /** @var Tab<Recipe>|null  */
    private ?Tab $recipes = null;
    /** @var Tab<Ingredient>|null  */
    private ?Tab $ingredients = null;
    private UuidGeneratorInterface $uuidGenerator;
    private ?string $name = null;
    private int $version = 1;
    private ?DateTimeImmutable $sentAt = null;
    private ?string $listTypeId = null;

    public function __construct(?UuidGeneratorInterface $uuidGenerator = null)
    {
        $this->uuidGenerator = $uuidGenerator ?? new UuidGenerator();
    }


    public function build(): ShoppingList
    {
        $this->initialize();
        $owner = $this->owner instanceof User ? $this->owner : $this->owner->build();
        $shoppingList = ShoppingList::load([
            'id' => $this->id,
            'owner_id' => $owner->getId(),
            'created_at' => $this->createdAt,
            'name' => $this->name,
            'status' => ShoppingListStatus::ACTIVE, // On met au status ACTIVE pour pouvoir ajouter les recettes
            'meals' => Tab::newEmptyTyped(Meal::class),
            'ingredients' => Tab::newEmptyTyped(ShoppingListIngredient::class),
            'rows' => Tab::newEmptyTyped(ShoppingListRow::class),
            'version' => $this->version,
            'sent_at' => $this->status === ShoppingListStatus::SENT ? ($this->sentAt ?? new DateTimeImmutable(self::DEFAULT_DATE)) : null,
            'list_type_id' => $this->listTypeId,
        ]);

        // On ajoute les repas(recette)
        foreach ($this->recipes as $recipe) {
            $recipe = $recipe instanceof Recipe ? $recipe : $recipe->build();
            $shoppingList->addMeal($this->uuidGenerator->generate(), $recipe, $owner->getDefaultServing());
        }

        foreach ($this->ingredients as $ingredient) {
            $purchaseUnitSlug = $ingredient->getDefaultPurchaseUnit()->getSlug();
            $shoppingList->addIngredient($ingredient->getSlug(), $purchaseUnitSlug);
            $shoppingList->addRow($ingredient->getSlug(), $purchaseUnitSlug, 1);
        }

        // On passe au status demandé en dernier : une liste envoyée n'est plus modifiable
        if ($this->status !== ShoppingListStatus::ACTIVE) {
            $shoppingList->setStatus($this->status);
        }

        return $shoppingList;
    }

    public function withVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function withOwner(UserBuilder|User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function addRecipe(RecipeBuilder|Recipe $recipe): self
    {
        if ($recipe instanceof RecipeBuilder) {
            $recipe = $recipe->build();
        }

        $this->recipes ??= Tab::newEmptyTyped(Recipe::class);
        $this->recipes[] = $recipe;
        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->owner ??= new UserBuilder();
        $this->createdAt ??= new DateTimeImmutable(self::DEFAULT_DATE);
        $this->status ??= ShoppingListStatus::ACTIVE;
        $this->name ??= null;
        $this->recipes ??= Tab::newEmptyTyped(Recipe::class);
        $this->ingredients ??= Tab::newEmptyTyped(Ingredient::class);
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function addIngredient(Ingredient|IngredientBuilder $ingredient): self
    {
        $this->ingredients ??= Tab::newEmptyTyped(Ingredient::class);
        $this->ingredients[] = $ingredient instanceof IngredientBuilder ? $ingredient->build() : $ingredient;

        return $this;
    }

    public function withStatus(ShoppingListStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function withSentAt(DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function withName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** Le type de la liste (ADR 0036) : `null` par défaut, la liste principale. */
    public function withListTypeId(?string $listTypeId): self
    {
        $this->listTypeId = $listTypeId;
        return $this;
    }

    public function withCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
