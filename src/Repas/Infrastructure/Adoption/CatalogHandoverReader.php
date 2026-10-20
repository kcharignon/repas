<?php

namespace Repas\Repas\Infrastructure\Adoption;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Model\CatalogHandoverState;
use Repas\Shared\Application\Interface\DriveIngredientReferences;

/**
 * Lit, en LECTURE SEULE, l'état dont dépendent les préconditions de la passation du catalogue (ING-009, `docs/ingredients/sans-commun.md` §§ 5 et
 * 6) : une douzaine de requêtes quel que soit le nombre d'ingrédients et de lignes, aucun verrou. Partagée par
 * `IngredientCatalogHandoverPostgreSQLRepository` (la commande, qui la relit dans sa transaction) et `CatalogHandoverReportPostgreSQLLoader`
 * (le rapport à blanc), pour qu'elles disent la même chose — comme `AdoptionSnapshotReader` et `SeedingSourceReader`.
 *
 * Les définitions sont celles de la requête de santé de l'amorçage (`IngredientSeedingReportPostgreSQLLoader`) : « amorcé » = au moins un alias
 * d'adoption, « a des ingrédients » = au moins un ingrédient dont il est le créateur. Les choix du drive passent par le port `Shared` (le contexte
 * `Drive` garde ses tables), comptés par compte et par ingrédient.
 */
final readonly class CatalogHandoverReader
{
    /** Le slug d'un ingrédient personnel ou copié finit par l'identifiant d'un compte (`StringTool::slugify($nom . $identifiant)`). */
    public const string ACCOUNT_SLUG_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DriveIngredientReferences $driveReferences,
    ) {
    }

    /**
     * @param array<string, bool> $classification
     */
    public function read(?string $ownerId, array $classification): CatalogHandoverState
    {
        $connection = $this->connection();
        $owner = $ownerId ?? '';
        $admin = 'roles::jsonb @> CAST(\'["ROLE_ADMIN"]\' AS jsonb)';

        $ownerRow = $ownerId === null ? false : $connection->fetchAssociative('SELECT status, (' . $admin . ') AS administrator FROM "user" WHERE id = ?', [$ownerId]);
        $administrators = (int) $connection->fetchOne('SELECT count(*) FROM "user" WHERE ' . $admin);

        // Les ingrédients sans créateur : leur marqueur, leur origine, la forme de leur slug
        $withoutCreator = $connection->fetchAllNumeric(
            'SELECT slug, is_base, adopted_from IS NOT NULL, slug ~ ? FROM ingredient WHERE creator IS NULL ORDER BY slug COLLATE "C"',
            [self::ACCOUNT_SLUG_PATTERN],
        );
        $markers = [];
        foreach ($withoutCreator as [$slug, $isBase]) {
            $markers[(string) $slug] = (bool) $isBase;
        }
        $conversions = (int) $connection->fetchOne('SELECT count(*) FROM conversion v JOIN ingredient c ON c.slug = v.ingredient WHERE c.creator IS NULL');

        // Parmi ceux du fichier : ceux qui sont déjà au propriétaire (anciens communs, donc sans origine)
        $atOwner = [];
        if ($ownerId !== null && $classification !== []) {
            foreach ($connection->fetchAllNumeric(
                'SELECT slug, is_base FROM ingredient WHERE creator = ? AND adopted_from IS NULL AND slug IN (?) ORDER BY slug COLLATE "C"',
                [$ownerId, array_keys($classification)],
                [1 => ArrayParameterType::STRING],
            ) as [$slug, $isBase]) {
                $atOwner[(string) $slug] = (bool) $isBase;
            }
        }

        // Les lignes des autres comptes sur un ingrédient sans créateur (P2), par compte et par table
        $foreign = [];
        foreach ($connection->fetchAllNumeric(
            'SELECT t, u, count(*) FROM ('
            . "   SELECT 'recettes' AS t, r.author AS u FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NULL AND r.author <> :owner"
            . "   UNION ALL SELECT 'lignes de liste', l.owner FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NULL AND l.owner <> :owner"
            . "   UNION ALL SELECT 'ingrédients de liste', l.owner FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NULL AND l.owner <> :owner"
            . ') s GROUP BY t, u ORDER BY u, t',
            ['owner' => $owner],
        ) as [$table, $accountId, $count]) {
            $foreign[(string) $accountId][(string) $table] = (int) $count;
        }

        // Les lignes du propriétaire sur l'ingrédient personnel d'un autre compte (P7)
        $ownerOnOthers = $connection->fetchAllKeyValue(
            'SELECT t, n FROM ('
            . "   SELECT 'recettes' AS t, count(*) AS n FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE r.author = :owner AND i.creator IS NOT NULL AND i.creator <> :owner"
            . "   UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = :owner AND i.creator IS NOT NULL AND i.creator <> :owner"
            . "   UNION ALL SELECT 'ingrédients de liste', count(*) FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE l.owner = :owner AND i.creator IS NOT NULL AND i.creator <> :owner"
            . ') s WHERE n > 0',
            ['owner' => $owner],
        );

        // Les comptes actifs, hors propriétaire, qui n'ont pas leurs ingrédients (P3)
        $notSeeded = $withoutIngredient = [];
        foreach ($connection->fetchAllNumeric(
            'SELECT u.id,'
            . " EXISTS (SELECT 1 FROM ingredient_alias a WHERE a.user_id = u.id AND a.kind = 'adoption'),"
            . ' EXISTS (SELECT 1 FROM ingredient i WHERE i.creator = u.id)'
            . " FROM \"user\" u WHERE u.status = 'ACTIVE' AND u.id <> :owner ORDER BY u.id",
            ['owner' => $owner],
        ) as [$accountId, $seeded, $hasIngredient]) {
            if (!$seeded) {
                $notSeeded[] = (string) $accountId;
            }
            if (!$hasIngredient) {
                $withoutIngredient[] = (string) $accountId;
            }
        }

        // Les références orphelines (P6) : aucun ingrédient pour ce slug
        $orphans = $connection->fetchAllKeyValue(
            'SELECT t, n FROM ('
            . "   SELECT 'recettes' AS t, count(*) AS n FROM recipe_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)"
            . "   UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)"
            . "   UNION ALL SELECT 'ingrédients de liste', count(*) FROM shopping_list_ingredient x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)"
            . "   UNION ALL SELECT 'conversions', count(*) FROM conversion x WHERE x.ingredient IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)"
            . ') s WHERE n > 0',
        );

        // Les choix du drive (le contexte `Drive` garde ses tables) : orphelins, sur un ingrédient sans créateur (autres comptes), sur l'ingrédient d'un autre (propriétaire)
        $driveSlugs = $this->driveReferences->referencedSlugsOfEveryUser();
        $creators = $driveSlugs === [] ? [] : $connection->fetchAllKeyValue(
            'SELECT slug, creator FROM ingredient WHERE slug IN (?)',
            [array_values(array_unique(array_merge(...array_values($driveSlugs))))],
            [ArrayParameterType::STRING],
        );
        $driveOrphans = $driveOwnerOnOthers = 0;
        foreach ($driveSlugs as $accountId => $slugs) {
            foreach ($slugs as $slug) {
                if (!array_key_exists($slug, $creators)) {
                    ++$driveOrphans;
                } elseif ($creators[$slug] === null && $accountId !== $owner) {
                    $foreign[$accountId][CatalogHandoverState::DRIVE_CHOICES] = ($foreign[$accountId][CatalogHandoverState::DRIVE_CHOICES] ?? 0) + 1;
                } elseif ($creators[$slug] !== null && $creators[$slug] !== $accountId && $accountId === $owner) {
                    ++$driveOwnerOnOthers;
                }
            }
        }
        if ($driveOrphans > 0) {
            $orphans[CatalogHandoverState::DRIVE_CHOICES] = $driveOrphans;
        }
        if ($driveOwnerOnOthers > 0) {
            $ownerOnOthers[CatalogHandoverState::DRIVE_CHOICES] = $driveOwnerOnOthers;
        }
        ksort($foreign);

        return new CatalogHandoverState(
            ownerId: $ownerId,
            ownerStatus: $ownerRow === false ? null : (string) $ownerRow['status'],
            ownerIsAdministrator: $ownerRow !== false && (bool) $ownerRow['administrator'],
            administrators: $administrators,
            slugsWithoutCreator: array_map('strval', array_keys($markers)),
            markersWithoutCreator: $markers,
            withoutCreatorWithOrigin: count(array_filter($withoutCreator, static fn(array $row): bool => (bool) $row[2])),
            withoutCreatorAccountFormat: count(array_filter($withoutCreator, static fn(array $row): bool => (bool) $row[3])),
            ownConversionsWithoutCreator: $conversions,
            fileSlugsAtOwner: $atOwner,
            foreignLinesByAccount: $foreign,
            ownerLinesOnOthers: array_map('intval', $ownerOnOthers),
            activeNotSeeded: $notSeeded,
            activeWithoutIngredient: $withoutIngredient,
            orphans: array_map('intval', $orphans),
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
