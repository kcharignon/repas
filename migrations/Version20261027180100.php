<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Votes de départ de `drive_rayon_vote` : relus des choix que les comptes ont réellement enregistrés, et non recopiés de l'ancien compteur
 * `drive_department_rayon` (qui compte des envois, sans compte). Un vote par compte et par couple (enseigne, rayon de Repas, rayon de
 * l'enseigne) pour chaque produit accepté (`ACCEPTED`) qui connaît son rayon, sur un ingrédient qui est bien à ce compte, le rayon de Repas
 * étant celui de l'ingrédient. Les refus et « j'en ai toujours » ne votent pas, comme à l'enregistrement d'un choix. Au plus 500 couples par
 * compte et par enseigne (`RayonVote::MAX_PER_USER_AND_RETAILER`, recopié ici : une migration ne dépend pas du code qui la suit).
 *
 * Ce que l'ancien compteur avait retenu d'un choix depuis remplacé ou retiré n'est pas repris : le plan en propose alors moins de rayons
 * tant que le compte ne choisit pas de nouveau, comme pour un rayon jamais appris. L'ancienne table n'est ni lue ni modifiée.
 *
 * Données seulement, additive, rejouable sans doublon (`ON CONFLICT DO NOTHING`), compatible avec la version précédente du code (ADR 0017
 * règle 6). `down` ne supprime rien : les votes sont lus par le code ; la table disparaît avec `Version20261027180000`.
 */
final class Version20261027180100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Votes de départ de drive_rayon_vote, relus des choix enregistrés par les comptes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO drive_rayon_vote (user_id, retailer, department_slug, rayon_id)'
            . ' SELECT ranked.user_id, ranked.retailer, ranked.department_slug, ranked.rayon_id FROM ('
            . 'SELECT voted.*, ROW_NUMBER() OVER (PARTITION BY voted.user_id, voted.retailer ORDER BY voted.department_slug, voted.rayon_id) AS place FROM ('
            . 'SELECT DISTINCT c.user_id, c.retailer, i.department AS department_slug, p.rayon_id'
            . ' FROM drive_choice c'
            . " JOIN drive_choice_product cp ON cp.choice_id = c.id AND cp.verdict = 'ACCEPTED'"
            . ' JOIN drive_product p ON p.id = cp.product_id AND p.retailer = c.retailer AND p.rayon_id > 0'
            . ' JOIN ingredient i ON i.slug = c.ingredient_slug AND i.creator = c.user_id'
            . ') AS voted) AS ranked WHERE ranked.place <= 500'
            . ' ON CONFLICT DO NOTHING',
        );
    }

    public function down(Schema $schema): void
    {
        // Rien n'est supprimé : les votes sont lus par le code, un retour arrière ne les défait pas
        $this->addSql('SELECT 1');
    }
}
