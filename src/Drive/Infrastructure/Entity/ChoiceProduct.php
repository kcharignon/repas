<?php

namespace Repas\Drive\Infrastructure\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapping de `drive_choice_product` : un produit accepté (`ACCEPTED`, rang 1 à 5) ou refusé (`REJECTED`, sans rang) d'une
 * préférence. Sans colonne utilisateur ni clé étrangère : la purge d'un compte l'efface par les identifiants de ses
 * préférences (`ChoiceRepository::deleteProductsByUser`). Mapping lu par les loaders (DQL), jamais hydraté ni écrit par le
 * code : les propriétés sont publiques et sans accesseur pour cette raison.
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
}
