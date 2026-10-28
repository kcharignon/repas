<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inscription avec la seule adresse (ADR 0020, amendement du 2026-10-28) : le mot de passe se choisit après le clic sur le lien reçu par
 * email. Les comptes encore en attente ont été inscrits avec l'ancien formulaire, donc avec un mot de passe posé AVANT la confirmation de
 * l'adresse, par quelqu'un qui n'est peut-être pas le propriétaire de la boîte : une fois l'adresse confirmée, il ouvrirait un accès. Cette
 * migration le remplace par `User::NO_PASSWORD` (« ! », jamais un hachage valide : aucune saisie ne lui correspond) et fait monter la
 * version des identifiants. Le propriétaire de l'adresse choisit son mot de passe par « mot de passe oublié », dont le lien prouve l'adresse
 * et active le compte en attente ; confirmer l'adresse par l'ancien lien activerait le compte, sans mot de passe non plus
 * (`VerifyEmailHandler`).
 *
 * Données seulement, aucune colonne : compatible avec la version précédente du code (ADR 0017 règle 6), qui lit un compte en attente sans
 * mot de passe comme un compte dont aucune saisie ne convient. Le retour arrière du code ne rend pas les anciens mots de passe (perdus
 * par construction : c'est le but) ; `down` ne fait donc rien. Rejouable sans effet (la condition exclut les comptes déjà vidés).
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Comptes en attente : mot de passe posé avant la confirmation de l'adresse remplacé par « aucun mot de passe »";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE "user" SET password = \'!\', credentials_version = credentials_version + 1 '
            . 'WHERE status = \'PENDING\' AND password <> \'!\''
        );
    }

    public function down(Schema $schema): void
    {
        // Les anciens mots de passe ne se reconstituent pas : rien à défaire
        $this->addSql('SELECT 1');
    }
}
