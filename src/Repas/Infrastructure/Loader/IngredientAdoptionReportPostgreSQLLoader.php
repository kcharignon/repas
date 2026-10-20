<?php

namespace Repas\Repas\Infrastructure\Loader;


use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Application\Loader\IngredientAdoptionReportLoader;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionReportDTO;
use Repas\Repas\Application\Query\GetAdoptionReport\AdoptionUserReportDTO;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Service\IngredientSlugPolicy;
use Repas\Repas\Infrastructure\Adoption\AdoptionSnapshotReader;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;
use Repas\Shared\Application\Interface\DriveIngredientReferences;

/**
 * Le rapport « à blanc » de la migration d'adoption des communs (ING-003, `docs/ingredients/migration.md` §§ 6 et 8), en LECTURE SEULE :
 * jamais d'`INSERT`, d'`UPDATE` ni de `DELETE`, aucun verrou. Il compte, pour chaque compte concerné, ce qui serait copié (communs,
 * collisions de slug, conversions) et repointé (lignes de recettes et de listes dont les listes envoyées, choix du drive), cherche les
 * anomalies (références orphelines ou croisées, slug trop long) et calcule l'empreinte du contenu.
 *
 * Il sert aussi de `--check` d'après lancement : « rien à adopter, rien à repointer, aucun alias invalide ».
 *
 * Aucune adresse, aucun nom d'ingrédient personnel : les comptes sont numérotés et désignés par une référence courte (SHA-256).
 */
final readonly class IngredientAdoptionReportPostgreSQLLoader implements IngredientAdoptionReportLoader
{
    private const int MAX_SLUG_LENGTH = 255;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdoptionSnapshotReader $snapshotReader,
        private IngredientSlugPolicy $slugPolicy,
        private DriveIngredientReferences $driveReferences,
    ) {
    }

    public function report(array $only, array $except): AdoptionReportDTO
    {
        $connection = $this->connection();

        // Le catalogue des communs : jamais modifié par la migration
        $commons = [];
        $ownConversions = [];
        foreach ($connection->fetchAllNumeric(
            'SELECT c.slug, c.name, (SELECT count(*) FROM conversion v WHERE v.ingredient = c.slug) FROM ingredient c WHERE ' . IngredientVisibility::isCommonSql('c') . ' ORDER BY c.slug',
        ) as [$slug, $name, $conversions]) {
            $commons[$slug] = $name;
            $ownConversions[$slug] = (int) $conversions;
        }
        $orphanConversions = (int) $connection->fetchOne(
            'SELECT count(*) FROM conversion x WHERE x.ingredient IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)',
        );

        $accounts = $connection->fetchAllAssociative(
            'SELECT u.id, u.status, (u.roles::jsonb @> CAST(\'["ROLE_ADMIN"]\' AS jsonb)) AS administrator,'
            . ' (SELECT count(*) FROM ingredient i WHERE i.creator = u.id AND i.adopted_from IS NULL) AS personal,'
            . ' (SELECT count(*) FROM ingredient i WHERE i.creator = u.id AND i.adopted_from IS NOT NULL) AS copies,'
            . ' (SELECT count(*) FROM recipe r WHERE r.author = u.id) AS recipes,'
            . ' (SELECT count(*) FROM recipe r WHERE r.author = u.id AND r.orignal IS NOT NULL) AS copied_recipes'
            . ' FROM "user" u ORDER BY u.id',
        );

        $matches = static fn(array $selectors, string $id): bool => in_array($id, $selectors, true) || in_array(self::referenceOf($id), $selectors, true);
        $known = array_merge(...array_map(static fn(array $account): array => [$account['id'], self::referenceOf($account['id'])], $accounts));
        $unknown = array_values(array_unique(array_diff([...$only, ...$except], $known)));

        $users = [];
        foreach ($accounts as $index => $account) {
            $id = $account['id'];
            $administrator = (bool) $account['administrator'];
            $concerned = !$administrator && ((int) $account['personal'] + (int) $account['copies'] + (int) $account['recipes']) > 0;
            $exclusion = match (true) {
                $administrator => AdoptionUserReportDTO::ADMINISTRATOR,
                !$concerned => AdoptionUserReportDTO::NOTHING_OWNED,
                $matches($except, $id) => AdoptionUserReportDTO::EXCEPTED,
                $only !== [] && !$matches($only, $id) => AdoptionUserReportDTO::NOT_SELECTED,
                default => null,
            };
            $selected = $concerned && $exclusion === null;

            $base = [
                'rank' => $index + 1,
                'reference' => self::referenceOf($id),
                'userId' => $id,
                'status' => (string) $account['status'],
                'administrator' => $administrator,
                'personalIngredients' => (int) $account['personal'],
                'adoptedCopies' => (int) $account['copies'],
                'recipes' => (int) $account['recipes'],
                'copiedRecipes' => (int) $account['copied_recipes'],
                'concerned' => $concerned,
                'selected' => $selected,
                'exclusion' => $exclusion,
            ];

            $users[] = $selected ? $this->detail($id, $base, $commons, $ownConversions) : new AdoptionUserReportDTO(...$base);
        }

        return new AdoptionReportDTO(
            commons: count($commons),
            commonOwnConversions: array_sum($ownConversions),
            longestCommonSlug: $commons === [] ? 0 : max(array_map('strlen', array_keys($commons))),
            catalogAnomalies: $orphanConversions > 0 ? [sprintf("%d conversion(s) rattachée(s) à un ingrédient qui n'existe pas", $orphanConversions)] : [],
            unknownSelectors: $unknown,
            users: $users,
        );
    }

    /**
     * Le détail d'un compte concerné et retenu.
     *
     * @param array{rank: int, reference: string, userId: string, status: string, administrator: bool, personalIngredients: int, adoptedCopies: int, recipes: int, copiedRecipes: int, concerned: bool, selected: bool, exclusion: ?string} $base
     * @param array<string, string> $commons nom par slug de commun
     * @param array<string, int> $ownConversions
     */
    private function detail(string $userId, array $base, array $commons, array $ownConversions): AdoptionUserReportDTO
    {
        $connection = $this->connection();
        $anomalies = [];

        // Les communs déjà adoptés (alias d'adoption dont la copie existe et est au compte) et ceux à adopter
        $adopted = array_flip($connection->fetchFirstColumn(
            'SELECT a.alias_slug FROM ingredient_alias a JOIN ingredient own ON own.slug = a.ingredient_slug AND own.creator = a.user_id'
            . " WHERE a.user_id = ? AND a.kind = 'adoption'",
            [$userId],
        ));
        $toAdopt = array_diff_key($commons, $adopted);

        // Les slugs des copies, par la politique de domaine (jamais une formule SQL) : collisions et longueurs
        $taken = array_fill_keys($connection->fetchFirstColumn('SELECT slug FROM ingredient WHERE slug LIKE ?', ['%' . addcslashes($userId, '%_\\')]), true);
        $collisions = [];
        $tooLong = 0;
        $conversionsToCopy = 0;
        foreach ($toAdopt as $slug => $name) {
            $target = $this->slugPolicy->forAdoption($name, $userId, static fn(string $candidate): bool => isset($taken[$candidate]));
            $taken[$target] = true;
            if ($target !== $this->slugPolicy->forAdoption($name, $userId, [])) {
                $collisions[] = $name;
            }
            $tooLong += strlen($target) > self::MAX_SLUG_LENGTH ? 1 : 0;
            $conversionsToCopy += $ownConversions[$slug];
        }
        if ($tooLong > 0) {
            $anomalies[] = sprintf('%d slug(s) de copie dépasseraient 255 caractères', $tooLong);
        }

        // Ce qui serait repointé : les lignes qui référencent un commun, par table, et les choix du drive
        $snapshot = $this->snapshotReader->read($userId);
        $drive = $this->driveReferences->fingerprint($userId);
        $choiceIdsOnCommons = array_keys(array_filter($drive->ingredientSlugByChoice, static fn(string $slug): bool => isset($commons[$slug])));

        array_push($anomalies, ...$this->anomalies($userId, $drive->ingredientSlugByChoice));

        $invalidAliases = (int) $connection->fetchOne(
            "SELECT count(*) FROM ingredient_alias a WHERE a.user_id = ? AND a.kind = 'adoption' AND NOT ("
            . ' EXISTS (SELECT 1 FROM ingredient own WHERE own.slug = a.ingredient_slug AND own.creator = a.user_id AND own.adopted_from = a.alias_slug)'
            . ' AND EXISTS (SELECT 1 FROM ingredient c WHERE c.slug = a.alias_slug AND ' . IngredientVisibility::isCommonSql('c') . '))',
            [$userId],
        );

        return new AdoptionUserReportDTO(
            ...$base,
            toAdopt: count($toAdopt),
            alreadyAdopted: count($adopted),
            slugCollisions: count($collisions),
            collidingCommons: $collisions,
            conversionsToCopy: $conversionsToCopy,
            recipeRowsToRepoint: $snapshot->watchedRowCounts[AdoptionSnapshot::RECIPE_ROW] ?? 0,
            recipesToRepoint: $snapshot->recipesToRepoint(),
            shoppingListRowsToRepoint: $snapshot->watchedRowCounts[AdoptionSnapshot::SHOPPING_LIST_ROW] ?? 0,
            shoppingListIngredientsToRepoint: $snapshot->watchedRowCounts[AdoptionSnapshot::SHOPPING_LIST_INGREDIENT] ?? 0,
            shoppingListsToRepoint: $snapshot->shoppingListsToRepoint(),
            sentShoppingListsToRepoint: $snapshot->sentShoppingListsToRepoint(),
            driveChoicesToRepoint: count($choiceIdsOnCommons),
            driveProductsOfChoicesToRepoint: array_sum(array_map(static fn(string $id): int => $drive->productCountByChoice[$id] ?? 0, $choiceIdsOnCommons)),
            driveChoices: $drive->choices,
            driveProducts: $drive->products,
            fingerprint: $snapshot->contentFingerprint(),
            blockingAnomalies: $anomalies,
            invalidAliases: $invalidAliases,
        );
    }

    /**
     * Les anomalies qui interdisent d'écrire (`migration.md` § 8, « avant ») : une ligne dont l'ingrédient n'existe pas (l'orphelin n'est
     * pas touché), une ligne qui référence l'ingrédient PERSONNEL d'un autre utilisateur (donnée héritée d'avant l'ADR 0021). Des
     * comptages par table, jamais un identifiant ni un nom.
     *
     * @param array<string, string> $driveSlugByChoice
     *
     * @return list<string>
     */
    private function anomalies(string $userId, array $driveSlugByChoice): array
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
            $crossed = (int) $connection->fetchOne("SELECT count(*) $from JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NOT NULL AND i.creator <> :u", ['u' => $userId]);
            if ($orphans > 0) {
                $anomalies[] = sprintf("%s : %d ligne(s) dont l'ingrédient n'existe pas", $label, $orphans);
            }
            if ($crossed > 0) {
                $anomalies[] = sprintf("%s : %d ligne(s) sur l'ingrédient personnel d'un autre compte", $label, $crossed);
            }
        }

        if ($driveSlugByChoice !== []) {
            $slugs = array_values(array_unique($driveSlugByChoice));
            $creators = $connection->fetchAllKeyValue('SELECT slug, creator FROM ingredient WHERE slug IN (?)', [$slugs], [ArrayParameterType::STRING]);
            $missing = $crossed = 0;
            foreach ($driveSlugByChoice as $slug) {
                if (!array_key_exists($slug, $creators)) {
                    ++$missing;
                } elseif ($creators[$slug] !== null && $creators[$slug] !== $userId) {
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
     * La référence courte d'un compte dans les rapports : les 8 premiers caractères du SHA-256 de son identifiant.
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
