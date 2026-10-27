<?php

namespace Repas\Drive\Domain\Model;


/**
 * Le vote d'un compte pour un rayon de l'enseigne (DRV-642) : pour un ingrédient du rayon de Repas `departmentSlug`, le compte a choisi
 * un produit que l'enseigne range dans son rayon `rayonId`. Un compte pèse une fois par couple (rayon de Repas, rayon de l'enseigne),
 * quel que soit le nombre de fois qu'il renvoie le même choix ; le classement des rayons proposés compte les comptes distincts.
 * L'enseigne est celle du lot qui l'a produit, le dépôt la reçoit à part.
 */
final readonly class RayonVote
{
    /** Nombre de couples (rayon de Repas, rayon de l'enseigne) qu'un compte peut avoir appris, par enseigne. */
    public const int MAX_PER_USER_AND_RETAILER = 500;

    public function __construct(
        public string $departmentSlug,
        public int $rayonId,
    ) {
    }
}
