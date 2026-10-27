<?php

namespace Repas\Drive\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_department_rayon` (DRV-642) : l'ancien compteur des rayons de l'enseigne, par enseigne et par rayon de Repas, sans
 * compte. Il n'est plus ni lu ni écrit : les rayons appris sont les votes par compte de `drive_rayon_vote` (`RayonVote`). La table reste
 * en place pour que la version précédente du code, qui la lit et l'écrit, fonctionne encore après un retour arrière ; une migration
 * ultérieure la supprimera. Jamais hydratée ni écrite par le code.
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
