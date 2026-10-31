<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les recettes éclatées entre plusieurs listes de courses (ADR 0036, LST-005) : `meal.placed_ingredients` garde, pour un repas d'une recette qui
 * remplit plusieurs listes, les produits que ce repas a posés DANS SA LISTE. La répartition est figée à la planification (ADR 0036 décision 5) :
 * changer ensuite la destination d'un produit ne déplace aucune ligne, donc changer les personnes ou retirer la recette ne peut pas recalculer
 * « quel produit est dans quelle liste » d'après la destination d'aujourd'hui, il faut s'en souvenir. Une liste JSON de slugs d'ingrédients.
 *
 * Vide (`NULL`) pour tout repas qui a posé toute la recette : tous ceux d'avant les types, et ceux d'une recette qui n'a rempli qu'une liste. Aucune
 * donnée n'est écrite : aucun repas n'a de groupe ni de répartition, donc rien ne change pour personne.
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe pas la colonne, ses `INSERT` la laissent à `NULL`, ses `UPDATE` ne la
 * touchent pas ; un retour arrière du code traite un repas éclaté comme un repas qui aurait posé toute sa recette (il ne connaît pas non plus
 * les types, donc ne voit que la liste principale). Le retour arrière ne défait pas le schéma. Rejouable sans erreur (`IF NOT EXISTS`).
 * `down` supprime la colonne, donc la répartition des repas éclatés : il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261031160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne meal.placed_ingredients : les produits qu\'un repas d\'une recette éclatée a posés dans sa liste (LST-005)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal ADD COLUMN IF NOT EXISTS placed_ingredients JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal DROP COLUMN IF EXISTS placed_ingredients');
    }
}
