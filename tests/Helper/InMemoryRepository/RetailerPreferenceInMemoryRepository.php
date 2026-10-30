<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\RetailerPreferenceRepository;
use Repas\Drive\Domain\Model\RetailerPreference;

/**
 * @extends AbstractInMemoryRepository<RetailerPreference>
 */
class RetailerPreferenceInMemoryRepository extends AbstractInMemoryRepository implements RetailerPreferenceRepository
{
    /** @var int nombre d'enregistrements demandés (un handler qui n'a rien à changer n'écrit pas) */
    public int $saveCount = 0;

    protected static function getClassName(): string
    {
        return RetailerPreference::class;
    }

    public function findOneByUser(string $userId): ?RetailerPreference
    {
        $preference = $this->models[$userId] ?? null;

        return $preference === null ? null : clone $preference;
    }

    public function save(RetailerPreference $preference): void
    {
        ++$this->saveCount;
        $this->models[$preference->getId()] = clone $preference;
    }

    public function deleteByUser(string $userId): void
    {
        unset($this->models[$userId]);
    }

    /**
     * @return list<RetailerPreference>
     */
    public function all(): array
    {
        return array_values($this->models->toArray());
    }
}
