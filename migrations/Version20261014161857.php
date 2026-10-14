<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014161857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Aligne le schéma sur DBAL 4 : retire le commentaire de type résiduel et les valeurs par défaut que le mapping ne déclare pas";
    }

    public function up(Schema $schema): void
    {
        // DBAL 4 n'écrit plus de commentaire `(DC2Type:…)` sur les colonnes
        $this->addSql('COMMENT ON COLUMN shopping_list.created_at IS \'\'');
        // Valeurs par défaut posées par d'anciennes migrations, que les entités n'ont jamais déclarées : l'application fournit
        // toujours ces valeurs. (`user.statistics` portait même une date figée, celle du jour de la migration d'origine.)
        $this->addSql('ALTER TABLE ingredient ALTER compatible_units DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER statistics DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // Les valeurs par défaut retirées n'avaient pas de sens (dont une date figée) : rien à restaurer.
        $this->throwIrreversibleMigrationException();
    }
}
