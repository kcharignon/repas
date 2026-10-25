<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\SpokenResolution;

/**
 * Port par lequel le contexte `Voice` trouve, parmi les ingrédients de l'utilisateur, celui que désigne une phrase prononcée (ADR 0029
 * décision 3, VOX-102), lui propose les plus proches quand il n'y en a pas de sûr (décision 11, VOX-303) et retient son choix pour la suite.
 * Défini ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * Le choix est le plus probable, pas le seul possible : la confiance dit comment l'annoncer, et l'erreur se répare dans l'application. Il
 * n'est automatique que s'il est sûr : un produit qui n'existe pas n'est pas deviné, on demande.
 */
interface SpokenIngredientResolver
{
    /**
     * Charge les ingrédients de l'utilisateur UNE fois, puis : la mémoire de phrase passe avant tout classement (si l'utilisateur a déjà
     * choisi un ingrédient pour cette phrase, c'est lui) ; sinon le classeur choisit s'il est sûr ; sinon les trois ingrédients les plus
     * proches sont proposés.
     *
     * @param string $phrase la phrase reconnue (« des tomates », « du pain de mie »), telle quelle
     *
     * @return SpokenResolution un ingrédient de l'utilisateur, jamais celui d'un autre ; sinon ses propositions (aucune si rien ne
     *                          ressemble à la phrase ou si le compte n'a aucun ingrédient)
     */
    public function resolveOrSuggest(string $userId, string $phrase): SpokenResolution;

    /**
     * Retient que cette phrase désigne cet ingrédient : la prochaine fois, `resolveOrSuggest()` le choisit tout de suite. N'écrit rien si la
     * phrase n'a aucun mot qui compte.
     *
     * @param string $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur (rien n'est écrit)
     */
    public function remember(string $userId, string $phrase, string $ingredientSlug): void;
}
