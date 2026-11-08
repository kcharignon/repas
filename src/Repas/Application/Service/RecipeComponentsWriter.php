<?php

namespace Repas\Repas\Application\Service;

use Repas\Repas\Application\Command\CreateRecipe\RecipeComponentSubCommand;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeComponentLock;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Service\RecipeComponentRules;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Pose les composants d'une recette qu'on écrit (ADR 0040 décisions 24 à 27), pour la création ET le remplacement : l'unique endroit qui les juge, de
 * sorte qu'un cas d'usage de plus qui reçoit un identifiant de recette utilisée ne puisse pas oublier l'auteur (`SubRecipeWritesCheckAuthorTest`).
 *
 * L'ordre des contrôles est celui de la décision 26, tous AVANT la première écriture (le modèle n'est enregistré que par l'appelant) :
 *  1. sans lecture : le nombre (`RECIPE_COMPONENT_LIMIT_REACHED`), les doublons, les multiplicateurs, la recette elle-même, la recette sans propriétaire
 *     (`Recipe::setComponents`) ;
 *  2. le verrou de l'auteur, pris AVANT toute lecture (une lecture faite plus tôt pourrait ne pas voir l'écriture d'une transaction concurrente) ;
 *  3. les recettes utilisées, lues EN LOT : inconnue, d'un autre auteur ou secrète d'un autre pour qui écrit = `404` pour TOUTES, avant le reste ;
 *  4. la recette composée n'est pas déjà utilisée par une autre (`RecipeRepository::isUsedAsComponent`, une requête) et aucune recette utilisée n'a de
 *     composants : profondeur 1 (`RECIPE_COMPONENT_CHAIN`) ;
 *  5. la règle secrète et ordinaire (`RECIPE_COMPONENT_SECRET`).
 * Lignes + composants ≥ 1 (`RECIPE_EMPTY`) est jugé par l'appelant, une fois les lignes posées.
 *
 * `[]` retire tout : sans lecture ni verrou (rien ne peut être cassé en retirant). Le multiplicateur d'une paire déjà connue garde l'identifiant de son
 * arête, pour que l'enregistrement la mette à jour au lieu de l'effacer puis de la recréer.
 */
readonly class RecipeComponentsWriter
{
    public function __construct(
        private RecipeRepository $recipeRepository,
        private RecipeComponentLock $lock,
        private RecipeComponentRules $rules,
    ) {
    }

    /**
     * @param Tab<RecipeComponentSubCommand> $components les composants demandés, dans l'ordre de leur position
     * @param string $editorId l'utilisateur qui écrit : une recette secrète d'un autre n'existe pas pour lui, administrateur compris
     * @param bool $recipeIsNew la recette vient d'être créée : aucune autre recette ne peut l'utiliser, rien à lire
     * @param bool $lockHeld le verrou de l'auteur est déjà pris par cette commande (un changement de `secret`)
     *
     * @throws RecipeException
     */
    public function apply(Recipe $recipe, Tab $components, string $editorId, bool $recipeIsNew, bool $lockHeld = false): void
    {
        $known = [];
        foreach ($recipe->getComponents() as $edge) {
            $known[$edge->getComponentId()] = $edge->getId();
        }

        $edges = Tab::newEmptyTyped(RecipeComponent::class);
        foreach ($components->values() as $position => $component) {
            // `load` ne juge rien : les bornes sont jugées dans l'ordre de la décision 26 par `Recipe::setComponents`
            $edges[] = RecipeComponent::load([
                'id' => $known[$component->recipeId] ?? UuidGenerator::new(),
                'recipe_id' => $recipe->getId(),
                'component_id' => $component->recipeId,
                'multiplier' => $component->multiplier,
                'position' => $position,
            ]);
        }
        $recipe->setComponents($edges);
        if ($edges->empty()) {
            return;
        }

        if (!$lockHeld) {
            $this->lock->acquire($recipe->getAuthorId());
        }

        $used = $this->recipeRepository->findByIds($recipe->componentIds());
        // Inconnue, d'un autre auteur ou invisible de qui écrit : `404` pour TOUTES, la première dans l'ordre reçu nommée, avant la profondeur et le secret
        $found = [];
        foreach ($recipe->componentIds() as $componentId) {
            $component = $used[$componentId] ?? throw RecipeException::notFound($componentId);
            $this->rules->assertFound($recipe, $component, $editorId);
            $found[] = $component;
        }
        $recipeIsUsed = !$recipeIsNew && $this->recipeRepository->isUsedAsComponent($recipe->getId());
        foreach ($found as $component) {
            $this->rules->assertDepth($recipe, $component, $recipeIsUsed);
        }
        foreach ($found as $component) {
            $this->rules->assertSecrecy($recipe, $component);
        }
    }
}
