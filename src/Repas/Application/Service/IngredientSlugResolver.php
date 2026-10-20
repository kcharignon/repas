<?php

namespace Repas\Repas\Application\Service;


use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientCommand;
use Repas\Repas\Application\Command\AdoptCommonIngredient\AdoptCommonIngredientHandler;
use Repas\Repas\Domain\Exception\DepartmentException;
use Repas\Repas\Domain\Exception\IngredientAliasException;
use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\IngredientAliasRepository;
use Repas\Repas\Domain\Interface\IngredientRepository;

/**
 * Traduit les slugs d'ingrédients REÇUS EN ÉCRITURE en ceux de l'utilisateur (ADR 0028 décision 4, ING-002). Un client peut encore
 * envoyer le slug d'un commun que l'utilisateur a adopté depuis (onglet ouvert, liste en cache, brouillon d'un envoi au drive sur le
 * téléphone) : une ligne ne doit jamais atterrir sur un commun masqué (`IngredientVisibility`), elle atterrit sur sa copie.
 *
 * - Un alias valide répond pour tout utilisateur : adoption d'un commun, ou (ING-005) ingrédient absorbé par une fusion.
 * - Pour un ADOPTANT (au moins un alias d'adoption), un commun reçu qui n'a pas d'alias est adopté à la volée, `first-use` : un
 *   commun ajouté par l'administrateur après la migration, ou jamais utilisé jusque-là, devient sa copie à la première utilisation.
 * - Pour tout autre utilisateur (un administrateur, quelqu'un qui n'a rien adopté), seuls les alias qui existent répondent : il garde
 *   les communs partagés, comme avant ce chantier. C'est ce qui rend ce service sans effet tant qu'aucun alias n'existe.
 *
 * Un slug inconnu, personnel ou d'un autre utilisateur n'est jamais touché : les contrôles du cas d'usage qui suivent (existence,
 * disponibilité de l'ingrédient pour l'auteur) répondent comme avant. Les administrateurs n'adoptent jamais (ils n'ont aucun alias,
 * donc ne sont pas adoptants) ; l'adoption à la première utilisation de TOUS les utilisateurs est ING-008.
 *
 * Coût : une requête de lecture des alias (avec le drapeau « adoptant »), quel que soit le nombre de slugs, et rien d'autre pour un
 * non-adoptant. Un adoptant paie en plus une requête pour reconnaître les communs parmi les slugs sans alias, puis une adoption par
 * commun nouveau.
 */
readonly class IngredientSlugResolver
{
    /** Valeur de `ingredient_alias.source` d'une adoption faite à la volée par une écriture. */
    public const string SOURCE_FIRST_USE = 'first-use';

    public function __construct(
        private IngredientAliasRepository $aliasRepository,
        private IngredientRepository $ingredientRepository,
        private AdoptCommonIngredientHandler $adoptCommonIngredient,
    ) {
    }

    /**
     * Pour une écriture qui POSE ces ingrédients chez l'utilisateur (lignes de recette, ajout à la liste de courses).
     *
     * @param list<string> $slugs
     * @return array<string, string> le slug à utiliser par slug reçu, pour ceux seulement qui changent ; l'appelant garde les autres
     * @throws IngredientException
     * @throws IngredientAliasException
     * @throws DepartmentException
     * @throws UnitException
     */
    public function resolveForUser(string $userId, array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        if ($slugs === []) {
            return [];
        }

        $lookup = $this->aliasRepository->lookup($userId, $slugs);
        $resolved = $lookup->resolved;
        if (!$lookup->adopter) {
            return $resolved;
        }

        $unresolved = array_values(array_diff($slugs, array_keys($resolved)));
        $commons = array_flip($this->ingredientRepository->findCommonSlugs($unresolved));
        // Dans l'ordre reçu : deux communs de même nom visé prennent leurs slugs dans le même ordre à chaque fois
        foreach ($unresolved as $slug) {
            if (isset($commons[$slug])) {
                $resolved[$slug] = ($this->adoptCommonIngredient)(new AdoptCommonIngredientCommand($userId, $slug, self::SOURCE_FIRST_USE));
            }
        }

        return $resolved;
    }

    /**
     * Pour une écriture qui ne fait que retrouver ce que l'utilisateur a déjà (retirer un ingrédient de la liste) : les alias qui
     * existent, sans adopter. Adopter un commun pour le retirer aussitôt d'une liste qui ne le contient pas n'aurait pas de sens.
     *
     * @param list<string> $slugs
     * @return array<string, string> le slug à utiliser par slug reçu, pour ceux seulement qui changent
     */
    public function resolveExisting(string $userId, array $slugs): array
    {
        return $this->aliasRepository->resolve($userId, $slugs);
    }
}
