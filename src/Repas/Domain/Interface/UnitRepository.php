<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

interface UnitRepository
{
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
