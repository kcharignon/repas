<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commande vocale Alexa (ADR 0029, VOX-201) : les trois tables de la liaison de compte, vides.
 *
 * - `voice_link` : la liaison d'un compte Repas avec un compte Alexa (`user_id`, `alexa_user_id` connu à la première requête d'Alexa,
 *   dates de création, de dernier usage et de retrait) ;
 * - `voice_authorization_code` : le code à usage unique donné à Alexa (HACHÉ en clé primaire, jamais le code ; défi PKCE, adresse de
 *   retour, expiration, usage) ;
 * - `voice_access_grant` : la paire de jetons d'une liaison (HACHÉS seulement : l'accès en clé primaire, le rafraîchissement en index
 *   unique), reliée à sa liaison par `link_id`.
 *
 * Aucune clé étrangère (convention du dépôt, ADR 0013 et 0021) : la suppression du compte efface les trois tables
 * (`PurgeUserVoiceData`), les jetons par leur liaison. Rien n'est écrit ici que le schéma.
 *
 * Additive et seulement additive : trois nouvelles tables que le code précédent ignore, aucune table ni colonne existante touchée
 * (ADR 0017 règle 6) ; le retour arrière du code ne défait pas le schéma. `down` supprime les trois tables, donc les liaisons, codes et
 * jetons : il ne se joue qu'à la main, sur décision humaine (les utilisateurs devraient relier Alexa).
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables voice_link, voice_authorization_code et voice_access_grant : liaison de compte de la commande vocale Alexa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE voice_link (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, alexa_user_id VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_voice_link_user ON voice_link (user_id)');

        $this->addSql('CREATE TABLE voice_authorization_code (code_hash VARCHAR(64) NOT NULL, user_id VARCHAR(36) NOT NULL, client_id VARCHAR(100) NOT NULL, redirect_uri VARCHAR(2048) NOT NULL, code_challenge VARCHAR(128) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (code_hash))');
        $this->addSql('CREATE INDEX idx_voice_authorization_code_user ON voice_authorization_code (user_id)');

        $this->addSql('CREATE TABLE voice_access_grant (access_hash VARCHAR(64) NOT NULL, refresh_hash VARCHAR(64) NOT NULL, link_id VARCHAR(36) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refresh_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (access_hash))');
        $this->addSql('CREATE UNIQUE INDEX uniq_voice_access_grant_refresh ON voice_access_grant (refresh_hash)');
        $this->addSql('CREATE INDEX idx_voice_access_grant_link ON voice_access_grant (link_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE voice_access_grant');
        $this->addSql('DROP TABLE voice_authorization_code');
        $this->addSql('DROP TABLE voice_link');
    }
}
