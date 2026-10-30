<?php

namespace Repas\Drive\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_product`, lu par les loaders (DQL) et utilisé pour créer le schéma ; les écritures passent par
 * `ProductPostgreSQLRepository` (une requête pour tout un lot). Jamais hydratée ni écrite par le code : les propriétés sont
 * publiques et sans accesseur pour cette raison.
 */
#[ORM\Entity]
#[ORM\Table(name: 'drive_product')]
#[ORM\UniqueConstraint(name: 'uniq_drive_product_key', columns: ['retailer', 'store_id', 'retailer_product_id'])]
#[ORM\Index(name: 'idx_drive_product_ean', columns: ['ean'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(type: Types::STRING, length: 20)]
    public string $retailer;

    #[ORM\Column(name: 'store_id', type: Types::STRING, length: 40)]
    public string $storeId;

    #[ORM\Column(name: 'retailer_product_id', type: Types::STRING, length: 40)]
    public string $retailerProductId;

    #[ORM\Column(type: Types::STRING, length: 14, nullable: true)]
    public ?string $ean;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $label;

    #[ORM\Column(name: 'content_value', type: Types::DECIMAL, precision: 10, scale: 3, nullable: true)]
    public ?string $contentValue;

    #[ORM\Column(name: 'content_unit', type: Types::STRING, length: 8, nullable: true)]
    public ?string $contentUnit;

    #[ORM\Column(name: 'price_cents', type: Types::INTEGER, nullable: true)]
    public ?int $priceCents;

    #[ORM\Column(name: 'price_per_measure_cents', type: Types::INTEGER, nullable: true)]
    public ?int $pricePerMeasureCents;

    #[ORM\Column(name: 'price_per_measure_unit', type: Types::STRING, length: 8, nullable: true)]
    public ?string $pricePerMeasureUnit;

    #[ORM\Column(name: 'price_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $priceSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastSeenAt;

    /** Rayon de l'enseigne (`iIdRayon` chez Leclerc), fait de catalogue rapporté par un agent (DRV-642). */
    #[ORM\Column(name: 'rayon_id', type: Types::INTEGER, nullable: true)]
    public ?int $rayonId;

    /** Famille de l'enseigne (`iIdFamille` chez Leclerc), le niveau sous le rayon (DRV-642). */
    #[ORM\Column(name: 'family_id', type: Types::INTEGER, nullable: true)]
    public ?int $familyId;
}
