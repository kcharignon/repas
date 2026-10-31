<?php

namespace Repas\Drive\Application\Port;

/**
 * Ce que l'enregistrement d'un choix de produit demande aux unités : lesquelles existent, au moment où il mémorise la contenance d'un produit
 * « dans l'unité de la liste » (DRV-652) : une contenance ne se mémorise que dans une unité que la liste peut réellement employer. Ne renvoie
 * que des slugs. Adapté depuis le contexte `Repas` (ADR 0035).
 */
interface UnitCommandPort
{
    /**
     * Une seule requête quel que soit le nombre de slugs.
     *
     * @param list<string> $slugs
     *
     * @return list<string> ceux de ces slugs qui désignent une unité ; un slug inconnu est absent du résultat
     */
    public function existingSlugs(array $slugs): array;
}
