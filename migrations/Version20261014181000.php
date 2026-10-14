<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute la version des identifiants de l'utilisateur (révocation des jetons au changement de mot de passe, indépendante du hash)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD credentials_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER credentials_version DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP credentials_version');
    }
}
