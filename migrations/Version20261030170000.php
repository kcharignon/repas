<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « plusieurs enseignes de drive » (DRV-703) : la préférence d'enseigne d'un compte, une ligne par utilisateur. `default_retailer`
 * est son drive habituel (choisi dans « Moi », facultatif), `last_used_retailer` la dernière enseigne qu'il a ouverte. L'enseigne n'est
 * qu'un identifiant (`VARCHAR(20)`, comme toutes les colonnes `retailer`) : aucune clé étrangère, ni vers `user` (le compte efface sa ligne
 * à sa suppression, ADR 0021, `UserOwnedDataCoverageTest`) ni vers `drive_settings` (« configurée » se juge sur la présence d'un réglage).
 *
 * Nouvelle table seule (additive, ADR 0017 règle 6) : la version précédente du code l'ignore et marche comme avant, sans ligne la
 * sélection vaut `AUTO` ou `NONE` comme avant. Rejouable sans erreur.
 *
 * `down` supprime la table : elle ne contient que le choix de confort de la personne (drive habituel, dernière enseigne), qu'elle peut
 * refaire d'un geste ; rien de précieux ne se perd.
 */
final class Version20261030170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table drive_retailer_preference : le drive habituel et la dernière enseigne utilisée, une ligne par utilisateur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS drive_retailer_preference (user_id VARCHAR(36) NOT NULL, default_retailer VARCHAR(20) DEFAULT NULL, last_used_retailer VARCHAR(20) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS drive_retailer_preference');
    }
}
