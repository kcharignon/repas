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
    private ?DateTimeImmutable $productWritesDay = null;
    private int $productWritesCount = 0;

    private function initialize(): void
    {
        $this->userId ??= UuidGenerator::new();
        $this->retailer ??= Retailer::default();
        $this->store ??= Store::create($this->retailer, '123456', 'Ville-Test', $this->retailer->profile()->storeAddressExample);
        $this->updatedAt ??= new DateTimeImmutable('2026-10-18 10:00:00');
    }

    public function withUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function withRetailer(Retailer $retailer): self
    {
        $this->retailer = $retailer;
        return $this;
    }

    /**
     * Le magasin est créé chez l'enseigne du builder au moment de l'appel : appeler `withRetailer()` avant.
     */
    public function withStore(string $id, ?string $label, string $baseUrl): self
    {
        $this->retailer ??= Retailer::default();
        $this->store = Store::create($this->retailer, $id, $label, $baseUrl);
        return $this;
    }

    /**
     * Un magasin tel que la base le restitue, sans aucun contrôle (`Store::load`) : pour un identifiant que l'enseigne du builder
     * n'accepterait pas à la création, par exemple un identifiant explicite de 36 caractères (DRV-720).
     */
    public function withStoreAsRead(string $id, ?string $label, string $baseUrl): self
    {
        $this->store = Store::load(['id' => $id, 'label' => $label, 'base_url' => $baseUrl]);
        return $this;
    }

    public function withUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function withProductWrites(DateTimeImmutable $day, int $count): self
    {
        $this->productWritesDay = $day;
        $this->productWritesCount = $count;
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
            'product_writes_day' => $this->productWritesDay,
            'product_writes_count' => $this->productWritesCount,
        ]);
    }
}
