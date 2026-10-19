<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Version de l'application connue du serveur (DRV-637) : la dernière version de l'application vue pour chaque compte, une ligne
 * par compte et par plateforme, clé `(user_id, platform)` (`android` seulement aujourd'hui). Seule la version est retenue, avec
 * l'heure : aucun identifiant d'appareil, aucun modèle de téléphone. Aucune clé étrangère (convention du dépôt, ADR 0021) : la
 * suppression du compte efface ses lignes (`DeleteUserHandler`).
 *
 * Additive : nouvelle table seulement, aucune colonne ajoutée ni modifiée ailleurs. Compatible avec la version précédente du code
 * (ADR 0017 règle 6), qui ne connaît pas la table et l'ignore ; le retour arrière du code ne défait pas le schéma.
 *
 * `down` supprime la table et donc les versions connues, sans toucher aux comptes : rien n'en dépend que l'invitation à mettre à
 * jour, qui retombe sur « version inconnue » (aucune invitation). Le retour arrière du code n'en a pas besoin : `down` ne se joue
 * qu'à la main, sur décision humaine.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table user_app_version : dernière version de l'application vue pour chaque compte et chaque plateforme";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_app_version (user_id VARCHAR(36) NOT NULL, platform VARCHAR(16) NOT NULL, version VARCHAR(20) NOT NULL, seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, platform))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_app_version');
    }
}
