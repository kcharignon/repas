<?php

namespace Repas\Tests\Helper\Builder;


use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;
use Repas\User\Domain\Model\User;

class RecipeBuilder implements Builder
{
    private ?string $id = null;
    private ?string $name = null;
    private ?int $serving = null;
    private UserBuilder|User|null $author = null;
    private ?string $authorId = null;
    private RecipeTypeBuilder|RecipeType|null $type = null;
    /** @var Tab<RecipeRowBuilder>|null  */
    private ?Tab $rows = null;
    private ?string $originalId = null;
    private int $version = 1;
    private bool $secret = false;
    private bool $favorite = false;
    private ?string $marmitonId = null;
    private ?string $sourceUrl = null;
    /** @var list<array{Recipe|RecipeBuilder, float}> les recettes utilisées (ADR 0040) et leur multiplicateur, dans l'ordre de leur position */
    private array $components = [];


    public function build(): Recipe
    {
        $this->initialize();
        $authorId = $this->authorId ?? ($this->author instanceof User ? $this->author : $this->author->build())->getId();
        $components = $this->components === [] ? null : Tab::fromArray(array_map(
            fn(array $component, int $position): RecipeComponent => RecipeComponent::load([
                'id' => UuidGenerator::new(),
                'recipe_id' => $this->id,
                'component_id' => $component[0] instanceof RecipeBuilder ? $component[0]->idOf() : $component[0]->getId(),
                'multiplier' => $component[1],
                'position' => $position,
            ]),
            $this->components,
            array_keys($this->components),
        ));
        return Recipe::load([
            'components' => $components,
            'id' => $this->id,
            'name' => $this->name,
            'serving' => $this->serving,
            'author_id' => $authorId,
            'type' => $this->type instanceof RecipeType ? $this->type : $this->type->build(),
            'rows' => $this->rows->map(fn(RecipeRowBuilder $row) => $row->withRecipeId($this->id)->build()),
            'original_id' => $this->originalId,
            'version' => $this->version,
            'secret' => $this->secret,
            'favorite' => $this->favorite,
            'marmiton_id' => $this->marmitonId,
            'source_url' => $this->sourceUrl,
        ]);
    }

    /**
     * La recette Marmiton neutre de l'identifiant `$marmitonId` (ADR 0037) : sans propriétaire (`Recipe::MARMITON_OWNER_ID`), originale, ordinaire,
     * avec son tag et son lien d'origine. Les ingrédients des lignes sont à régler par l'appelant (`IngredientBuilder::withCreatorId`).
     */
    public function asMarmitonNeutral(string $marmitonId = '22082', ?string $sourceUrl = null): self
    {
        $this->authorId = Recipe::MARMITON_OWNER_ID;
        $this->originalId = null;
        $this->secret = false;
        $this->marmitonId = $marmitonId;
        $this->sourceUrl = $sourceUrl ?? sprintf('https://www.marmiton.org/recettes/recette_tarte-au-citron_%s.aspx', $marmitonId);

        return $this;
    }

    /**
     * Le lien d'origine Marmiton d'une copie (ADR 0037 décision 10).
     */
    public function withSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;
        return $this;
    }

    public function isPastaCarbonara(): self
    {
        $this->id ??= UuidGenerator::new();
        $this->name = 'Pates carbonara';
        $this->serving = 4;
        $this->type = new RecipeTypeBuilder()->isMeal();
        $this->rows = Tab::fromArray(
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isPasta())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(500),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isEgg())
                ->withUnit(new UnitBuilder()->isUnite())
                ->withQuantity(1),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isThickCremeFraiche())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(250),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isDicedBacon())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(250),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isParmesan())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(100),
        );

        return $this;
    }

    public function isSoftBoiledEggs(): self
    {
        $this->id ??= UuidGenerator::new();
        $this->name = 'Œufs à la coque';
        $this->serving = 2;
        $this->type = new RecipeTypeBuilder()->isMeal();
        $this->rows = Tab::fromArray(
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isEgg())
                ->withUnit(new UnitBuilder()->isUnite())
                ->withQuantity(4),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isBread())
                ->withUnit(new UnitBuilder()->isUnite())
                ->withQuantity(1),
        );

        return $this;
    }

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->name ??= 'Gloubiboulga';
        $this->serving ??= 2;
        $this->author ??= new UserBuilder();
        $this->type ??= new RecipeTypeBuilder();
        $this->rows ??= Tab::newEmptyTyped(RecipeRowBuilder::class);
    }

    /**
     * La recette UTILISE une autre recette, `$multiplier` fois (ADR 0040) : les composants se posent dans l'ordre des appels. La recette utilisée
     * est une recette construite ou un builder (dont l'identifiant est alors fixé tout de suite). Rien n'est jugé ici : le builder reconstruit,
     * comme `Recipe::load`, un état qui peut être invalide pour qu'un test le provoque ; `Recipe::setComponents` est la voie qui juge.
     */
    public function withComponent(Recipe|RecipeBuilder $recipe, float $multiplier = 1.0): self
    {
        $this->components[] = [$recipe, $multiplier];
        return $this;
    }

    /**
     * L'identifiant de la recette, fixé s'il ne l'était pas.
     */
    public function idOf(): string
    {
        return $this->id ??= UuidGenerator::new();
    }

    public function withAuthor(UserBuilder|User $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function withAuthorId(string $authorId): self
    {
        $this->authorId = $authorId;
        return $this;
    }

    /**
     * Une recette secrète : vue de son auteur seul (ADR 0033).
     */
    public function asSecret(bool $secret = true): self
    {
        $this->secret = $secret;
        return $this;
    }

    /**
     * Une recette mise en favoris par son auteur (ADR 0034).
     */
    public function asFavorite(bool $favorite = true): self
    {
        $this->favorite = $favorite;
        return $this;
    }

    public function withVersion(int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        // On change l'id pour les rows déjà existantes
        $this->rows ??= Tab::newEmptyTyped(RecipeRowBuilder::class);
        $this->rows->map(fn(RecipeRowBuilder $row) => $row->withRecipeId($this->id));
        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function withOriginalId(?string $originalId): self
    {
        $this->originalId = $originalId;
        return $this;
    }

    public function withServing(int $serving): self
    {
        $this->serving = $serving;
        return $this;
    }

    public function addRow(RecipeRowBuilder $row): self
    {
        $this->rows ??= Tab::newEmptyTyped(RecipeRowBuilder::class);
        $this->rows[] = $row;
        return $this;
    }

    public function withRecipeType(RecipeType|RecipeTypeBuilder $recipeType): self
    {
        $this->type = $recipeType;
        return $this;
    }

    public function isBasqueCake(): self
    {
        $this->id ??= UuidGenerator::new();
        $this->type = new RecipeTypeBuilder()->isDessert();
        $this->serving = 6;
        $this->rows = Tab::fromArray([
            new RecipeRowBuilder()
            ->withRecipeId($this->id)
            ->withIngredient(new IngredientBuilder()->isFlour())
            ->withUnit(new UnitBuilder()->isGramme())
            ->withQuantity(250),
            new RecipeRowBuilder()
            ->withRecipeId($this->id)
            ->withIngredient(new IngredientBuilder()->isSugar())
            ->withUnit(new UnitBuilder()->isGramme())
            ->withQuantity(250),
            new RecipeRowBuilder()
                ->withRecipeId($this->id)
                ->withIngredient(new IngredientBuilder()->isButter())
                ->withUnit(new UnitBuilder()->isGramme())
                ->withQuantity(125),
            new RecipeRowBuilder()
            ->withRecipeId($this->id)
            ->withIngredient(new IngredientBuilder()->isEgg())
            ->withUnit(new UnitBuilder()->isUnite())
            ->withQuantity(2),
        ]);
        return $this;
    }
}
