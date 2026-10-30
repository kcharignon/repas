<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La recette secrète (ADR 0033, REC-001) : `recipe.secret` dit « cette recette n'est vue que de son auteur » (absente de « Recettes des
 * autres », illisible et non copiable par un autre utilisateur, administrateur compris). Une colonne, aucune donnée : `FALSE` partout, donc
 * toute recette existante reste visible comme aujourd'hui. L'auteur la change ensuite d'un geste (`PUT /api/v1/recipes/{id}`), jamais cette
 * migration.
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe pas la colonne, ses `INSERT` la laissent à `FALSE` (la valeur par défaut
 * s'applique), ses `UPDATE` ne la touchent pas ; le retour arrière du code ne défait pas le schéma. Attention : tant que l'ancien code tourne,
 * il ignore la colonne et montre de nouveau à tous les utilisateurs les recettes déjà secrètes. Avant de remettre une version qui ne la connaît
 * pas, il faut donc avoir prévenu les utilisateurs ou accepté que les recettes secrètes redeviennent visibles le temps du retour arrière.
 * Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime la colonne, donc le choix « secrète » de chaque auteur : toutes les recettes redeviennent visibles. Il ne se joue qu'à la
 * main, sur décision humaine.
 */
final class Version20261030210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne recipe.secret : la recette secrète, vue de son auteur seul (faux par défaut)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD COLUMN IF NOT EXISTS secret BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe DROP COLUMN IF EXISTS secret');
    }
}
