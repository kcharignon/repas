<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correctif de `Version20261027100000` : remettre en base les conversions universelles de référence qui manquent TOUJOURS après elle.
 *
 * Constat de production (2026-10-27, `rapport conversions`) : « centilitre > millilitre x 10 » n'avait pas été recréée. Son identifiant fixe
 * (`8f31ad66-…`) était pris par une AUTRE ligne : l'ancien `PUT /api/v1/ingredients/{slug}` retrouvait la ligne universelle de la paire qu'il
 * enregistrait et la rattachait à l'ingrédient (elle devenait propre à lui), si bien que `ON CONFLICT (id) DO NOTHING` ignorait la ligne neuve.
 *
 * Ici, quand l'identifiant fixe est déjà pris, la ligne universelle neuve prend un identifiant DÉRIVÉ et stable (`md5(identifiant fixe ||
 * ':universelle')` écrit en UUID) au lieu d'être ignorée. La garde ne change pas : une ligne n'est ajoutée que si les DEUX unités existent ET
 * qu'aucune conversion universelle ne relie déjà la paire, dans un sens ou dans l'autre (jamais l'identifiant). Aucune ligne existante n'est
 * touchée : une conversion détournée reste une conversion propre à son ingrédient, on ne devine pas ce que son propriétaire voulait.
 *
 * Données seulement, additive, compatible avec la version précédente du code (ADR 0017 règle 6) ; rejouable sans doublon (la garde sur la paire, et
 * l'identifiant dérivé est le même à chaque fois). `Version20261027100000` n'est pas modifiée : elle est déjà passée en production. `down` ne
 * supprime rien (des données de production que le code lit).
 */
final class Version20261027150000 extends AbstractMigration
{
    /**
     * Les mêmes 13 conversions universelles que `Version20261027100000` : [identifiant, unité de départ, unité d'arrivée, coefficient]. Un test
     * (`UniversalConversionsCorrectionMigrationTest`) les compare à `ReferenceUniversalConversions::LINES`, à la première migration et au fichier
     * `conversion.json` : elles ne divergent pas en silence. Recopiées ici et non importées, une migration reste lisible seule.
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
        return "Remet les conversions universelles de référence qui manquent encore, sous un identifiant dérivé quand l'identifiant fixe est pris par une autre ligne, sans jamais toucher une ligne existante";
    }

    public function up(Schema $schema): void
    {
        $values = implode(', ', array_map(
            static fn(array $row): string => sprintf("('%s', '%s', '%s', %s)", $row[0], $row[1], $row[2], $row[3]),
            self::UNIVERSAL_CONVERSIONS,
        ));

        $this->addSql(
            'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)'
            // Identifiant fixe s'il est libre, sinon un identifiant dérivé et stable de lui
            . " SELECT CASE WHEN EXISTS (SELECT 1 FROM conversion x WHERE x.id = v.id) THEN md5(v.id || ':universelle')::uuid::text ELSE v.id END,"
            . ' v.start_unit, v.end_unit, v.coefficient::double precision, NULL'
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
