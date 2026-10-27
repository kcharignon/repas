<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remettre en base les conversions universelles (celles qui s'appliquent à TOUS les ingrédients : `conversion.ingredient` vide) : « kilo
 * > gramme », « litre > millilitre »… Le propriétaire a vu en production la farine « ne pas savoir convertir le kilo en gramme » : ces
 * lignes sont les données de référence (`DataFixture/Data/conversion.json`), mais rien ne garantissait qu'elles existent
 * dans une base déjà en service. Données seulement, aucun schéma.
 *
 * Une ligne n'est ajoutée que si les DEUX unités existent dans `unit` ET qu'aucune conversion universelle ne relie déjà cette paire, dans
 * un sens ou dans l'autre : une ligne existante n'est JAMAIS écrasée ni doublée, même avec un autre coefficient (c'est peut-être un choix
 * de l'administrateur). L'identifiant est celui du fichier de référence : la migration est rejouable sans doublon (`ON CONFLICT DO NOTHING`
 * pour le cas où cet identifiant serait pris par une autre ligne).
 *
 * Additive et compatible avec la version précédente du code (ADR 0017 règle 6) : une ligne de plus dans une table que l'ancien code lit
 * déjà (`findByIngredientOrCommon`). `down` ne supprime rien : ces lignes sont des données de production, que le code lit ; les retirer
 * casserait des conversions. Il ne se joue qu'à la main, sur décision humaine, et n'écrit que l'instruction neutre.
 */
final class Version20261027100000 extends AbstractMigration
{
    /**
     * Les 13 conversions universelles du fichier de référence : [identifiant, unité de départ, unité d'arrivée, coefficient]. Un test
     * (`UniversalConversionsMigrationTest`) les compare à `conversion.json` : elles ne divergent pas en silence.
     */
    public const array UNIVERSAL_CONVERSIONS = [
        ['743ae730-1643-4aed-b795-4dc06586a9d7', 'litre', 'centilitre', 100],
        ['58db18f7-3c62-421c-9736-0327528de05d', 'litre', 'millilitre', 1000],
        ['8f31ad66-feaf-4c35-a17b-c755d8c1c889', 'centilitre', 'millilitre', 10],
        ['fc0c6751-d668-465f-a2c7-b36fc89c6f55', 'bol', 'centilitre', 35],
        ['fd82c26a-fa4a-4374-b467-a553ca66f78a', 'verre', 'centilitre', 25],
        ['72a354fc-5f7c-403d-93d1-44a6ed4f402c', 'tasse-a-cafe', 'centilitre', 10],
        ['4e2ed30f-0032-430e-9541-635d12ee922a', 'cuillere-a-cafe', 'millilitre', 5],
        ['242e62f9-77d3-4dad-a2ac-dd0fcc1b7f0e', 'cuillere-a-soupe', 'millilitre', 15],
        ['2112ac45-a451-4952-8f9c-ccb9d76c3551', 'kilo', 'gramme', 1000],
        ['19d556b3-1dda-428f-bd0c-1824d63a7d83', 'sachet', 'paquet', 1],
        ['98a13430-218b-42b8-b8b5-fb9711f07f84', 'boite', 'paquet', 1],
        ['d9db6b70-470f-4dba-ad17-b10b230966db', 'conserve', 'boite', 1],
        ['95031205-ac9f-4b3f-9d8b-e648fa931003', 'pot', 'boite', 1],
    ];

    public function getDescription(): string
    {
        return 'Remet les conversions universelles de référence (kilo > gramme, litre > millilitre…) quand elles manquent, sans jamais écraser ni doubler une ligne existante';
    }

    public function up(Schema $schema): void
    {
        $values = implode(', ', array_map(
            static fn(array $row): string => sprintf("('%s', '%s', '%s', %s)", $row[0], $row[1], $row[2], $row[3]),
            self::UNIVERSAL_CONVERSIONS,
        ));

        $this->addSql(
            'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)'
            . ' SELECT v.id, v.start_unit, v.end_unit, v.coefficient::double precision, NULL'
            . " FROM (VALUES $values) AS v (id, start_unit, end_unit, coefficient)"
            . ' WHERE EXISTS (SELECT 1 FROM unit s WHERE s.slug = v.start_unit)'
            . ' AND EXISTS (SELECT 1 FROM unit e WHERE e.slug = v.end_unit)'
            . ' AND NOT EXISTS (SELECT 1 FROM conversion c WHERE c.ingredient IS NULL'
            . ' AND ((c.start_unit = v.start_unit AND c.end_unit = v.end_unit) OR (c.start_unit = v.end_unit AND c.end_unit = v.start_unit)))'
            . ' ON CONFLICT (id) DO NOTHING',
        );
    }

    public function down(Schema $schema): void
    {
        // Rien n'est supprimé : ce sont des données de production que le code lit, un retour arrière ne les défait pas
        $this->addSql('SELECT 1');
    }
}
