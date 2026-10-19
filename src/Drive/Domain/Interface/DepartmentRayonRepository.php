<?php

namespace Repas\Drive\Domain\Interface;

use Repas\Drive\Domain\Model\DepartmentRayonChoice;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Ce que Repas a appris des choix de produits (DRV-642) : pour chaque rayon de Repas, dans quels rayons de l'enseigne les
 * utilisateurs choisissent leurs produits, et combien de fois. Agrégat par enseigne seulement, sans utilisateur ni magasin :
 * des faits de catalogue, donc rien à purger avec un compte. La lecture (les rayons les plus choisis, pour le plan) est un
 * loader, pas ce port.
 */
interface DepartmentRayonRepository
{
    /**
     * Compte ces choix : une unité de plus pour chaque `(rayon de Repas, rayon de l'enseigne)`, créé au besoin. Les doublons de la
     * liste s'additionnent. Une seule requête quelle que soit la taille de la liste ; une liste vide n'en coûte aucune.
     *
     * @param list<DepartmentRayonChoice> $choices
     */
    public function recordChoices(Retailer $retailer, array $choices): void;
}
