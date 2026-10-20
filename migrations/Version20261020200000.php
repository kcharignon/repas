<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Socle des ingrédients propres à chaque utilisateur (ADR 0028, ING-001) : adopter un commun, c'est en créer une copie au nom de
 * l'utilisateur et noter la correspondance. Deux structures, aucune donnée :
 *
 * - `ingredient.adopted_from` (nullable) : le slug du commun dont cet ingrédient est l'adoption, un fait immuable (statistiques,
 *   provenance à la copie d'une recette) ;
 * - `ingredient_alias` : « pour cet utilisateur, `alias_slug` est son ingrédient `ingredient_slug` », clé `(user_id, alias_slug)`.
 *   `kind` vaut `adoption` (l'alias est un commun) ou `merge` (l'alias est un ingrédient absorbé par une fusion) ; `source` dit
 *   d'où vient la ligne (`first-use`, `copy`, `migration-AAAA-MM-JJ`, `merge`). Aucune clé étrangère (convention du dépôt, ADR 0013
 *   et 0021) : la suppression du compte efface ses alias (`PurgeUserDataHandler`).
 *
 * Additive : une colonne nullable et une nouvelle table, rien n'est supprimé ni renommé. Compatible avec la version précédente du
 * code (ADR 0017 règle 6) : son `IngredientPostgreSQLRepository::updateEntity` ne copie que les colonnes qu'il connaît et Doctrine
 * n'écrit que les colonnes mappées, donc la colonne n'est ni lue ni effacée ; la table lui est inconnue. Le retour arrière du code ne
 * défait pas le schéma. Aucun alias n'existe tant que la migration de données (ING-003) n'a pas tourné : rien n'est masqué ni repointé.
 *
 * `down` supprime la colonne et la table, donc la mémoire de ce qui a été adopté ou fusionné ; il ne se joue qu'à la main, sur
 * décision humaine, jamais sur une base où des utilisateurs ont adopté des communs sans sauvegarde (les copies d'ingrédients restent,
 * ce sont des ingrédients personnels ordinaires pour l'ancien code).
 */
final class Version20261020200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Colonne ingredient.adopted_from et table ingredient_alias : la correspondance entre un commun adopté (ou un ingrédient fusionné) et l'ingrédient de l'utilisateur";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ingredient ADD adopted_from VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE ingredient_alias (user_id VARCHAR(36) NOT NULL, alias_slug VARCHAR(255) NOT NULL, ingredient_slug VARCHAR(255) NOT NULL, kind VARCHAR(16) NOT NULL, source VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, alias_slug))');
        $this->addSql('CREATE INDEX idx_ingredient_alias_ingredient ON ingredient_alias (user_id, ingredient_slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingredient_alias');
        $this->addSql('ALTER TABLE ingredient DROP adopted_from');
    }
}
