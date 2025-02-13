<?php

namespace DoctrineMigrations;


use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20250213131912 extends AbstractMigration
{

    public function getDescription(): string
    {
        return "Corrige la conversion qui a pour ingredient 'échalote' au lieu de 'echalote'";
    }


    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE conversion SET ingredient = 'echalote' WHERE ingredient = 'échalote'");
    }
}
