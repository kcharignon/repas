<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250224215851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champs statistics pour l\'utilisateur';
    }

    public function up(Schema $schema): void
    {
        $statistics = json_encode(["createdAt" => new DateTimeImmutable()]);
        $this->addSql("ALTER TABLE \"user\" ADD statistics JSON NOT NULL DEFAULT '{$statistics}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP statistics');
    }
}
