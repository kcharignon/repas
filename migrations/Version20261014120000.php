<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Supprime les lignes de recette orphelines (laissées par la suppression d'une recette)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM recipe_row WHERE recipe NOT IN (SELECT id FROM recipe)');
    }

    public function down(Schema $schema): void
    {
        // Les lignes supprimées n'appartenaient plus à aucune recette : rien à restaurer.
        $this->throwIrreversibleMigrationException();
    }
}
