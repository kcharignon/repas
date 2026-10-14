<?php

/**
 * Budgets SQL des use cases d'écriture (voir WriteBudgetTest). Ne fait que diminuer.
 *
 * @return array<string, array{on: int, off: int}>
 */
return [
    'ajouter-ingredient-liste' => ['on' => 16, 'off' => 16],
    'assurer-liste-active' => ['on' => 6, 'off' => 9],
    'copier-recette' => ['on' => 25, 'off' => 33],
    'creer-conversion' => ['on' => 4, 'off' => 4],
    'creer-ingredient' => ['on' => 8, 'off' => 8],
    'creer-liste' => ['on' => 5, 'off' => 8],
    'creer-rayon' => ['on' => 2, 'off' => 2],
    'creer-recette' => ['on' => 29, 'off' => 32],
    'creer-type-recette' => ['on' => 2, 'off' => 2],
    'creer-unite' => ['on' => 2, 'off' => 2],
    'decocher-ligne' => ['on' => 7, 'off' => 7],
    'inscription' => ['on' => 2, 'off' => 2],
    'mettre-liste-en-pause' => ['on' => 9, 'off' => 9],
    'modifier-conversion' => ['on' => 4, 'off' => 4],
    'modifier-ingredient' => ['on' => 8, 'off' => 9],
    'modifier-portions-repas' => ['on' => 26, 'off' => 35],
    'modifier-rayon' => ['on' => 2, 'off' => 2],
    'modifier-recette' => ['on' => 23, 'off' => 36],
    'modifier-type-recette' => ['on' => 2, 'off' => 2],
    'modifier-unite' => ['on' => 2, 'off' => 2],
    'planifier-repas' => ['on' => 29, 'off' => 40],
    'reactiver-liste' => ['on' => 10, 'off' => 10],
    'reglages-utilisateur' => ['on' => 2, 'off' => 2],
    'renommer-liste' => ['on' => 9, 'off' => 9],
    'retirer-ingredient-liste' => ['on' => 12, 'off' => 12],
    'retirer-repas' => ['on' => 21, 'off' => 30],
    'supprimer-ingredient' => ['on' => 12, 'off' => 13],
    'supprimer-liste' => ['on' => 9, 'off' => 9],
    'supprimer-recette' => ['on' => 14, 'off' => 22],
    'terminer-liste' => ['on' => 10, 'off' => 10],
    'tick-derniere-ligne' => ['on' => 13, 'off' => 13],
    'tick-ligne' => ['on' => 3, 'off' => 3],
];
