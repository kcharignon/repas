<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Repas\Domain\Model\Unit;

interface UnitRepository
{
    public function save(Unit $unit): void;

    public function findBySlug(string $slug): Unit;

    public function delete(Unit $unit): void;
}
