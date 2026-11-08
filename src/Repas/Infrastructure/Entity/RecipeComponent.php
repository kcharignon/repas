<?php

namespace Repas\Repas\Infrastructure\Entity;

use Doctrine\ORM\Mapping as ORM;
use Repas\Repas\Domain\Model\RecipeComponent as RecipeComponentModel;

/**
 * Une arête « la recette `recipe` utilise la recette `component`, `multiplier` fois » (ADR 0040 décision 4). Aucune clé étrangère (convention du
 * dépôt) : une arête dont l'une des recettes n'existe plus se lit sans erreur. Aucune colonne qui désigne un utilisateur : les arêtes d'un compte
 * partent avec ses recettes (`RecipePostgreSQLRepository::deleteByAuthor`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'recipe_component')]
// Une recette n'en utilise une autre qu'une fois ; l'index sert aussi à lire les composants d'une recette
#[ORM\UniqueConstraint(name: 'uniq_recipe_component', columns: ['recipe', 'component'])]
// « Cette recette sert-elle quelque part ? » : supprimer, rendre secrète
#[ORM\Index(name: 'idx_recipe_component_component', columns: ['component'])]
class RecipeComponent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private ?string $id = null;

    // La recette composée, celle qui en utilise une autre
    #[ORM\Column(name: 'recipe', type: 'string', length: 36, nullable: false)]
    private ?string $recipeId = null;

    // La recette utilisée
    #[ORM\Column(name: 'component', type: 'string', length: 36, nullable: false)]
    private ?string $componentId = null;

    #[ORM\Column(type: 'float', nullable: false)]
    private ?float $multiplier = null;

    // Rang du composant dans la recette : l'ordre de saisie est conservé à la lecture
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 0])]
    private int $position = 0;

    public function __construct(?string $id, ?string $recipeId, ?string $componentId, ?float $multiplier, int $position = 0)
    {
        $this->id = $id;
        $this->recipeId = $recipeId;
        $this->componentId = $componentId;
        $this->multiplier = $multiplier;
        $this->position = $position;
    }

    public static function fromModel(RecipeComponentModel $component): self
    {
        return new self(
            $component->getId(),
            $component->getRecipeId(),
            $component->getComponentId(),
            $component->getMultiplier(),
            $component->getPosition(),
        );
    }

    public function updateFromModel(RecipeComponentModel $component): void
    {
        $this->componentId = $component->getComponentId();
        $this->multiplier = $component->getMultiplier();
        $this->position = $component->getPosition();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getRecipeId(): ?string
    {
        return $this->recipeId;
    }

    public function getComponentId(): ?string
    {
        return $this->componentId;
    }

    public function getMultiplier(): ?float
    {
        return $this->multiplier;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
