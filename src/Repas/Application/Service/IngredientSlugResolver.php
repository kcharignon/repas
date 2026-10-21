<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Domain\Interface\IngredientAliasRepository;

/**
 * Traduit les slugs d'ingrédients REÇUS EN ÉCRITURE en ceux de l'utilisateur (ADR 0028 décision 4, réduite par l'amendement du
 * 2026-10-20). Un client peut encore envoyer un ancien slug : celui d'un ancien commun dont l'utilisateur a reçu une copie (onglet
 * ouvert, liste en cache, brouillon d'un envoi au drive sur le téléphone), ou celui d'un ingrédient absorbé par une fusion. La ligne
 * atterrit sur l'ingrédient actuel de l'utilisateur, désigné par son alias.
 *
 * Seuls les alias VALIDES répondent (l'ingrédient désigné existe et appartient à l'utilisateur). Il n'y a plus d'adoption à la volée :
 * tout compte a ses ingrédients dès son activation, personne n'a plus à copier ce qu'il utilise. Un slug sans alias, inconnu, ou à un
 * autre utilisateur n'est jamais touché : les contrôles du cas d'usage qui suivent (existence, disponibilité de l'ingrédient pour
 * l'auteur) répondent seuls.
 *
 * Coût : une requête de lecture des alias, quel que soit le nombre de slugs, aucune sans slug.
 */
readonly class IngredientSlugResolver
{
    public function __construct(
        private IngredientAliasRepository $aliasRepository,
    ) {
    }

    /**
     * @param list<string> $slugs
     * @return array<string, string> le slug à utiliser par slug reçu, pour ceux seulement qui changent ; l'appelant garde les autres
     */
    public function resolve(string $userId, array $slugs): array
    {
        return $this->aliasRepository->resolve($userId, array_values(array_unique($slugs)));
    }
}
