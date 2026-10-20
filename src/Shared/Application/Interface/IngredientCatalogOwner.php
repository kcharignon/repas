<?php

namespace Repas\Shared\Application\Interface;


/**
 * Le propriétaire du catalogue d'ingrédients (ADR 0028, amendement du 2026-10-20) : le compte dont les ingrédients, ceux que l'on marque
 * « de base », partent aux nouveaux comptes. C'est un seul compte, le compte protégé, jamais « tout administrateur » : plusieurs
 * propriétaires donneraient plusieurs « Tomate » à chaque nouveau compte.
 *
 * Port de `Shared` : le contexte `Repas` doit savoir quel compte est le propriétaire sans importer `User`. Il est implémenté par `User`, qui
 * connaît le compte protégé. Ce n'est pas un `…Loader` : un `CommandHandler` l'appelle (règle Deptrac `no-loader-in-commandhandler`).
 */
interface IngredientCatalogOwner
{
    /**
     * L'identifiant du compte propriétaire du catalogue, ou `null` s'il n'existe pas (base vide, compte pas encore créé) : alors personne
     * ne peut poser le marqueur « de base ». Le résultat est mémorisé le temps de la requête.
     */
    public function ownerId(): ?string;
}
