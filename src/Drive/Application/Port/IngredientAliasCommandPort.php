<?php

namespace Repas\Drive\Application\Port;

/**
 * Ce que les écritures du drive demandent aux ingrédients de l'utilisateur : à quel ingrédient à lui répond un slug reçu (ADR 0028 décision 4,
 * ING-002) ? Un ancien slug (d'un ancien commun dont l'utilisateur a reçu une copie, ou d'un ingrédient absorbé par une fusion), resté dans un
 * brouillon d'envoi sur le téléphone ou un onglet ouvert, ne doit jamais faire créer une préférence sous cet ancien slug. Il ne renvoie que des
 * identifiants. Adapté depuis le contexte `Repas` (ADR 0035).
 *
 * Il ne fait que RÉSOUDRE les alias qui existent (depuis l'amendement du 2026-10-20 il n'y a plus d'adoption à la volée) : un slug sans alias
 * valide est gardé tel quel.
 */
interface IngredientAliasCommandPort
{
    /**
     * Une seule requête quel que soit le nombre de slugs.
     *
     * @param list<string> $slugs
     *
     * @return array<string, string> le slug de l'ingrédient de l'utilisateur par slug reçu, pour ceux seulement qui ont un alias valide
     *                               (l'ingrédient désigné existe et lui appartient) ; l'appelant garde les autres tels quels
     */
    public function resolve(string $userId, array $slugs): array;

    /**
     * Pour une écriture qui pose quelque chose SUR un ingrédient (un choix de produit) : à quel ingrédient de l'utilisateur répond chaque slug
     * reçu ? Lui-même quand l'ingrédient est à l'utilisateur, l'ingrédient qu'un alias valide désigne sinon (l'alias l'emporte, comme dans
     * `resolve()`). Une seule requête quel que soit le nombre de slugs : le contrôle ne coûte rien de plus qu'une traduction.
     *
     * @param list<string> $slugs
     *
     * @return array<string, string> le slug de l'ingrédient de l'utilisateur par slug reçu ; un slug qui ne répond à aucun de ses ingrédients
     *                               (inconnu, ou à un autre compte) est ABSENT : l'appelant refuse alors l'écriture, sans rien écrire
     */
    public function resolveOwned(string $userId, array $slugs): array;
}
