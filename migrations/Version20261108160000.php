<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le total d'un parent dans la liste de courses (ADR 0038, SUB-003) : `shopping_list.part_shares` est le registre CACHÉ des parties d'ingrédient
 * (« Jaune d'œuf ») que la liste contient. Une partie n'est jamais une ligne de la liste : elle se plie dans la ligne de son parent, et le registre
 * garde par partie ce qu'il faut pour retrouver le plus grand des équivalents du parent (« 3 œufs + 2 jaunes + 2 blancs = 5 œufs »,
 * « 2 blancs puis 3 jaunes = 3 œufs »). Un objet JSON par slug de partie : `{"parent": …, "quantity": …, "equivalent": …}`.
 *
 * Vide (`NULL`) pour toute liste existante et pour toute liste qui ne reçoit pas de partie : aucune donnée n'est écrite, donc rien ne change pour
 * personne tant qu'aucun ingrédient n'est découpé.
 *
 * Additive (ADR 0017 règle 6) : l'entité du code précédent ne mappe pas la colonne, ses `INSERT` la laissent à `NULL`, ses `UPDATE` n'y touchent pas.
 * Mais l'ancien code ne connaît pas les parties : il ne retirerait pas de la ligne du parent ce que le registre y a ajouté (le total ne baisserait
 * plus quand on retire un repas), et il poserait « Jaune d'œuf » comme une ligne ordinaire : on ne revient pas au code précédent sans prévenir une
 * fois des parties créées (`docs/production.md`). Rejouable sans erreur (`IF NOT EXISTS`). `down` supprime la colonne, donc les registres des
 * listes : les lignes des parents gardent leur total, rien d'autre n'est perdu ; il ne se joue qu'à la main, sur décision humaine.
 */
final class Version20261108160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sous-ingrédients : shopping_list.part_shares, le registre caché des parties d\'une liste de courses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list ADD COLUMN IF NOT EXISTS part_shares JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list DROP COLUMN IF EXISTS part_shares');
    }
}
