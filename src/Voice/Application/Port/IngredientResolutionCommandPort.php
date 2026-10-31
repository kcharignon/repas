<?php

namespace Repas\Voice\Application\Port;

use Repas\Voice\Domain\Model\SpokenResolution;

/**
 * Ce que les écritures vocales demandent aux ingrédients de l'utilisateur (ADR 0029 décisions 3 et 11) : trouver, parmi les siens, celui que
 * désigne une phrase prononcée, lui proposer les plus proches quand il n'y en a pas de sûr, et retenir son choix pour la suite. Adapté depuis
 * le contexte `Repas` (ADR 0035).
 *
 * Le choix est le plus probable, pas le seul possible : la confiance dit comment l'annoncer, et l'erreur se répare dans l'application. Il
 * n'est automatique que s'il est sûr : un produit qui n'existe pas n'est pas deviné, on demande.
 */
interface IngredientResolutionCommandPort
{
    /**
     * La mémoire de phrase passe avant tout classement (si l'utilisateur a déjà choisi un ingrédient pour cette phrase, c'est lui) ; sinon
     * le classeur choisit s'il est sûr ; sinon les trois ingrédients les plus proches sont proposés. Quand le classeur hésite entre des
     * ingrédients qui se valent, il ne devine pas : `SpokenResolution::ties` les rend (deux ou trois, chacun avec son rayon) pour que
     * l'utilisateur choisisse.
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
