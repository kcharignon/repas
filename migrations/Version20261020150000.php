<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mémoriser la recherche du drive (DRV-650, lot 1) : une préférence d'ingrédient retient le texte qui a fait trouver le produit
 * choisi sur le site de l'enseigne (« céréales coussins » pour « Céréale coussin »), pour chercher un remplaçant avec ce texte quand
 * le produit n'est plus disponible. Une colonne nullable sur `drive_choice` : la recherche suit la clé du choix
 * `(user_id, retailer, store_id, ingredient_slug)`, donc elle est propre à l'utilisateur, à l'enseigne et au magasin.
 *
 * Additive : compatible avec la version précédente du code (ADR 0017 règle 6), qui ne la lit ni ne l'écrit (son `INSERT … ON
 * CONFLICT` ne touche pas la colonne : une valeur déjà écrite reste). Le retour arrière du code ne défait pas le schéma. Une
 * ligne « j'en ai toujours » ne garde jamais de recherche pour la version courante ; si une version plus ancienne a repassé la ligne
 * en « j'en ai toujours » sans l'effacer, les lectures l'ignorent.
 *
 * `down` supprime la colonne et donc les recherches mémorisées ; il ne se joue qu'à la main, sur décision humaine. Le plan retombe
 * alors sur le nom de l'ingrédient, comme avant.
 */
final class Version20261020150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne drive_choice.search_query : la recherche qui a fait trouver le produit choisi (80 caractères au plus)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_choice ADD search_query VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drive_choice DROP search_query');
    }
}
