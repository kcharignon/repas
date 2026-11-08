<?php

namespace Repas\Repas\Domain\Interface;


/**
 * Le verrou d'un import Marmiton, PAR IDENTIFIANT de recette (ADR 0037 décision 14) : deux imports du même lien neuf, lancés en même temps par
 * deux comptes (ou deux fois par le même), se suivent. Le second attend la fin du premier, puis voit la recette neutre qu'il a créée (et, le
 * cas échéant, sa propre copie) au lieu d'en créer une deuxième ou de laisser des ingrédients orphelins. Deux identifiants différents ne
 * s'attendent pas.
 */
interface MarmitonImportLock
{
    /**
     * Prend le verrou de cet identifiant pour la durée de la transaction en cours (le bus des commandes est transactionnel : il est relâché au
     * `COMMIT` ou au `ROLLBACK`, jamais à la main). Bloque tant qu'une autre transaction le tient.
     */
    public function acquire(string $marmitonId): void;
}
