<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\SpokenRecipeResolution;

/**
 * Ce que les écritures vocales demandent aux recettes de l'utilisateur (ADR 0029 décision 13, VOX-305) : trouver, parmi les siennes, celle que
 * désigne une phrase prononcée, lui proposer les plus proches quand il n'y en a pas de sûre, et retenir son choix pour la suite. Adapté depuis le
 * contexte `Repas` (ADR 0035). Jumeau de `IngredientResolutionCommandPort`, mais SÉPARÉ : une recherche de recette ne regarde jamais les produits,
 * et inversement.
 */
interface RecipeResolutionCommandPort
{
    /**
     * La mémoire de phrase passe avant tout classement ; sinon le classeur choisit s'il est sûr ou probable ; sinon — rien d'assez proche, ou
     * plusieurs noms qui se touchent, on ne devine pas une recette — les trois plus proches sont proposées. Jamais la recette d'un autre
     * utilisateur.
     *
     * @param string $phrase la phrase reconnue (« hachis parmentier », « le gratin »), sans le mot « repas » ni « recette »
     */
    public function resolveOrSuggest(string $userId, string $phrase): SpokenRecipeResolution;

    /**
     * Retient que cette phrase désigne cette recette : la prochaine fois, `resolveOrSuggest()` la choisit tout de suite. N'écrit rien si la phrase
     * n'a aucun mot qui compte.
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException la recette n'existe pas ou est à un autre utilisateur (rien n'est écrit)
     */
    public function remember(string $userId, string $phrase, string $recipeId): void;
}
