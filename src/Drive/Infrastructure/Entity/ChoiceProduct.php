<?php

namespace Repas\Drive\Infrastructure\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_choice_product` : un produit accepté (`ACCEPTED`, rang 1 à 5) ou refusé (`REJECTED`, sans rang) d'une
 * préférence. Sans colonne utilisateur ni clé étrangère : la purge d'un compte l'efface par les identifiants de ses
 * préférences (`ChoiceRepository::deleteProductsByUser`). Mapping lu par les loaders (DQL), jamais hydraté ni écrit par le
 * code : les propriétés sont publiques et sans accesseur pour cette raison.
 *
 * `pack_size_value` et `pack_size_unit_slug` (DRV-652) sont la réponse privée de la personne à « combien d'unités de la liste
 * dans ce produit ? » (20 tranches) : elle ne concerne que le couple (préférence, produit) de cette ligne, jamais la fiche commune
 * du produit, et part avec la ligne (donc avec la préférence et avec le compte).
 *
 * `label`, `price_cents` et `price_seen_at` sont de même ce que CE compte a lui-même observé du produit (libellé lu, prix lu et sa date) :
 * nullables, effacés avec la ligne (aucune colonne utilisateur de plus). La fiche commune `drive_product` ne sert que de repli quand la
 * ligne n'a pas de valeur à elle (`COALESCE`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'drive_choice_product')]
#[ORM\UniqueConstraint(name: 'uniq_drive_choice_product_rank', columns: ['choice_id', 'rank'])]
#[ORM\Index(name: 'idx_drive_choice_product_product', columns: ['product_id'])]
class ChoiceProduct
{
    #[ORM\Id]
    #[ORM\Column(name: 'choice_id', type: Types::STRING, length: 36)]
    public string $choiceId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_id', type: Types::STRING, length: 36)]
    public string $productId;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $verdict;

    #[ORM\Column(name: 'rank', type: Types::SMALLINT, nullable: true)]
    public ?int $rank;

    /** Seulement pour un produit accepté ; les deux colonnes `pack_size_*` vont ensemble (toutes deux nulles ou toutes deux remplies). */
    #[ORM\Column(name: 'pack_size_value', type: Types::DECIMAL, precision: 7, scale: 3, nullable: true)]
    public ?string $packSizeValue = null;

    #[ORM\Column(name: 'pack_size_unit_slug', type: Types::STRING, length: 255, nullable: true)]
    public ?string $packSizeUnitSlug = null;

    /**
     * Le libellé tel que CE compte l'a lu, `null` tant qu'il n'a rien observé de ce produit : la lecture retombe alors sur le libellé de
     * la fiche commune `drive_product`, qui ne sert plus que de repli.
     */
    #[ORM\Column(name: 'label', type: Types::STRING, length: 255, nullable: true)]
    public ?string $label = null;

    /**
     * Le prix (en centimes) tel que CE compte l'a observé, avec la date de la lecture : les deux colonnes vont ensemble. Seulement pour un
     * produit accepté. `null` : la lecture retombe sur le prix de la fiche commune.
     */
    #[ORM\Column(name: 'price_cents', type: Types::INTEGER, nullable: true)]
    public ?int $priceCents = null;

    #[ORM\Column(name: 'price_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?DateTimeImmutable $priceSeenAt = null;
}
