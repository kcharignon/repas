<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Copier une recette depuis Marmiton (ADR 0037, MAR-003) :
 *
 * - `recipe.marmiton_id` : le tag de la recette Marmiton NEUTRE (l'identifiant de la page), unique quand il est présent (index partiel) ;
 *   vide pour toute autre recette, copies comprises ;
 * - `recipe.source_url` : l'adresse canonique de la page dont la recette vient, reprise par chacune de ses copies ;
 * - l'unité `pincee` (« 1 pincée de sel »), sans dimension comme la cuillère à café.
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe pas les deux colonnes (ses `INSERT` les laissent vides, ses `UPDATE`
 * n'y touchent pas) et ignore une unité de plus ; le retour arrière du code ne défait pas le schéma. Aucune donnée existante n'est
 * touchée. Rejouable sans erreur (`IF NOT EXISTS`, `ON CONFLICT DO NOTHING` : une unité `pincee` déjà créée à la main reste telle quelle).
 * `down` supprime les colonnes (donc le tag et le lien d'origine de chaque recette) et l'unité ; il ne se joue qu'à la main, sur décision
 * humaine, et jamais si une ligne de recette ou une conversion utilise la pincée.
 */
final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recettes Marmiton : recipe.marmiton_id (unique), recipe.source_url et l\'unité pincée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recipe ADD COLUMN IF NOT EXISTS marmiton_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE recipe ADD COLUMN IF NOT EXISTS source_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_recipe_marmiton_id ON recipe (marmiton_id) WHERE (marmiton_id IS NOT NULL)');
        $this->addSql("INSERT INTO unit (slug, name, symbol, dimension, base_factor) VALUES ('pincee', 'pincée', 'pincée', NULL, NULL) ON CONFLICT (slug) DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_recipe_marmiton_id');
        $this->addSql('ALTER TABLE recipe DROP COLUMN IF EXISTS source_url');
        $this->addSql('ALTER TABLE recipe DROP COLUMN IF EXISTS marmiton_id');
        $this->addSql("DELETE FROM unit WHERE slug = 'pincee'");
    }
}
