<?php

namespace Repas\Repas\Application\Port;

/**
 * Ce que les écritures d'ingrédients demandent aux comptes : qui est le propriétaire du catalogue (ADR 0028, amendement du 2026-10-20), le
 * compte dont les ingrédients, ceux que l'on marque « de base », partent aux nouveaux comptes. C'est un seul compte, le compte protégé,
 * jamais « tout administrateur » : plusieurs propriétaires donneraient plusieurs « Tomate » à chaque nouveau compte. Adapté depuis le
 * contexte `User` (ADR 0035).
 */
interface CatalogOwnerCommandPort
{
    /**
     * L'identifiant du compte propriétaire du catalogue, ou `null` s'il n'existe pas (base vide, compte pas encore créé) : alors personne
     * ne peut poser le marqueur « de base ». Chaque appel relit : l'appelant ne le demande que si la réponse change quelque chose.
     */
    public function ownerId(): ?string;
}
