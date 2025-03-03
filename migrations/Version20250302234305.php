<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250302234305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champs sluggedName pour les ingredients';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient ADD slugged_name VARCHAR(255)');
        $this->addSql('UPDATE ingredient SET slugged_name = CASE WHEN creator IS NULL THEN slug ELSE LEFT(slug, LENGTH(slug) - 36) END');
        $this->addSql('ALTER TABLE ingredient ALTER COLUMN slugged_name SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient DROP slugged_name');
    }
}
