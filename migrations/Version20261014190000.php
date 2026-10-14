<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh tokens du SPA (ADR 0015) : hachage du secret, famille de rotation, version des identifiants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE refresh_token (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, family_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, credentials_version INT NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, family_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C74F2195B3BC57DA ON refresh_token (token_hash)');
        $this->addSql('CREATE INDEX idx_refresh_token_family ON refresh_token (family_id)');
        $this->addSql('CREATE INDEX idx_refresh_token_user ON refresh_token (user_id)');
        $this->addSql('CREATE INDEX idx_refresh_token_expires_at ON refresh_token (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refresh_token');
    }
}
