<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dernière connexion d'un compte, visible de l'administrateur : `user.last_login_at`, la date de la dernière authentification
 * réussie (connexion par mot de passe, renouvellement de la session ; ADR 0021, amendement du 2026-10-20). Écrite seulement par
 * `UserRepository::recordLogin()`, jamais par l'enregistrement du modèle `User`.
 *
 * Additive : une colonne nullable. Compatible avec la version précédente du code (ADR 0017 règle 6), dont l'entité ne la mappe pas :
 * elle ne la lit ni ne l'écrit, et un compte créé par l'ancien code reste à `NULL`. Le retour arrière du code ne défait pas le schéma.
 *
 * Rattrapage : les comptes existants reçoivent la date de leur jeton de rafraîchissement le plus récent (`refresh_token.issued_at`),
 * parce que chaque jeton est émis par une authentification réussie (ouverture d'une session à la connexion, rotation à chaque
 * renouvellement) : c'est exactement ce que la colonne veut dire. La valeur est une borne basse sûre, jamais une date inventée : elle
 * peut être plus ancienne que la vraie dernière connexion (une déconnexion supprime la session, les jetons expirés sont purgés), mais
 * jamais plus récente. Un compte sans aucun jeton reste à `NULL` (« pas encore vu depuis le suivi »). On n'utilise pas
 * `user_app_version.seen_at` : c'est la date d'une requête de l'application, pas d'une authentification (un jeton d'accès vit une heure).
 *
 * `down` supprime la colonne, donc la mémoire de ces dates.
 */
final class Version20261020230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne user.last_login_at : date de la dernière authentification réussie, rattrapée depuis les jetons de rafraîchissement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql(
            'UPDATE "user" SET last_login_at = last_tokens.issued_at '
            . 'FROM (SELECT user_id, MAX(issued_at) AS issued_at FROM refresh_token GROUP BY user_id) AS last_tokens '
            . 'WHERE "user".id = last_tokens.user_id AND "user".last_login_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP last_login_at');
    }
}
