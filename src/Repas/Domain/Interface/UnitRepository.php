<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Model\Unit;

interface UnitRepository
{
    public function save(Unit $unit): void;

    /**
     * @throws UnitException
     */
    public function getOneBySlug(string $slug): Unit;

    public function delete(Unit $unit): void;
}
