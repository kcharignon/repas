<?php

namespace Repas\Repas\Application\Loader;

use Repas\Repas\Application\Query\GetConversionHealth\ConversionHealthDTO;

interface ConversionHealthLoader
{
    /**
     * Le rapport de santé des conversions, en LECTURE SEULE (aucune écriture, aucun verrou) et en un nombre constant de requêtes, quel que soit le
     * nombre de comptes, d'ingrédients ou de recettes : les universelles de référence présentes ou manquantes, les conversions propres qui répètent
     * une paire universelle, et par compte les ingrédients et les lignes de recettes dont l'unité ne se convertit pas en l'unité d'achat.
     */
    public function report(): ConversionHealthDTO;
}
