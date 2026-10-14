<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute la position des lignes de recette (l'ordre de saisie devient garanti), remplie dans l'ordre de stockage actuel";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe_row ADD position INT DEFAULT 0 NOT NULL');
        // L'ordre affiché jusqu'ici était l'ordre de stockage : les positions existantes le reprennent, rien ne bouge à l'écran
        $this->addSql('UPDATE recipe_row r SET position = s.rn FROM (SELECT id, row_number() OVER (PARTITION BY recipe ORDER BY ctid) - 1 AS rn FROM recipe_row) s WHERE r.id = s.id');
        $this->addSql('ALTER TABLE recipe_row ALTER position DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe_row DROP position');
    }
}
