<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\UnitException;
use Repas\Repas\Domain\Interface\UnitRepository;
use Repas\Repas\Domain\Model\Unit;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends AbstractInMemoryRepository<Unit>
 */
class UnitInMemoryRepository extends AbstractInMemoryRepository implements UnitRepository
{
    use SaveModelTrait;

    protected static function getClassName(): string
    {
        return Unit::class;
    }

    public function findAll(): Tab
    {
        return $this->models;
    }

    public function exists(string $slug): bool
    {
        return isset($this->models[$slug]);
    }

    /** @var list<list<string>> slugs reçus à chaque appel de `existingSlugs()`, dans l'ordre des appels */
    public array $existingSlugsCalls = [];

    public function existingSlugs(array $slugs): array
    {
        $this->existingSlugsCalls[] = $slugs;

        return array_values(array_filter($slugs, fn(string $slug): bool => isset($this->models[$slug])));
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
