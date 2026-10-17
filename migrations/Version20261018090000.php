<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « liste envoyée au drive » (DRV-101, ADR 0023) : date d'envoi d'une liste de courses. Colonne nullable : compatible
 * avec la version précédente du code (ADR 0017 règle 6), qui l'ignore. Le statut `SENT` est une valeur de la colonne texte
 * `status` : aucune liste n'en porte tant que `DRIVE_ENABLED` est coupé. Retour arrière avec des listes `SENT` :
 * `UPDATE shopping_list SET status = 'ACTIVE', sent_at = NULL WHERE status = 'SENT'`.
 */
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "ShoppingList : colonne sent_at (nullable), date d'envoi au drive";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list ADD sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list DROP sent_at');
    }
}
