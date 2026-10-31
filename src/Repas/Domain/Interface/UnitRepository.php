<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

interface UnitRepository
{
    /**
     * Un unit porte ce slug (le slug dérive du nom : deux créations du même nom se télescoperaient).
     */
    public function exists(string $slug): bool;

    /**
     * Ceux de ces slugs qui désignent une unité, en une seule requête quel que soit leur nombre (aucune quand la liste est vide).
     *
     * @param list<string> $slugs
     *
     * @return list<string> un slug inconnu est absent du résultat
     */
    public function existingSlugs(array $slugs): array;

    public function save(Unit $unit): void;

    /**
     * @return Tab<Unit>
     */
    public function findAll(): Tab;

    /**
     * @throws UnitException
     */
    public function findOneBySlug(string $slug): Unit;

    /**
     * @param Tab<string> $slugs
     * @return Tab<Unit>
     */
    public function findBySlugs(Tab $slugs): Tab;

    public function delete(Unit $unit): void;
}
