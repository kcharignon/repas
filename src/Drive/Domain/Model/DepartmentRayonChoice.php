<?php

namespace Repas\Drive\Domain\Model;


/**
 * Un fait appris d'un choix de produit (DRV-642) : pour un ingrédient du rayon de Repas `departmentSlug`, l'utilisateur a choisi un
 * produit que l'enseigne range dans son rayon `rayonId`. Rien de personnel : ni qui, ni dans quel magasin. L'enseigne est celle du
 * lot qui l'a produit, le dépôt la reçoit à part.
 */
final readonly class DepartmentRayonChoice
{
    public function __construct(
        public string $departmentSlug,
        public int $rayonId,
    ) {
    }
}
