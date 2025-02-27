<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250227004140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champs originelle pour une recette';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD orignal VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP orignal');
    }
}
