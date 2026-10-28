<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inscription avec la seule adresse (ADR 0020, amendement du 2026-10-28) : le mot de passe se choisit après le clic sur le lien reçu par
 * email. Les comptes encore en attente n'ont donc plus de mot de passe : celui de l'ancien formulaire est remplacé par
 * `User::NO_PASSWORD` (« ! », jamais un hachage valide : aucune saisie ne lui correspond) et la version des identifiants monte. Chaque
 * compte en choisit un nouveau avec le lien de bienvenue (nouvelle inscription avec la même adresse) ou « mot de passe oublié ».
 *
 * Données seulement, aucune colonne : compatible avec la version précédente du code (ADR 0017 règle 6), qui lit un compte en attente sans
 * mot de passe comme un compte dont aucune saisie ne convient. Le retour arrière du code ne rend pas les anciens mots de passe ; `down` ne
 * fait donc rien. Rejouable sans effet (la condition exclut les comptes déjà traités).
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes en attente : ancien mot de passe remplacé par « aucun mot de passe »';
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
