<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier « liste envoyée au drive » (DRV-105) : une unité porte sa dimension (poids, volume, pièce, conditionnement) et son
 * facteur vers l'unité de base (g, ml, pièce), pour calculer le nombre de produits à acheter. Colonnes nullables : compatible avec
 * la version précédente du code (ADR 0017 règle 6), qui les ignore. Les unités de référence sont amorcées par slug ; les autres
 * (cuillères, tasse, verre, bol, tranche, cube, gousse) restent sans dimension. Une unité créée par l'administrateur n'est pas touchée.
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unit : colonnes dimension et base_factor (nullables), amorcées pour les unités de référence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit ADD dimension VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE unit ADD base_factor DOUBLE PRECISION DEFAULT NULL');

        $this->addSql("UPDATE unit SET dimension = 'WEIGHT', base_factor = 1 WHERE slug = 'gramme'");
        $this->addSql("UPDATE unit SET dimension = 'WEIGHT', base_factor = 1000 WHERE slug = 'kilo'");
        $this->addSql("UPDATE unit SET dimension = 'VOLUME', base_factor = 1 WHERE slug = 'millilitre'");
        $this->addSql("UPDATE unit SET dimension = 'VOLUME', base_factor = 10 WHERE slug = 'centilitre'");
        $this->addSql("UPDATE unit SET dimension = 'VOLUME', base_factor = 1000 WHERE slug = 'litre'");
        $this->addSql("UPDATE unit SET dimension = 'COUNT', base_factor = 1 WHERE slug = 'unite'");
        $this->addSql("UPDATE unit SET dimension = 'PACKAGE', base_factor = 1 WHERE slug IN ('paquet', 'boite', 'sachet', 'bouteille', 'conserve', 'brique', 'pot', 'flacon', 'plaquette', 'tablette', 'buche')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit DROP dimension');
        $this->addSql('ALTER TABLE unit DROP base_factor');
    }
}
