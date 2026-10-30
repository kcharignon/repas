<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « plusieurs enseignes de drive » (DRV-720) : l'identifiant d'un magasin passe de 20 à 40 caractères. Chez une enseigne où
 * le magasin n'est pas une adresse, l'agent lit un identifiant explicite (un UUID : 36 caractères) que `VARCHAR(20)` ne contenait pas.
 * Les trois colonnes qui le portent sont élargies : `drive_settings.store_id`, `drive_product.store_id` et `drive_choice.store_id`
 * (aucune autre table n'en a : `drive_choice_product` désigne le choix et le produit par leur identifiant, `drive_rayon_vote` ne
 * porte pas de magasin).
 *
 * Élargissement seul : PostgreSQL n'a ni réécriture de table ni reconstruction d'index pour une colonne `VARCHAR` dont on relève la
 * limite, et les index uniques `uniq_drive_product_key` (retailer, store_id, retailer_product_id) et
 * `uniq_drive_choice_user_store_ingredient` (user_id, retailer, store_id, ingredient_slug) sont conservés tels quels. Compatible avec
 * la version précédente du code (ADR 0017 règle 6) : elle n'écrit jamais plus de 20 caractères tant qu'aucune enseigne à identifiant
 * explicite n'est déclarée, et son mapping (`length: 20`) ne contrôle rien à la lecture. Aucune donnée n'est touchée.
 *
 * `down` ramène les colonnes à 20 caractères, **seulement si aucun identifiant n'en dépasse 20** : sinon la migration s'arrête
 * AVANT la première instruction, avec le nombre de lignes en cause, plutôt que de laisser PostgreSQL refuser en cours de route (ou de
 * tronquer, ce qu'elle ne fait jamais). Elle ne se joue qu'à la main, sur décision humaine, après avoir retiré ou rangé ces lignes.
 */
final class Version20261030100000 extends AbstractMigration
{
    private const array TABLES = ['drive_settings', 'drive_product', 'drive_choice'];

    public function getDescription(): string
    {
        return 'Colonnes store_id de drive_settings, drive_product et drive_choice : VARCHAR(20) élargi à VARCHAR(40) (identifiant de magasin explicite)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER store_id TYPE VARCHAR(40)', $table));
        }
    }

    public function down(Schema $schema): void
    {
        $tooLong = 0;
        foreach (self::TABLES as $table) {
            $tooLong += (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE LENGTH(store_id) > 20', $table));
        }
        $this->abortIf(
            $tooLong > 0,
            sprintf('%d ligne(s) de drive_settings, drive_product ou drive_choice ont un identifiant de magasin de plus de 20 caractères : retour arrière refusé (rien n\'est tronqué).', $tooLong),
        );

        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER store_id TYPE VARCHAR(20)', $table));
        }
    }
}
