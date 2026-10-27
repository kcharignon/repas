<?php

namespace Repas\Drive\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_rayon_vote` (DRV-642) : le compte `userId` a choisi un produit du rayon `rayonId` de l'enseigne pour un ingrédient du
 * rayon de Repas `departmentSlug`. Une ligne par compte et par couple (enseigne, rayon de Repas, rayon de l'enseigne) ; le plan classe les
 * rayons par nombre de comptes. Lu par le loader du plan (SQL) et utilisé pour créer le schéma ; les écritures passent par
 * `RayonVotePostgreSQLRepository` (une requête). Jamais hydratée ni écrite par le code. Aucune clé étrangère : `userId` est effacé avec le
 * compte (ADR 0021).
 */
#[ORM\Entity]
#[ORM\Table(name: 'drive_rayon_vote')]
#[ORM\Index(name: 'idx_drive_rayon_vote_department', columns: ['retailer', 'department_slug', 'rayon_id'])]
class RayonVote
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    public string $userId;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 20)]
    public string $retailer;

    #[ORM\Id]
    #[ORM\Column(name: 'department_slug', type: Types::STRING, length: 255)]
    public string $departmentSlug;

    #[ORM\Id]
    #[ORM\Column(name: 'rayon_id', type: Types::INTEGER)]
    public int $rayonId;
}
