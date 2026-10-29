<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chaque compte garde SES valeurs observées d'un produit du drive : le libellé tel qu'il l'a lu et le prix tel qu'il l'a observé. Trois
 * colonnes nullables sur `drive_choice_product`, la ligne d'un produit dans la préférence d'un ingrédient (même mécanisme que la contenance,
 * DRV-652) : `label`, `price_cents` et `price_seen_at`. Elles sont propres au compte et au couple (préférence, produit), et partent avec la
 * ligne, donc avec le compte (aucune colonne utilisateur de plus). La fiche commune `drive_product` ne sert plus que de valeur de repli,
 * pour une ligne qui n'a pas de valeur à elle.
 *
 * Données existantes : les lignes actuelles sont renseignées UNE FOIS à partir de la fiche commune, pour que chaque compte soit tout de
 * suite détaché de la fiche : ce qu'il voit ne change pas aujourd'hui (la valeur recopiée est celle qu'il lisait déjà), et ce que les autres
 * comptes écriront ensuite sur la fiche ne l'atteint plus. Le libellé est recopié pour tout produit accepté ou refusé, le prix pour les seuls
 * produits acceptés (un produit refusé n'est jamais acheté, donc jamais estimé), avec sa date. Rejouable sans effet : une ligne qui a déjà
 * une valeur à elle n'est jamais touchée (`IS NULL`), et un produit absent de la fiche commune ne fait rien.
 *
 * Additive : compatible avec la version précédente du code (ADR 0017 règle 6), qui ne les lit ni ne les écrit. Attention, son `DELETE` puis
 * `INSERT` des lignes d'une préférence (réécriture complète, sans ces colonnes) EFFACE les valeurs de cette préférence : un retour arrière du
 * code ne défait pas le schéma, mais les valeurs d'une préférence réécrite par l'ancienne version sont perdues, et la ligne retombe sur la
 * fiche commune, comme avant (rien d'autre n'est touché).
 *
 * `down` supprime les colonnes et donc les valeurs observées ; il ne se joue qu'à la main, sur décision humaine. Les lectures retombent alors
 * sur la fiche commune, comme avant.
 */
final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonnes drive_choice_product.label, price_cents et price_seen_at : les valeurs observées par le compte (fiche commune en repli), recopiées une fois pour les lignes existantes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_choice_product ADD label VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE drive_choice_product ADD price_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE drive_choice_product ADD price_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Les lignes existantes : le libellé de la fiche, pour les produits acceptés comme refusés (rejouable : seulement une ligne sans libellé)
        $this->addSql(
            'UPDATE drive_choice_product cp SET label = p.label'
            . ' FROM drive_product p'
            . ' WHERE p.id = cp.product_id AND cp.label IS NULL',
        );
        // Le prix et sa date, ensemble, pour les seuls produits acceptés dont la fiche connaît un prix daté (rejouable : seulement une ligne sans prix)
        $this->addSql(
            'UPDATE drive_choice_product cp SET price_cents = p.price_cents, price_seen_at = p.price_seen_at'
            . ' FROM drive_product p'
            . " WHERE p.id = cp.product_id AND cp.verdict = 'ACCEPTED'"
            . ' AND cp.price_cents IS NULL AND cp.price_seen_at IS NULL'
            . ' AND p.price_cents IS NOT NULL AND p.price_seen_at IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_choice_product DROP price_seen_at');
        $this->addSql('ALTER TABLE drive_choice_product DROP price_cents');
        $this->addSql('ALTER TABLE drive_choice_product DROP label');
    }
}
