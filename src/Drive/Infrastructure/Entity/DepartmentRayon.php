<?php

namespace Repas\Drive\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_department_rayon` (DRV-642) : combien de fois un produit du rayon `rayonId` de l'enseigne a été choisi pour un
 * ingrédient du rayon de Repas `departmentSlug`. Lu par le loader du plan (SQL) et utilisé pour créer le schéma ; les écritures
 * passent par `DepartmentRayonPostgreSQLRepository` (une requête). Jamais hydratée ni écrite par le code.
 *
 * **Rien à purger avec un compte** : la table n'a aucune colonne qui désigne un utilisateur (ni `user`, ni `owner`, ni magasin),
 * seulement un compteur par `(enseigne, rayon de Repas, rayon de l'enseigne)`. Ce sont des faits de catalogue agrégés, comme
 * `drive_product` : on n'y retrouve personne, `UserOwnedDataCoverageTest` n'a donc rien à y lister.
 */
#[ORM\Entity]
#[ORM\Table(name: 'drive_department_rayon')]
class DepartmentRayon
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 20)]
    public string $retailer;

    #[ORM\Id]
    #[ORM\Column(name: 'department_slug', type: Types::STRING, length: 255)]
    public string $departmentSlug;

    #[ORM\Id]
    #[ORM\Column(name: 'rayon_id', type: Types::INTEGER)]
    public int $rayonId;

    #[ORM\Column(name: 'chosen_count', type: Types::INTEGER)]
    public int $chosenCount;
}
