<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\SpokenRecipeResolution;

/**
 * Port par lequel le contexte `Voice` trouve, parmi les recettes de l'utilisateur, celle que désigne une phrase prononcée (ADR 0029 décision 13,
 * VOX-305), lui propose les plus proches quand il n'y en a pas de sûre et retient son choix pour la suite. Défini ici, implémenté dans
 * `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`). Jumeau de `SpokenIngredientResolver`, mais SÉPARÉ : une recherche
 * de recette ne regarde jamais les produits, et inversement.
 */
interface SpokenRecipeResolver
{
    /**
     * Charge les recettes de l'utilisateur UNE fois, puis : la mémoire de phrase passe avant tout classement ; sinon le classeur choisit s'il est
     * sûr ou probable ; sinon — rien d'assez proche, ou plusieurs noms qui se touchent, on ne devine pas une recette — les trois plus proches sont
     * proposées. Jamais la recette d'un autre utilisateur.
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
