<?php

/**
 * Budgets SQL des use cases d'écriture (voir WriteBudgetTest). Ne fait que diminuer.
 *
 * @return array<string, array{on: int, off: int}>
 */
return [
    'ajouter-ingredient-liste' => ['on' => 32, 'off' => 54],
    'assurer-liste-active' => ['on' => 6, 'off' => 9],
    'copier-recette' => ['on' => 25, 'off' => 33],
    'creer-conversion' => ['on' => 4, 'off' => 4],
    'creer-ingredient' => ['on' => 8, 'off' => 8],
    'creer-liste' => ['on' => 5, 'off' => 8],
    'creer-rayon' => ['on' => 2, 'off' => 2],
    'creer-recette' => ['on' => 29, 'off' => 32],
    'creer-type-recette' => ['on' => 2, 'off' => 2],
    'creer-unite' => ['on' => 2, 'off' => 2],
    'decocher-ligne' => ['on' => 26, 'off' => 47],
    'inscription' => ['on' => 2, 'off' => 2],
    'mettre-liste-en-pause' => ['on' => 28, 'off' => 49],
    'modifier-conversion' => ['on' => 4, 'off' => 4],
    'modifier-ingredient' => ['on' => 8, 'off' => 9],
    'modifier-portions-repas' => ['on' => 35, 'off' => 70],
    'modifier-rayon' => ['on' => 2, 'off' => 2],
    'modifier-recette' => ['on' => 23, 'off' => 36],
    'modifier-type-recette' => ['on' => 2, 'off' => 2],
    'modifier-unite' => ['on' => 2, 'off' => 2],
    'planifier-repas' => ['on' => 39, 'off' => 63],
    'reactiver-liste' => ['on' => 29, 'off' => 50],
    'reglages-utilisateur' => ['on' => 2, 'off' => 2],
    'renommer-liste' => ['on' => 28, 'off' => 49],
    'retirer-ingredient-liste' => ['on' => 28, 'off' => 51],
    'retirer-repas' => ['on' => 30, 'off' => 65],
    'supprimer-ingredient' => ['on' => 12, 'off' => 13],
    'supprimer-liste' => ['on' => 28, 'off' => 49],
    'supprimer-recette' => ['on' => 14, 'off' => 22],
    'terminer-liste' => ['on' => 29, 'off' => 50],
    'tick-derniere-ligne' => ['on' => 32, 'off' => 53],
    'tick-ligne' => ['on' => 3, 'off' => 3],
];
