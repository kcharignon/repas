<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « liste envoyée au drive » (DRV-613) : compteur du jour des écritures de produits de chaque utilisateur, porté par ses
 * réglages du drive (plafond par utilisateur, constat C2 de la revue de sécurité). Deux colonnes ajoutées, la seconde avec une
 * valeur par défaut : compatible avec la version précédente du code (ADR 0017 règle 6), qui n'écrit pas ces colonnes.
 */
final class Version20261018150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'drive_settings : jour et nombre des écritures de produits (plafond par utilisateur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_settings ADD product_writes_day DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE drive_settings ADD product_writes_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_settings DROP product_writes_day');
        $this->addSql('ALTER TABLE drive_settings DROP product_writes_count');
    }
}
