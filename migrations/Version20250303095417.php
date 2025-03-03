<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250303095417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajout d'un nom pour les listes de courses";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list ADD name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list DROP name');
    }
}
