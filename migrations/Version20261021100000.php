<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mémoire des « Pas le même » (ADR 0028 décision h, ING-006) : quand l'application propose deux ingrédients d'un utilisateur comme
 * doublon possible et qu'il répond « Pas le même », la paire n'est plus jamais reproposée. Une table, aucune donnée :
 *
 * - `ingredient_distinct (user_id, first_slug, second_slug, created_at)`, clé primaire sur les trois premières colonnes : une paire
 *   n'a pas d'ordre, les deux slugs sont rangés dans l'ordre des OCTETS (`COLLATE "C"`, comme `strcmp` côté PHP) et la contrainte
 *   `CHECK` l'impose ; la paire (a, b) et la paire (b, a) sont donc la même ligne.
 * - aucune clé étrangère (convention du dépôt, ADR 0013 et 0021) : la suppression du compte efface les lignes (`PurgeUserDataHandler`) et
 *   la disparition d'un des deux ingrédients, par suppression ou par fusion, aussi.
 *
 * Additive : une nouvelle table que le code précédent ignore (ADR 0017 règle 6) ; le retour arrière du code ne défait pas le schéma.
 * `down` supprime la table, donc la mémoire des paires écartées : il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Table ingredient_distinct : les paires d'ingrédients qu'un utilisateur a écartées par « Pas le même »";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ingredient_distinct (user_id VARCHAR(36) NOT NULL, first_slug VARCHAR(255) NOT NULL, second_slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (user_id, first_slug, second_slug), CONSTRAINT ingredient_distinct_ordered CHECK (first_slug COLLATE "C" < second_slug COLLATE "C"))');
        // Retrouver les paires qui nomment un ingrédient (nettoyage à sa disparition) : la clé couvre le premier slug, cet index le second
        $this->addSql('CREATE INDEX idx_ingredient_distinct_second ON ingredient_distinct (user_id, second_slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ingredient_distinct');
    }
}
