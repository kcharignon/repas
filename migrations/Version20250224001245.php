<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Repas\User\Domain\Model\UserStatus;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250224001245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la colonne status de l\'utilisateur';
    }

    public function up(Schema $schema): void
    {
        $status = UserStatus::ACTIVE->value;
        $this->addSql("ALTER TABLE \"user\" ADD status VARCHAR(255) NOT NULL DEFAULT '{$status}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP status');
    }
}
