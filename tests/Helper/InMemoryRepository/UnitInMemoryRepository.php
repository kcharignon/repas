<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

class UnitInMemoryRepository extends AbstractInMemoryRepository implements UnitRepository
{
    protected static function getClassName(): string
    {
        return Unit::class;
    }

    public function save(Unit $unit): void
    {
        $this->models[$unit->getId()] = $unit;
    }

    public function findAll(): Tab
    {
        return $this->models;
    }

    public function findOneBySlug(string $slug): Unit
    {
        return $this->models[$slug] ?? throw UnitException::notFound($slug);
    }

    public function findBySlugs(Tab $slugs): Tab
    {
        return $this->models->filter(fn(Unit $unit) => in_array($unit->getSlug(), $slugs->toArray()));
    }

    public function delete(Unit $unit): void
    {
        unset($this->models[$unit->getId()]);
    }
}
