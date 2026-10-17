<?php

namespace Repas\Repas\Domain\Model;


use DateTimeImmutable;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Exception\ShoppingListException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Repas\Domain\Model\ShoppingListStatus as Status;
use Repas\Repas\Domain\Model\ShoppingListRow as Row;

final class ShoppingList implements ModelInterface
{
    use ModelTrait;

    /**
     * @param Tab<Meal> $meals
     * @param Tab<ShoppingListIngredient> $ingredients
     * @param Tab<Row> $rows
     */
    private function __construct(
        private string            $id,
        private string            $ownerId,
        private DateTimeImmutable $createdAt,
        private Status            $status,
        private ?string           $name,
        private Tab               $meals,
        private Tab               $ingredients,
        private Tab               $rows,
        private int               $version = 1,
        private ?DateTimeImmutable $sentAt = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Version de la liste (ETag, ADR 0016) : augmente à chaque enregistrement de la liste ou de ses repas, ingrédients et
     * quantités ; cocher une ligne n'y touche pas.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Refuse la modification si le client a lu une autre version que celle d'aujourd'hui (`If-Match`). Sans version
     * attendue, la modification passe : le contrôle est facultatif.
     *
     * @throws ShoppingListException
     */
    public function assertVersion(?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $this->version) {
            throw ShoppingListException::outdated($this->id);
        }
    }

    /**
     * Identifiant du propriétaire de la liste (ADR 0013 règle 8 : un agrégat référence un utilisateur par son identifiant).
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Tab<Meal>
     */
    public function getMeals(): Tab
    {
        return $this->meals;
    }

    /**
     * @return Tab<ShoppingListIngredient>
     */
    public function getIngredients(): Tab
    {
        return $this->ingredients;
    }

    public function getRows(): Tab
    {
        return $this->rows->usort(fn(Row $a, Row $b) => $a->getIngredientSlug() <=> $b->getIngredientSlug());
    }

    public static function create(
        string            $id,
        string            $ownerId,
        DateTimeImmutable $createdAt,
    ): ShoppingList {
        return new ShoppingList(
            id: $id,
            ownerId: $ownerId,
            createdAt: $createdAt,
            status: Status::ACTIVE,
            name: null,
            meals: Tab::newEmptyTyped(Meal::class),
            ingredients: Tab::newEmptyTyped(ShoppingListIngredient::class),
            rows: Tab::newEmptyTyped(Row::class),
        );
    }

    public static function load(array $datas): self
    {
        return new self(
            id: $datas['id'],
            ownerId: $datas['owner_id'],
            createdAt: $datas['created_at'],
            status: $datas['status'],
            name: $datas['name'],
            meals: $datas['meals'],
            ingredients: $datas['ingredients'],
            rows: $datas['rows'],
            version: $datas['version'] ?? 1,
            sentAt: $datas['sent_at'] ?? null,
        );
    }

    public function hasRecipe(Recipe $recipe): bool
    {
        return $this->meals->find(fn(Meal $meal) => $meal->hasRecipe($recipe)) !== null;
    }

    public function isActive(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === Status::PAUSED;
    }

    public function isCompleted(): bool
    {
        return $this->status === Status::COMPLETED;
    }

    public function isSent(): bool
    {
        return $this->status === Status::SENT;
    }

    /**
     * Date d'envoi au drive ; null tant que la liste n'est pas `SENT`.
     */
    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    /**
     * Une liste envoyée au drive n'est plus modifiable (ADR 0023) : seuls la lecture, `completed()`, `reopen()` et la
     * suppression restent permis. Appelée par chaque mutateur de l'agrégat et, pour ce qui ne passe pas par lui, par les
     * handlers (repas par identifiant).
     *
     * @throws ShoppingListException
     */
    public function assertEditable(): void
    {
        if ($this->isSent()) {
            throw ShoppingListException::sent($this->id);
        }
    }

    /**
     * Envoie la liste au drive : elle devient non modifiable. Sans effet si elle est déjà envoyée.
     *
     * @throws ShoppingListException la liste n'est ni active ni déjà envoyée (409), ou elle est vide (422)
     */
    public function send(DateTimeImmutable $now): void
    {
        if ($this->isSent()) {
            return;
        }

        if ($this->status !== Status::ACTIVE) {
            throw ShoppingListException::notSendable($this->id);
        }

        if ($this->countRows() === 0) {
            throw ShoppingListException::empty($this->id);
        }

        $this->status = Status::SENT;
        $this->sentAt = $now;
    }

    /**
     * Rouvre une liste envoyée : elle redevient active et modifiable. Le use case met en pause l'éventuelle autre liste active.
     *
     * @throws ShoppingListException la liste n'est pas envoyée
     */
    public function reopen(): void
    {
        if (!$this->isSent()) {
            throw ShoppingListException::notSent($this->id);
        }

        $this->status = Status::ACTIVE;
        $this->sentAt = null;
    }

    /**
     * @param int $servings nombre de personnes du repas : celui du propriétaire (`AuthenticatedUser::getDefaultServing()`),
     *                       que le use case transmet puisque la liste ne connaît pas l'utilisateur
     * @throws RecipeException la recette n'est pas celle du propriétaire de la liste : ses ingrédients personnalisés seraient
     *                         ceux d'un autre, la recette est traitée comme inconnue (comme l'ingrédient d'un autre)
     * @throws ShoppingListException la recette est déjà dans la liste
     */
    public function addMeal(string $id, Recipe $recipe, int $servings): void
    {
        $this->assertEditable();

        // Une liste ne planifie que les recettes de son propriétaire : leurs ingrédients sont les siens ou des ingrédients communs
        if ($recipe->getAuthorId() !== $this->ownerId) {
            throw RecipeException::notFound($recipe->getId());
        }

        // Impossible de mettre la même recette deux fois dans une liste
        if ($this->hasRecipe($recipe)) {
            throw ShoppingListException::recipeAlreadyInList($recipe->getName());
        }

        // Ajoute à la liste des recettes avec le nombre de personnes demandé
        $this->meals[] = Meal::create(
            id: $id,
            shoppingListId: $this->id,
            recipeId: $recipe->getId(),
            servings: $servings,
        );
    }

    public function addRow(string $ingredientSlug, string $purchaseUnitSlug, float $quantity): void
    {
        $this->assertEditable();

        // Cherche si la ligne avec l'ingrédient est déjà present dans la liste
        if (($row = $this->rows->find(fn(Row $row) => $row->getIngredientSlug() === $ingredientSlug)) !== null) {
            $row->addQuantity($quantity);
        } else {
            //Sinon on créer la ligne
            $this->rows[] = Row::create($this->id, $ingredientSlug, $purchaseUnitSlug, $quantity);
        }
    }

    public function subtractRow(string $ingredientSlug, float $quantity): void
    {
        $this->assertEditable();

        // Cherche si la ligne avec l'ingrédient est déjà present dans la liste
        if (($rowKey = $this->rows->findKey(fn(Row $row) => $row->getIngredientSlug() === $ingredientSlug)) !== null) {
            $this->rows[$rowKey]->subtractQuantity($quantity);
            // si la quantité passe à zero ou moins, on supprime la row
            if ($this->rows[$rowKey]->getQuantity() <= 0) {
                unset($this->rows[$rowKey]);
            }
        }
    }

    public function setStatus(?Status $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): ShoppingList
    {
        $this->assertEditable();
        $this->name = $name;
        return $this;
    }

    public function addIngredient(string $ingredientSlug, string $purchaseUnitSlug): void
    {
        $this->assertEditable();

        // Recherche de l'ingrédient dans l'unité d'achat dans la liste
        if (($shopListIngredient = $this->ingredients->find(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($ingredientSlug) && $sli->getUnitSlug() === $purchaseUnitSlug)) !== null) {
            // Si on le trouve on incrémente de 1 la quantité
            $shopListIngredient->addQuantity(1);
        } else {
            // Sinon on le créer avec une quantité de 1
            $this->ingredients[] = ShoppingListIngredient::create(
                shoppingListId: $this->id,
                ingredientSlug: $ingredientSlug,
                unitSlug: $purchaseUnitSlug,
                quantity: 1
            );
        }
    }

    public function removeIngredient(string $ingredientSlug, string $purchaseUnitSlug): void
    {
        $this->assertEditable();

        $ingredientKey = $this->ingredients->findKey(fn(ShoppingListIngredient $sli) => $sli->hasIngredient($ingredientSlug) && $sli->getUnitSlug() === $purchaseUnitSlug);

        // Si on ne retrouve pas l'ingrédient, il n'est deja plus present dans la liste
        if ($ingredientKey === null) {
            return;
        }

        // On soustrait 1 a la quantité
        $this->ingredients[$ingredientKey]->subtractQuantity(1);

        // Si la quantité est inférieur ou égal à zéro, alors on supprime l'ingrédient
        if ($this->ingredients[$ingredientKey]->getQuantity() <= 0) {
            unset($this->ingredients[$ingredientKey]);
        }
    }

    public function completed(): void
    {
        $this->status = Status::COMPLETED;
    }

    public function activated(): void
    {
        $this->assertEditable();
        $this->status = Status::ACTIVE;
    }

    public function removeMeal(Recipe $recipe): void
    {
        $this->assertEditable();

        $mealKey = $this->meals->findKey(fn(Meal $meal) => $meal->hasRecipe($recipe));

        if ($mealKey === null) {
            // Le repas n'est déjà pas/plus présent dans la liste
            return ;
        }

        // On trouve le coefficient grace au serving
        unset($this->meals[$mealKey]);
    }

    public function countRows(): int
    {
        return $this->rows->count();
    }

    public function countRecipes(): int
    {
        return $this->meals->count();
    }

    public function getIngredientQuantity(Ingredient $ingredient): ?float
    {
        return $this->rows->find(fn(Row $row) => $row->getIngredientSlug() === $ingredient->getSlug())?->getQuantity();
    }

    public function isActivate(): bool
    {
        return $this->status === Status::ACTIVE;
    }

    public function pause(): void
    {
        $this->assertEditable();
        $this->status = Status::PAUSED;
    }
}
