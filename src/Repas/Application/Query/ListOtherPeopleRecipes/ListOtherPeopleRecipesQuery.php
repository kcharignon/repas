<?php

namespace Repas\Repas\Application\Query\ListOtherPeopleRecipes;

use Repas\Shared\Application\Interface\QueryInterface;

final readonly class ListOtherPeopleRecipesQuery implements QueryInterface
{
    /** Recettes rendues par page : sans paramètre, la première page de ce nombre, ce qui est tout pour un usage ordinaire. */
    public const int MAX_LIMIT = 200;

    /**
     * @param int $limit le nombre de recettes de la page, de 1 à `MAX_LIMIT`
     * @param int $offset le nombre de recettes sautées (dans l'ordre de la réponse)
     * @param string|null $search le texte cherché dans le nom des recettes (ADR 0019, amendement du 2026-10-28), 100 caractères au plus à la
     *                            frontière HTTP ; `null` ou vide : toutes les recettes, triées par catégorie
     * @param bool $isAdministrator l'utilisateur est administrateur : les recettes sans propriétaire lui sont toujours montrées, même copiées
     * @param string|null $typeSlug la catégorie (type de recette) à laquelle la page, le total et la recherche se restreignent ; `null` : toutes
     */
    public function __construct(
        public string $userId,
        public int $limit = self::MAX_LIMIT,
        public int $offset = 0,
        public ?string $search = null,
        public bool $isAdministrator = false,
        public ?string $typeSlug = null,
    ) {
    }
}
