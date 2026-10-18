<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Retailer;

/**
 * @extends AbstractInMemoryRepository<DriveSettings>
 */
class DriveSettingsInMemoryRepository extends AbstractInMemoryRepository implements DriveSettingsRepository
{
    protected static function getClassName(): string
    {
        return DriveSettings::class;
    }

    public function findOneByUser(string $userId, Retailer $retailer): ?DriveSettings
    {
        $settings = $this->models[$userId . ':' . $retailer->value] ?? null;

        return $settings === null ? null : clone $settings;
    }

    public function save(DriveSettings $settings): void
    {
        $this->models[$settings->getId()] = clone $settings;
    }

    public function deleteByUser(string $userId): void
    {
        foreach ($this->models->toArray() as $key => $settings) {
            if ($settings->getUserId() === $userId) {
                unset($this->models[$key]);
            }
        }
    }

    /**
     * @return list<DriveSettings>
     */
    public function all(): array
    {
        return array_values($this->models->toArray());
    }
}
