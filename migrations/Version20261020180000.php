<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mémoriser la contenance d'un produit dans l'unité de la liste (DRV-652, lot 1) : « 20 tranches » pour le cheddar que la liste
 * compte en tranches. Une unité sans dimension (tranche, cuillère…) ne se déduit pas du contenu net du produit : au lieu de
 * redemander à chaque envoi « combien de produits ? », Repas retient combien d'unités tiennent dans CE produit et calcule le nombre de
 * produits. Deux colonnes nullables sur `drive_choice_product`, la ligne d'un produit dans la préférence d'un ingrédient : la
 * contenance est propre à l'utilisateur et au couple (préférence, produit), jamais à la fiche commune `drive_product`.
 *
 * Additive : compatible avec la version précédente du code (ADR 0017 règle 6), qui ne les lit ni ne les écrit. Attention, son
 * `DELETE` puis `INSERT` des lignes d'une préférence (réécriture complète, sans ces colonnes) EFFACE les contenances de cette
 * préférence : un retour arrière du code ne défait pas le schéma, mais les contenances d'une préférence réécrite par l'ancienne
 * version sont perdues (l'agent redemande alors le nombre de produits, comme avant ; rien d'autre n'est touché).
 *
 * `down` supprime les colonnes et donc les contenances mémorisées ; il ne se joue qu'à la main, sur décision humaine. Le plan
 * retombe alors sur le contenu net du produit, comme avant.
 */
final class Version20261020180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Colonnes drive_choice_product.pack_size_value et pack_size_unit_slug : la contenance d'un produit dans l'unité de la liste";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_choice_product ADD pack_size_value NUMERIC(7, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE drive_choice_product ADD pack_size_unit_slug VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_choice_product DROP pack_size_unit_slug');
        $this->addSql('ALTER TABLE drive_choice_product DROP pack_size_value');
    }
}
