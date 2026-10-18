<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Valeurs factices uniquement : jamais un vrai numéro de magasin ni une vraie ville.
 */
class DriveSettingsBuilder implements Builder
{
    private string $userId;
    private Retailer $retailer;
    private Store $store;
    private DateTimeImmutable $updatedAt;

    private function initialize(): void
    {
        $this->userId ??= UuidGenerator::new();
        $this->retailer ??= Retailer::LECLERC;
        $this->store ??= Store::create(Retailer::LECLERC, '123456', 'Ville-Test', 'https://exemple.leclercdrive.fr/magasin-123456-123456');
        $this->updatedAt ??= new DateTimeImmutable('2026-10-18 10:00:00');
    }

    public function withUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function withStore(string $id, ?string $label, string $baseUrl): self
    {
        $this->store = Store::create(Retailer::LECLERC, $id, $label, $baseUrl);
        return $this;
    }

    public function withUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function build(): DriveSettings
    {
        $this->initialize();
        return DriveSettings::load([
            'user_id' => $this->userId,
            'retailer' => $this->retailer,
            'store' => $this->store,
            'updated_at' => $this->updatedAt,
        ]);
    }
}
