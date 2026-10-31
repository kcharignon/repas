<?php

namespace Repas\Repas\Application\OpenHostService;

use Repas\Repas\Domain\Interface\IngredientAliasRepository;

/**
 * Open Host Service d'écriture du contexte `Repas` (ADR 0035) : à quel ingrédient d'un utilisateur répond un slug que l'appelant reçoit en
 * écriture (ADR 0028 décision 4, ING-002). Un ancien slug (d'un ancien commun dont l'utilisateur a reçu une copie, ou d'un ingrédient absorbé
 * par une fusion), resté dans un brouillon d'envoi sur le téléphone ou un onglet ouvert, ne doit jamais faire écrire sous cet ancien slug.
 * Des identifiants seulement, jamais un agrégat.
 *
 * Il ne fait que RÉSOUDRE les alias qui existent (depuis l'amendement du 2026-10-20 il n'y a plus d'adoption à la volée) : un slug sans
 * alias valide est gardé tel quel par l'appelant.
 */
final readonly class IngredientAliasCommandOHS
{
    public function __construct(
        private IngredientAliasRepository $aliases,
    ) {
    }

    /**
     * Une seule requête quel que soit le nombre de slugs.
     *
     * @param list<string> $slugs
     *
     * @return array<string, string> le slug de l'ingrédient de l'utilisateur par slug reçu, pour ceux seulement qui ont un alias valide
     *                               (l'ingrédient désigné existe et lui appartient) ; l'appelant garde les autres tels quels
     */
    public function resolve(string $userId, array $slugs): array
    {
        return $this->aliases->resolve($userId, $slugs);
    }

    /**
     * Pour une écriture qui pose quelque chose SUR un ingrédient : lui-même quand il est à l'utilisateur, l'ingrédient qu'un alias valide
     * désigne sinon (l'alias l'emporte). Une seule requête quel que soit le nombre de slugs.
     *
     * @param list<string> $slugs
     *
     * @return array<string, string> le slug de l'ingrédient de l'utilisateur par slug reçu ; un slug qui ne répond à aucun de ses ingrédients
     *                               (inconnu, ou à un autre compte) est ABSENT : l'appelant refuse alors l'écriture, sans rien écrire
     */
    public function resolveOwned(string $userId, array $slugs): array
    {
        return $this->aliases->resolveOwned($userId, $slugs);
    }
}
