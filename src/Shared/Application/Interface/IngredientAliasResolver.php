<?php

namespace Repas\Shared\Application\Interface;

/**
 * Port qui dit à `Drive` quel ingrédient de l'utilisateur répond à un slug qu'il reçoit en écriture (ADR 0028 décision 4, ING-002) : un
 * ancien slug de commun, resté dans un brouillon d'envoi sur le téléphone ou un onglet ouvert, ne doit jamais faire créer une
 * préférence sous un commun que l'utilisateur a adopté et qui est masqué pour lui. Défini ici, implémenté dans
 * `Repas\Infrastructure\Loader` (ADR 0013 règle 8 : `Drive` n'importe pas `Repas`).
 *
 * Il ne s'appelle volontairement pas `…Loader`, pour la même raison que `IngredientDepartmentResolver` et `UnitSlugResolver` : un
 * `CommandHandler` n'appelle pas de `Loader` (Deptrac `no-loader-in-commandhandler`), et les écritures du drive en ont besoin. Comme
 * eux, il ne renvoie que des identifiants, jamais un agrégat.
 *
 * Il ne fait que RÉSOUDRE les alias qui existent : il n'adopte rien (l'adoption à la volée est celle des écritures de Repas), et un
 * utilisateur qui n'a aucun alias, comme un administrateur, garde le slug reçu.
 */
interface IngredientAliasResolver
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
}
