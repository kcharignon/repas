<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index des lectures par propriétaire : `recipe.author`, `ingredient.creator` (avec `slugged_name`, que le contrôle de doublon de nom lit
 * avec le créateur) et `shopping_list.owner` (avec `status`, que la liste active, la liste envoyée et le quota lisent avec le propriétaire).
 * Chacune de ces lectures parcourait la table entière ; elle s'arrête désormais à la fin des lignes du compte. Index seulement : aucune
 * colonne, aucune donnée ne change, et la version précédente du code les ignore (ADR 0017 règle 6). Rejouable sans erreur
 * (`IF NOT EXISTS`), mêmes noms que ceux du mapping.
 */
final class Version20261027200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index recipe(author), ingredient(creator, slugged_name) et shopping_list(owner, status)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_recipe_author ON recipe (author)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ingredient_creator ON ingredient (creator, slugged_name)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_shopping_list_owner ON shopping_list (owner, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_shopping_list_owner');
        $this->addSql('DROP INDEX IF EXISTS idx_ingredient_creator');
        $this->addSql('DROP INDEX IF EXISTS idx_recipe_author');
    }
}
