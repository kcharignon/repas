<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\PricePerMeasure;
use Repas\Drive\Domain\Model\PriceUnit;
use Repas\Drive\Domain\Model\Product;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;

/**
 * Valeurs factices uniquement : jamais un vrai numéro de magasin ni un vrai produit.
 */
class ProductBuilder implements Builder
{
    private Retailer $retailer;
    private string $storeId;
    private string $retailerProductId;
    private ?string $ean;
    private string $label;
    private ?ProductContent $content;
    private bool $contentGiven = false;
    private ?ProductPrice $price;
    private ?DateTimeImmutable $priceSeenAt;
    private DateTimeImmutable $lastSeenAt;
    private ?int $rayonId = null;
    private ?int $familyId = null;

    private function initialize(): void
    {
        $this->retailer ??= Retailer::default();
        $this->storeId ??= '123456';
        $this->retailerProductId ??= '555111';
        $this->ean ??= null;
        $this->label ??= 'Farine de blé T55 Marque Repère 1 kg';
        if (!$this->contentGiven) {
            $this->content = new ProductContent(1000, ContentUnit::G);
        }
        $this->price ??= null;
        $this->priceSeenAt ??= null;
        $this->lastSeenAt ??= new DateTimeImmutable('2026-10-18 10:00:00');
    }

    public function withRetailer(Retailer $retailer): self
    {
        $this->retailer = $retailer;
        return $this;
    }

    public function withStoreId(string $storeId): self
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function withRetailerProductId(string $retailerProductId): self
    {
        $this->retailerProductId = $retailerProductId;
        return $this;
    }

    public function withEan(?string $ean): self
    {
        $this->ean = $ean;
        return $this;
    }

    public function withLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function withContent(?ProductContent $content): self
    {
        $this->content = $content;
        $this->contentGiven = true;
        return $this;
    }

    public function withPrice(int $cents, ?int $perMeasureCents = null, PriceUnit $unit = PriceUnit::KG, ?DateTimeImmutable $seenAt = null): self
    {
        $this->price = new ProductPrice($cents, $perMeasureCents === null ? null : new PricePerMeasure($perMeasureCents, $unit));
        $this->priceSeenAt = $seenAt ?? new DateTimeImmutable('2026-10-18 10:00:00');
        return $this;
    }

    public function withLastSeenAt(DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    /**
     * Rayon et famille de l'enseigne (valeurs inventées dans les tests, jamais un vrai identifiant du site).
     */
    public function withRayon(?int $rayonId, ?int $familyId = null): self
    {
        $this->rayonId = $rayonId;
        $this->familyId = $familyId;
        return $this;
    }

    public function build(): Product
    {
        $this->initialize();
        return Product::load([
            'id' => Product::idFor($this->retailer, $this->storeId, $this->retailerProductId),
            'retailer' => $this->retailer,
            'store_id' => $this->storeId,
            'retailer_product_id' => $this->retailerProductId,
            'ean' => $this->ean,
            'label' => $this->label,
            'content' => $this->content,
            'price' => $this->price,
            'price_seen_at' => $this->priceSeenAt,
            'last_seen_at' => $this->lastSeenAt,
            'rayon_id' => $this->rayonId,
            'family_id' => $this->familyId,
        ]);
    }
}
