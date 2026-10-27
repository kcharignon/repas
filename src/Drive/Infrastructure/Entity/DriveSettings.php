<?php

namespace Repas\Drive\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Repas\Drive\Domain\Model\DriveSettings as DriveSettingsModel;

#[ORM\Entity]
#[ORM\Table(name: 'drive_settings')]
class DriveSettings
{
    #[ORM\Id]
    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
    private string $userId;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $retailer;

    #[ORM\Column(name: 'store_id', type: Types::STRING, length: 20)]
    private string $storeId;

    #[ORM\Column(name: 'store_label', type: Types::STRING, length: 255, nullable: true)]
    private ?string $storeLabel;

    #[ORM\Column(name: 'base_url', type: Types::STRING, length: 255)]
    private string $baseUrl;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'product_writes_day', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $productWritesDay;

    #[ORM\Column(name: 'product_writes_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $productWritesCount;

    public static function fromModel(DriveSettingsModel $model): self
    {
        $entity = new self();
        $entity->userId = $model->getUserId();
        $entity->retailer = $model->getRetailer()->value;
        $entity->updateFromModel($model);
        $entity->productWritesDay = $model->getProductWritesDay();
        $entity->productWritesCount = $model->getProductWritesCount();

        return $entity;
    }

    /**
     * Reporte le magasin sur la ligne existante. Le compteur d'écritures de produits n'est PAS recopié : un modèle lu avant une autre requête
     * du même compte porterait une valeur périmée et la remettrait en arrière. Seule l'instruction atomique du dépôt
     * (`reserveProductWrites()`) le modifie, après l'insertion.
     */
    public function updateFromModel(DriveSettingsModel $model): void
    {
        $store = $model->getStore();
        $this->storeId = $store->id;
        $this->storeLabel = $store->label;
        $this->baseUrl = $store->baseUrl;
        $this->updatedAt = $model->getUpdatedAt();
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getRetailer(): string
    {
        return $this->retailer;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getStoreLabel(): ?string
    {
        return $this->storeLabel;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProductWritesDay(): ?DateTimeImmutable
    {
        return $this->productWritesDay;
    }

    public function getProductWritesCount(): int
    {
        return $this->productWritesCount;
    }
}
