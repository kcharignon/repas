<?php

namespace Repas\Repas\Domain\Model;


/**
 * Les conversions UNIVERSELLES de référence (`conversion.ingredient` vide : elles servent à tous les ingrédients) : « kilo > gramme »,
 * « litre > millilitre »… Ce sont les données de référence de `DataFixture/Data/conversion.json` (groupe `prod`) que la migration
 * `Version20261027100000` remet quand elles manquent ; `repas:conversions:check` s'en sert pour dire lesquelles manquent. Un test
 * (`UniversalConversionsMigrationTest`, `ReferenceUniversalConversionsTest`) les compare au fichier et aux migrations : elles ne divergent pas en silence.
 *
 * Seules des données publiques (des unités et un coefficient), jamais rien qui vienne d'un utilisateur.
 */
final class ReferenceUniversalConversions
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: int}> `[identifiant, unité de départ, unité d'arrivée, coefficient]`, dans l'ordre du fichier de référence
     */
    public const array LINES = [
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

    private function __construct()
    {
    }

    /**
     * Les identifiants des lignes de référence : une conversion PROPRE à un ingrédient qui en porte un est une ligne universelle détournée
     * (l'ancien `PUT /api/v1/ingredients/{slug}` la rattachait à l'ingrédient qu'il enregistrait).
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_column(self::LINES, 0);
    }

    /**
     * « début > fin x coefficient », la forme que dit le rapport de santé des conversions.
     */
    public static function label(string $start, string $end, float|int $coefficient): string
    {
        return sprintf('%s > %s x %s', $start, $end, self::number($coefficient));
    }

    /**
     * Un nombre sans zéros inutiles ni notation scientifique (`1000`, `0.001`).
     */
    public static function number(float|int $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
