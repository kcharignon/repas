<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Liaison de compte Alexa (ADR 0029, VOX-202) : ce qu'il faut ajouter aux trois tables de VOX-201 pour tenir le serveur d'autorisation.
 *
 * - `voice_access_grant.user_id` : le compte à qui appartient la paire de jetons. Le contexte `Voice` n'a pas de clé étrangère, et la
 *   résolution d'un jeton d'accès (une lecture par requête d'Alexa) ne doit lire qu'une ligne. La colonne est aussi effacée avec le
 *   compte (`PurgeUserVoiceData`, `UserOwnedDataCoverageTest`).
 * - `voice_access_grant.used_at` : le jeton de rafraîchissement de cette ligne a été échangé contre son successeur (rotation, comme
 *   `refresh_token.used_at`, ADR 0015). Une ligne ainsi marquée est GARDÉE jusqu'à l'expiration de son jeton de rafraîchissement : le
 *   présenter une seconde fois est un rejeu, qui révoque la liaison. Son jeton d'accès reste valable jusqu'à son expiration (Amazon :
 *   ne pas invalider un jeton d'accès avant `expires_in`).
 * - `voice_authorization_code.link_id` : la liaison créée en échangeant ce code. Rejouer un code déjà échangé révoque cette liaison
 *   (RFC 6749, 4.1.2 : révoquer les jetons déjà émis à partir du code).
 *
 * Additive et seulement additive : deux colonnes nullables d'abord, une colonne ajoutée à une table que le code précédent n'écrit
 * jamais (VOX-201 n'a posé que le schéma, `ALEXA_ENABLED` est coupé) ; aucune table ni colonne existante n'est modifiée. Les lignes
 * déjà présentes, s'il y en avait (aucune ne peut exister : rien ne les écrit avant ce ticket), recevraient le compte de leur liaison ;
 * une ligne sans liaison est un jeton orphelin, effacé. Le retour arrière du code ne défait pas le schéma (ADR 0017 règle 6) : le code
 * précédent ignore ces colonnes. `down` les retire ; il ne se joue qu'à la main.
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liaison de compte Alexa : voice_access_grant.user_id et used_at, voice_authorization_code.link_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voice_access_grant ADD user_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE voice_access_grant g SET user_id = l.user_id FROM voice_link l WHERE l.id = g.link_id');
        $this->addSql('DELETE FROM voice_access_grant WHERE user_id IS NULL');
        $this->addSql('ALTER TABLE voice_access_grant ALTER user_id SET NOT NULL');
        $this->addSql('ALTER TABLE voice_access_grant ADD used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_voice_access_grant_user ON voice_access_grant (user_id)');

        $this->addSql('ALTER TABLE voice_authorization_code ADD link_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voice_authorization_code DROP link_id');

        $this->addSql('DROP INDEX idx_voice_access_grant_user');
        $this->addSql('ALTER TABLE voice_access_grant DROP used_at');
        $this->addSql('ALTER TABLE voice_access_grant DROP user_id');
    }
}
