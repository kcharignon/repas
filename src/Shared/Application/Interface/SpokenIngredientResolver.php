<?php

namespace Repas\Shared\Application\Interface;

use Repas\Shared\Application\Dto\IngredientSuggestion;
use Repas\Shared\Application\Dto\ResolvedIngredient;

/**
 * Port par lequel le contexte `Voice` trouve, parmi les ingrédients de l'utilisateur, celui que désigne une phrase prononcée (ADR 0029
 * décision 3, VOX-102), lui propose les plus proches quand il n'y en a pas de sûr (décision 11, VOX-303) et retient son choix pour la
 * suite. Défini ici, implémenté dans `Repas\Infrastructure\Voice` (ADR 0013 règle 8 : `Voice` n'importe pas `Repas`).
 *
 * Le choix est le plus probable, pas le seul possible : la confiance dit comment l'annoncer, et l'erreur se répare dans l'application.
 */
interface SpokenIngredientResolver
{
    /**
     * La mémoire de phrase passe avant tout classement : si l'utilisateur a déjà choisi un ingrédient pour cette phrase, c'est lui.
     *
     * @param string $phrase la phrase reconnue (« des tomates », « du pain de mie »), telle quelle
     *
     * @return ?ResolvedIngredient un ingrédient de l'utilisateur, jamais celui d'un autre ; `null` si rien ne ressemble à la phrase
     *                             (ou si le compte n'a aucun ingrédient)
     */
    public function resolve(string $userId, string $phrase): ?ResolvedIngredient;

    /**
     * Les ingrédients de l'utilisateur les plus proches de la phrase quand `resolve()` n'en choisit aucun, du plus au moins proche, trois
     * au plus. Liste vide : rien d'assez proche (ou aucun ingrédient).
     *
     * @return list<IngredientSuggestion>
     */
    public function suggest(string $userId, string $phrase): array;

    /**
     * Retient que cette phrase désigne cet ingrédient : la prochaine fois, `resolve()` le choisit tout de suite. N'écrit rien si la phrase
     * n'a aucun mot qui compte.
     *
     * @param string $ingredientSlug un ingrédient de l'utilisateur, ou un ancien slug que ses alias traduisent
     *
     * @throws \Repas\Shared\Domain\Exception\DomainException l'ingrédient n'existe pas ou est à un autre utilisateur (rien n'est écrit)
     */
    public function remember(string $userId, string $phrase, string $ingredientSlug): void;
}
