<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la version (ETag) des recettes et des listes de courses : contrôle de modification concurrente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE recipe ALTER version DROP DEFAULT');
        $this->addSql('ALTER TABLE shopping_list ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE shopping_list ALTER version DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP version');
        $this->addSql('ALTER TABLE shopping_list DROP version');
    }
}
