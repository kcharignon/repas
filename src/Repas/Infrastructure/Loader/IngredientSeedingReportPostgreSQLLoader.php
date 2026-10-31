<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\IngredientSeedingReportLoader;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingHealthDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingReportDTO;
use Repas\Repas\Application\Query\GetSeedingReport\SeedingUserReportDTO;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\IngredientToSeed;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Infrastructure\Adoption\AdoptionSnapshotReader;
use Repas\Repas\Infrastructure\Adoption\SeedingSourceReader;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Repas\Application\Port\AccountDirectoryQueryPort;
use Repas\Repas\Application\Port\CatalogOwnerQueryPort;

/**
 * Le rapport « à blanc » de l'amorçage des comptes (ING-008, `docs/ingredients/sans-commun.md` §§ 2 et 5), en LECTURE SEULE : jamais
 * d'`INSERT`, d'`UPDATE` ni de `DELETE`, aucun verrou. Il dit, pour chaque compte retenu, ce qui serait copié (ingrédients, collisions de slug,
 * conversions) et repointé (lignes de recettes et de listes dont les listes envoyées, choix du drive), cherche les anomalies, calcule
 * l'empreinte du contenu — et répond à la requête de SANTÉ (`--check`) : plus de compte actif sans amorçage ni sans ingrédient, plus de ligne
 * sur l'ingrédient d'un autre compte. Il sert donc aussi de vérification d'après lancement.
 *
 * Il lit la source par `SeedingSourceReader`, le même code que l'écriture : le rapport dit ce que la commande fera. Aucune adresse, aucun nom
 * d'ingrédient personnel : les comptes sont numérotés et désignés par une référence courte (SHA-256).
 */
final readonly class IngredientSeedingReportPostgreSQLLoader implements IngredientSeedingReportLoader
{
    private const int MAX_SLUG_LENGTH = 255;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdoptionSnapshotReader $snapshotReader,
        private SeedingSourceReader $sourceReader,
        private IngredientSlugPolicy $slugPolicy,
        private DriveIngredientReferences $driveReferences,
        private CatalogOwnerQueryPort $catalogOwner,
        private AccountDirectoryQueryPort $directory,
    ) {
    }

    public function report(array $only, array $except, bool $complete): SeedingReportDTO
    {
        $connection = $this->connection();
        $ownerId = $this->catalogOwner->ownerId();
        // Le catalogue : ce qu'on copie, jamais modifié par l'amorçage. Sans propriétaire (`null`), le catalogue est vide.
        [$catalog, $base, $longest, $conversions] = $connection->fetchNumeric(
            'SELECT count(*), count(*) FILTER (WHERE ' . IngredientVisibility::isBaseCatalogSql('c', 'owner') . '),'
            . ' coalesce(max(length(c.slug)) FILTER (WHERE ' . IngredientVisibility::isBaseCatalogSql('c', 'owner') . '), 0),'
            . ' (SELECT count(*) FROM conversion v JOIN ingredient b ON b.slug = v.ingredient WHERE ' . IngredientVisibility::isBaseCatalogSql('b', 'owner') . ')'
            . ' FROM ingredient c WHERE ' . IngredientVisibility::isCatalogSql('c', 'owner'),
            ['owner' => $ownerId],
        );

        // Les comptes (identifiant et statut, par identifiant) viennent du contexte `User`. Ce que chacun possède se compte ici, sur nos tables,
        // en trois lectures groupées quel que soit le nombre de comptes : les ingrédients par créateur, les recettes par auteur, les comptes amorcés.
        $statuses = $this->directory->statusesById();
        /** @var array<string, int|string> $ingredientsByCreator */
        $ingredientsByCreator = $connection->fetchAllKeyValue('SELECT creator, count(*) FROM ingredient GROUP BY creator');
        /** @var array<string, int|string> $recipesByAuthor */
        $recipesByAuthor = $connection->fetchAllKeyValue('SELECT author, count(*) FROM recipe GROUP BY author');
        $seededAccounts = array_fill_keys($connection->fetchFirstColumn("SELECT DISTINCT user_id FROM ingredient_alias WHERE kind = 'adoption'"), true);

        $accountIds = array_map('strval', array_keys($statuses));
        $matches = static fn(array $selectors, string $id): bool => in_array($id, $selectors, true) || in_array(self::referenceOf($id), $selectors, true);
        $known = $accountIds === [] ? [] : array_merge(...array_map(static fn(string $id): array => [$id, self::referenceOf($id)], $accountIds));
        $unknown = array_values(array_unique(array_diff([...$only, ...$except], $known)));

        $users = [];
        foreach ($accountIds as $index => $id) {
            $status = (string) $statuses[$id];
            $seeded = isset($seededAccounts[$id]);
            $exclusion = match (true) {
                $id === $ownerId => SeedingUserReportDTO::OWNER,
                $matches($except, $id) => SeedingUserReportDTO::EXCEPTED,
                $only !== [] && !$matches($only, $id) => SeedingUserReportDTO::NOT_SELECTED,
                $only === [] && $status !== AccountDirectoryQueryPort::STATUS_ACTIVE => SeedingUserReportDTO::NOT_ACTIVE,
                $seeded && !($complete && $only !== []) => SeedingUserReportDTO::ALREADY_SEEDED,
                default => null,
            };

            $identity = [
                'rank' => $index + 1,
                'reference' => self::referenceOf($id),
                'userId' => $id,
                'status' => $status,
                'owner' => $id === $ownerId,
                'seeded' => $seeded,
                'ingredients' => (int) ($ingredientsByCreator[$id] ?? 0),
                'recipes' => (int) ($recipesByAuthor[$id] ?? 0),
                'selected' => $exclusion === null,
                'exclusion' => $exclusion,
            ];

            $users[] = $exclusion === null ? $this->detail($id, $ownerId, $complete, $identity) : new SeedingUserReportDTO(...$identity);
        }

        $catalogAnomalies = [];
        if ($ownerId === null) {
            $catalogAnomalies[] = "le propriétaire du catalogue n'existe pas : rien ne peut être copié";
        }
        if ((int) $connection->fetchOne('SELECT count(*) FROM conversion x WHERE x.ingredient IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)') > 0) {
            $catalogAnomalies[] = "des conversions sont rattachées à un ingrédient qui n'existe pas";
        }

        return new SeedingReportDTO(
            catalogOwnerExists: $ownerId !== null,
            catalogIngredients: (int) $catalog,
            baseIngredients: (int) $base,
            baseOwnConversions: (int) $conversions,
            longestBaseSlug: (int) $longest,
            catalogAnomalies: $catalogAnomalies,
            unknownSelectors: $unknown,
            users: $users,
            health: $this->health($ownerId, $statuses, $ingredientsByCreator, $seededAccounts),
        );
    }

    /**
     * Le détail d'un compte retenu.
     *
     * @param array{rank: int, reference: string, userId: string, status: string, owner: bool, seeded: bool, ingredients: int, recipes: int, selected: bool, exclusion: ?string} $base
     */
    private function detail(string $userId, ?string $ownerId, bool $complete, array $base): SeedingUserReportDTO
    {
        $connection = $this->connection();
        $anomalies = [];

        $toSeed = [];
        if ($ownerId === null) {
            $anomalies[] = "le propriétaire du catalogue n'existe pas : rien ne peut être copié";
        } else {
            $toSeed = $this->sourceReader->read($userId, $ownerId, $this->driveReferences->referencedSlugs($userId));
            if (!$complete && array_filter($toSeed, static fn(IngredientToSeed $ingredient): bool => $ingredient->isBase) === []) {
                // Le classement n'est pas posé : l'amorçage n'écrirait rien et le compte resterait non amorcé (voir le handler)
                $anomalies[] = "aucun ingrédient « de base » dans le catalogue : l'amorçage n'écrirait rien et le compte resterait non amorcé";
                $toSeed = [];
            }
        }
        // `--complete` répare aussi : les lignes restées sur l'origine d'une adoption que le compte a déjà sont repointées (comme le fait le handler)
        $adoptedOrigins = $complete ? $connection->fetchFirstColumn(
            'SELECT a.alias_slug FROM ingredient_alias a JOIN ingredient own ON own.slug = a.ingredient_slug AND own.creator = a.user_id'
            . " WHERE a.user_id = ? AND a.kind = 'adoption' ORDER BY a.alias_slug",
            [$userId],
        ) : [];
        $sourceSlugs = array_values(array_unique([...array_map(static fn(IngredientToSeed $ingredient): string => $ingredient->slug, $toSeed), ...$adoptedOrigins]));

        // Les slugs des copies, par la politique de domaine (jamais une formule SQL) : collisions et longueurs
        $taken = array_fill_keys($connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE slug LIKE ?', ['%' . addcslashes($userId, '%_\\')]), true);
        $collisions = $tooLong = $reusable = 0;
        $copied = [];
        foreach ($toSeed as $ingredient) {
            if ($ingredient->existingCopySlug !== null) {
                ++$reusable;
                continue;
            }
            $target = $this->slugPolicy->forAdoption($ingredient->name, $userId, static fn(string $candidate): bool => isset($taken[$candidate]));
            $taken[$target] = true;
            $collisions += $target === $this->slugPolicy->forAdoption($ingredient->name, $userId, []) ? 0 : 1;
            $tooLong += strlen($target) > self::MAX_SLUG_LENGTH ? 1 : 0;
            $copied[] = $ingredient->slug;
        }
        if ($tooLong > 0) {
            $anomalies[] = sprintf('%d slug(s) de copie dépasseraient 255 caractères', $tooLong);
        }
        $conversionsToCopy = $copied === [] ? 0 : (int) $connection->fetchOne('SELECT count(*) FROM conversion WHERE ingredient IN (?)', [$copied], [ArrayParameterType::STRING]);

        // Ce qui serait repointé : les lignes qui référencent un ingrédient à copier, par table, et les choix du drive
        $snapshot = $this->snapshotReader->read($userId, $sourceSlugs);
        $drive = $this->driveReferences->fingerprint($userId);
        $choiceIdsOnSources = array_keys(array_filter($drive->ingredientSlugByChoice, static fn(string $slug): bool => in_array($slug, $sourceSlugs, true)));

        array_push($anomalies, ...$this->anomalies($userId, $ownerId, $drive->ingredientSlugByChoice));

        return new SeedingUserReportDTO(
            ...$base,
            toSeed: count($toSeed),
            toSeedBase: count(array_filter($toSeed, static fn(IngredientToSeed $ingredient): bool => $ingredient->isBase)),
            toSeedReferenced: count(array_filter($toSeed, static fn(IngredientToSeed $ingredient): bool => !$ingredient->isBase)),
            reusable: $reusable,
            slugCollisions: $collisions,
            conversionsToCopy: $conversionsToCopy,
            recipeRowsToRepoint: $snapshot->watchedRowCounts[AdoptionSnapshot::RECIPE_ROW] ?? 0,
            recipesToRepoint: $snapshot->recipesToRepoint(),
            shoppingListRowsToRepoint: $snapshot->watchedRowCounts[AdoptionSnapshot::SHOPPING_LIST_ROW] ?? 0,
            shoppingListIngredientsToRepoint: $snapshot->watchedRowCounts[AdoptionSnapshot::SHOPPING_LIST_INGREDIENT] ?? 0,
            shoppingListsToRepoint: $snapshot->shoppingListsToRepoint(),
            sentShoppingListsToRepoint: $snapshot->sentShoppingListsToRepoint(),
            driveChoicesToRepoint: count($choiceIdsOnSources),
            driveProductsOfChoicesToRepoint: array_sum(array_map(static fn(string $id): int => $drive->productCountByChoice[$id] ?? 0, $choiceIdsOnSources)),
            driveChoices: $drive->choices,
            driveProducts: $drive->products,
            fingerprint: $snapshot->contentFingerprint(),
            blockingAnomalies: $anomalies,
        );
    }

    /**
     * Les anomalies qui interdisent d'écrire : une ligne dont l'ingrédient n'existe pas (l'orphelin n'est pas touché), une ligne qui référence
     * l'ingrédient PERSONNEL d'un autre utilisateur que le compte et que le propriétaire du catalogue (donnée héritée d'avant l'ADR 0021).
     * Des comptages par table, jamais un identifiant ni un nom.
     *
     * @param array<string, string> $driveSlugByChoice
     *
     * @return list<string>
     */
    private function anomalies(string $userId, ?string $ownerId, array $driveSlugByChoice): array
    {
        $connection = $this->connection();
        $sources = [
            'recettes' => 'FROM recipe_row x JOIN recipe o ON o.id = x.recipe AND o.author = :u',
            'lignes de liste' => 'FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list AND o.owner = :u',
            'ingrédients de liste' => 'FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list AND o.owner = :u',
        ];

        $anomalies = [];
        foreach ($sources as $label => $from) {
            $orphans = (int) $connection->fetchOne("SELECT count(*) $from WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)", ['u' => $userId]);
            $crossed = (int) $connection->fetchOne(
                "SELECT count(*) $from JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NOT NULL AND i.creator <> :u AND i.creator <> :owner",
                ['u' => $userId, 'owner' => $ownerId ?? ''],
            );
            if ($orphans > 0) {
                $anomalies[] = sprintf("%s : %d ligne(s) dont l'ingrédient n'existe pas", $label, $orphans);
            }
            if ($crossed > 0) {
                $anomalies[] = sprintf("%s : %d ligne(s) sur l'ingrédient personnel d'un autre compte", $label, $crossed);
            }
        }

        if ($driveSlugByChoice !== []) {
            $creators = $connection->fetchAllKeyValue('SELECT slug, creator FROM ingredient WHERE slug IN (?)', [array_values(array_unique($driveSlugByChoice))], [ArrayParameterType::STRING]);
            $missing = $crossed = 0;
            foreach ($driveSlugByChoice as $slug) {
                if (!array_key_exists($slug, $creators)) {
                    ++$missing;
                } elseif ($creators[$slug] !== null && $creators[$slug] !== $userId && $creators[$slug] !== $ownerId) {
                    ++$crossed;
                }
            }
            if ($missing > 0) {
                $anomalies[] = sprintf("choix du drive : %d dont l'ingrédient n'existe pas", $missing);
            }
            if ($crossed > 0) {
                $anomalies[] = sprintf("choix du drive : %d sur l'ingrédient personnel d'un autre compte", $crossed);
            }
        }

        return $anomalies;
    }

    /**
     * La requête de santé (`docs/ingredients/sans-commun.md` § 5) : plus de filet, donc un compte actif sans ses ingrédients serait aveugle.
     *
     * Les comptes viennent du contexte `User` (un statut par identifiant) ; les ingrédients par créateur et les comptes amorcés sont ceux que
     * le rapport vient de lire : aucune lecture de plus.
     *
     * @param array<string, string> $statuses le statut de chaque compte, par identifiant
     * @param array<string, int|string> $ingredientsByCreator le nombre d'ingrédients par créateur (clé vide : sans créateur)
     * @param array<string, true> $seededAccounts les comptes qui ont au moins un alias d'adoption
     */
    private function health(?string $ownerId, array $statuses, array $ingredientsByCreator, array $seededAccounts): SeedingHealthDTO
    {
        $connection = $this->connection();
        $owner = $ownerId ?? '';
        // Un ingrédient est toujours à quelqu'un : ceux dont le créateur n'est aucun compte existant (créateur vide compris) sont une anomalie
        $withoutCreator = 0;
        foreach ($ingredientsByCreator as $creator => $count) {
            $withoutCreator += isset($statuses[$creator]) ? 0 : (int) $count;
        }
        // Le propriétaire du catalogue entre dans les contrôles 2 et 4 dès qu'il existe (la passation est faite)
        $ownerIncluded = $ownerId !== null;

        $notSeeded = $withoutIngredient = 0;
        foreach ($statuses as $id => $status) {
            if ($status !== AccountDirectoryQueryPort::STATUS_ACTIVE) {
                continue;
            }
            $id = (string) $id;
            $notSeeded += $id !== $owner && !isset($seededAccounts[$id]) ? 1 : 0;
            $withoutIngredient += ($id !== $owner || $ownerIncluded) && (int) ($ingredientsByCreator[$id] ?? 0) === 0 ? 1 : 0;
        }

        $foreign = static fn(string $account): string => sprintf("COALESCE(i.creator, '') <> %s", $account);
        $lines = $connection->fetchAllKeyValue(
            'SELECT t, n FROM ('
            . "   SELECT 'recettes' AS t, count(*) AS n FROM recipe_row x JOIN recipe o ON o.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE o.author <> :owner AND " . $foreign('o.author')
            . "   UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner <> :owner AND " . $foreign('o.owner')
            . "   UNION ALL SELECT 'ingrédients de liste', count(*) FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner <> :owner AND " . $foreign('o.owner')
            . ') s WHERE n > 0',
            ['owner' => $owner],
        );
        $ownerLines = [];
        if ($ownerIncluded) {
            $ownerLines = $connection->fetchAllKeyValue(
                'SELECT t, n FROM ('
                . "   SELECT 'recettes' AS t, count(*) AS n FROM recipe_row x JOIN recipe o ON o.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient WHERE o.author = :owner AND " . $foreign('o.author')
                . "   UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :owner AND " . $foreign('o.owner')
                . "   UNION ALL SELECT 'ingrédients de liste', count(*) FROM shopping_list_ingredient x JOIN shopping_list o ON o.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient WHERE o.owner = :owner AND " . $foreign('o.owner')
                . ') s WHERE n > 0',
                ['owner' => $owner],
            );
        }

        // Les choix du drive : le contexte `Drive` garde ses tables, les slugs viennent de son port
        $driveForeign = $driveOwnerForeign = 0;
        $driveSlugs = $this->driveReferences->referencedSlugsOfEveryUser();
        $creators = $driveSlugs === [] ? [] : $connection->fetchAllKeyValue(
            'SELECT slug, creator FROM ingredient WHERE slug IN (?)',
            [array_values(array_unique(array_merge(...array_values($driveSlugs))))],
            [ArrayParameterType::STRING],
        );
        foreach ($driveSlugs as $userId => $slugs) {
            if (!isset($statuses[$userId])) {
                continue;
            }
            foreach ($slugs as $slug) {
                $isForeign = ($creators[$slug] ?? null) !== $userId;
                if ($userId === $ownerId) {
                    $driveOwnerForeign += $ownerIncluded && $isForeign ? 1 : 0;
                } else {
                    $driveForeign += $isForeign ? 1 : 0;
                }
            }
        }
        if ($driveForeign > 0) {
            $lines['choix du drive'] = $driveForeign;
        }
        if ($driveOwnerForeign > 0) {
            $ownerLines['choix du drive'] = $driveOwnerForeign;
        }

        return new SeedingHealthDTO(
            activeNotSeeded: $notSeeded,
            activeWithoutIngredient: $withoutIngredient,
            foreignLines: array_map('intval', $lines),
            ownerForeignLines: $ownerIncluded ? array_map('intval', $ownerLines) : null,
            ingredientsWithoutCreator: $withoutCreator,
        );
    }

    /**
     * La référence courte d'un compte dans les rapports : les 8 premiers caractères du SHA-256 de son identifiant (la même que celle du rapport
     * de la migration d'adoption, ING-003).
     */
    public static function referenceOf(string $userId): string
    {
        return substr(hash('sha256', $userId), 0, 8);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
