<?php

namespace Repas\Repas\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Interface\IngredientAdoptionBulkRepository;
use Repas\Repas\Domain\Model\AdoptionRepointed;
use Repas\Repas\Domain\Model\AdoptionSnapshot;
use Repas\Repas\Domain\Model\AliasKind;
use Repas\Repas\Infrastructure\Adoption\AdoptionSnapshotReader;
use Repas\Repas\Infrastructure\Adoption\SeedingSourceReader;
use Repas\Repas\Infrastructure\Visibility\IngredientVisibility;

/**
 * Les écritures en masse de la migration d'adoption (ADR 0028, ING-003) en SQL direct : quelques requêtes par utilisateur, jamais une
 * par commun ni par ligne, aucun agrégat hydraté (ni l'identity map de Doctrine ni les événements ne sont concernés). Les `UPDATE` ne
 * touchent qu'aux lignes des recettes dont l'utilisateur est l'AUTEUR et des listes dont il est le PROPRIÉTAIRE ; les communs ne sont
 * jamais écrits ; aucune ligne n'est supprimée, sauf les alias d'un lancement défait (`deleteAdoptionAliases`).
 *
 * Les tables `ingredient` / `conversion` / `ingredient_alias` / `recipe*` / `shopping_list*` sont celles du contexte `Repas`. Les choix
 * du drive, eux, sont repointés par `Drive` (port `Shared` `DriveIngredientReferences`).
 */
readonly class IngredientAdoptionBulkPostgreSQLRepository implements IngredientAdoptionBulkRepository
{
    /**
     * Ce qui sépare une copie `o` de son commun `c` : nom, image, rayon, unités, unités compatibles et conversions propres (unités et
     * coefficient). Faux pour une copie conforme.
     */
    public const string COPY_DIFFERS_FROM_COMMON = 'o.name IS DISTINCT FROM c.name OR o.image IS DISTINCT FROM c.image OR o.slugged_name IS DISTINCT FROM c.slugged_name'
        . ' OR o.department IS DISTINCT FROM c.department OR o.default_cooking_unit IS DISTINCT FROM c.default_cooking_unit'
        . ' OR o.default_purchase_unit IS DISTINCT FROM c.default_purchase_unit OR o.compatible_units::text IS DISTINCT FROM c.compatible_units::text'
        . " OR (SELECT coalesce(string_agg(v.start_unit || '>' || v.end_unit || '=' || v.coefficient::text, ',' ORDER BY v.start_unit, v.end_unit, v.coefficient), '') FROM conversion v WHERE v.ingredient = o.slug)"
        . " IS DISTINCT FROM (SELECT coalesce(string_agg(v.start_unit || '>' || v.end_unit || '=' || v.coefficient::text, ',' ORDER BY v.start_unit, v.end_unit, v.coefficient), '') FROM conversion v WHERE v.ingredient = c.slug)";

    /**
     * Les adoptions valides d'un utilisateur, de l'ingrédient d'origine vers sa copie : l'alias d'adoption dont la copie existe et lui
     * appartient (même définition que `IngredientAliasPostgreSQLRepository` et `IngredientVisibility`).
     */
    private const string VALID_ADOPTIONS = 'SELECT a.alias_slug AS from_slug, a.ingredient_slug AS to_slug FROM ingredient_alias a'
        . ' JOIN ingredient own ON own.slug = a.ingredient_slug AND own.creator = a.user_id'
        . " WHERE a.user_id = :u AND a.kind = 'adoption'";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdoptionSnapshotReader $snapshotReader,
        private SeedingSourceReader $seedingSourceReader,
    ) {
    }

    public function lock(string $userId): void
    {
        $this->connection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['adopt:' . $userId])->free();
    }

    public function takenSlugs(string $userId): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE slug LIKE ?', ['%' . addcslashes($userId, '%_\\')]);
    }

    public function writeAliases(string $userId, array $copySlugByCommonSlug, string $source, DateTimeImmutable $now): array
    {
        // Un alias ORPHELIN (adoption dont la copie a disparu) est remplacé sur place ; un alias valide n'est jamais réécrit (une
        // adoption simultanée l'a emporté) ; RETURNING ne rend que les lignes réellement écrites par cet appel
        $rows = $this->connection()->fetchAllNumeric(
            'INSERT INTO ingredient_alias (user_id, alias_slug, ingredient_slug, kind, source, created_at)'
            . ' SELECT :u, m.key, m.value, :kind, :source, :now FROM jsonb_each_text(CAST(:map AS jsonb)) AS m'
            . ' ON CONFLICT (user_id, alias_slug) DO UPDATE SET ingredient_slug = EXCLUDED.ingredient_slug, source = EXCLUDED.source, created_at = EXCLUDED.created_at'
            . '   WHERE ingredient_alias.kind = EXCLUDED.kind'
            . '     AND NOT EXISTS (SELECT 1 FROM ingredient own WHERE own.slug = ingredient_alias.ingredient_slug AND own.creator = ingredient_alias.user_id)'
            . ' RETURNING alias_slug, ingredient_slug',
            ['u' => $userId, 'kind' => AliasKind::ADOPTION->value, 'source' => $source, 'now' => $now, 'map' => $this->json($copySlugByCommonSlug)],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        $written = [];
        foreach ($rows as [$aliasSlug, $ingredientSlug]) {
            $written[$aliasSlug] = $ingredientSlug;
        }

        return $written;
    }

    public function ownConversionsOf(array $commonSlugs): array
    {
        if ($commonSlugs === []) {
            return [];
        }

        $rows = $this->connection()->fetchAllNumeric(
            'SELECT id, ingredient FROM conversion WHERE ingredient IN (?) ORDER BY ingredient, id',
            [array_values($commonSlugs)],
            [ArrayParameterType::STRING],
        );

        return array_map(static fn(array $row): array => ['id' => $row[0], 'commonSlug' => $row[1]], $rows);
    }

    public function copyConversions(array $copies): int
    {
        if ($copies === []) {
            return 0;
        }

        // Unités et coefficient sont recopiés par la base depuis la conversion d'origine : aucun aller-retour de flottant
        return (int) $this->connection()->executeStatement(
            'INSERT INTO conversion (id, start_unit, end_unit, coefficient, ingredient)'
            . ' SELECT x.new_id, c.start_unit, c.end_unit, c.coefficient, x.copy_slug'
            . ' FROM jsonb_to_recordset(CAST(:rows AS jsonb)) AS x(source_id text, new_id text, copy_slug text) JOIN conversion c ON c.id = x.source_id',
            ['rows' => $this->json(array_map(
                static fn(array $copy): array => ['source_id' => $copy['sourceId'], 'new_id' => $copy['newId'], 'copy_slug' => $copy['copySlug']],
                $copies,
            ))],
        );
    }

    public function adoptions(string $userId): array
    {
        $rows = $this->connection()->fetchAllNumeric(self::VALID_ADOPTIONS . ' ORDER BY a.alias_slug', ['u' => $userId]);

        $adoptions = [];
        foreach ($rows as [$commonSlug, $copySlug]) {
            $adoptions[$commonSlug] = $copySlug;
        }

        return $adoptions;
    }

    public function repointToCopies(string $userId): AdoptionRepointed
    {
        return $this->repoint($userId, self::VALID_ADOPTIONS, ['u' => $userId]);
    }

    public function snapshot(string $userId, array $watchedSlugs): AdoptionSnapshot
    {
        return $this->snapshotReader->read($userId, $watchedSlugs);
    }

    public function nonConformingCopies(string $userId, array $adoptions): array
    {
        if ($adoptions === []) {
            return [];
        }

        /** @var list<string> */
        return $this->connection()->fetchFirstColumn(
            'SELECT m.key FROM jsonb_each_text(CAST(:map AS jsonb)) AS m'
            . ' LEFT JOIN ingredient c ON c.slug = m.key LEFT JOIN ingredient o ON o.slug = m.value'
            . ' WHERE c.slug IS NULL OR o.slug IS NULL OR COALESCE(o.creator, \'\') <> :u OR o.adopted_from IS DISTINCT FROM c.slug'
            . '    OR ' . self::COPY_DIFFERS_FROM_COMMON
            . ' ORDER BY m.key',
            ['u' => $userId, 'map' => $this->json($adoptions)],
        );
    }

    public function lockSeeding(string $userId): void
    {
        $this->connection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['seed:' . $userId])->free();
    }

    public function accountExists(string $userId): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM "user" WHERE id = ?', [$userId]) !== false;
    }

    public function isSeeded(string $userId): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM ingredient_alias WHERE user_id = ? AND kind = ? LIMIT 1',
            [$userId, AliasKind::ADOPTION->value],
        ) !== false;
    }

    public function ingredientsToSeed(string $userId, string $ownerId, array $driveReferencedSlugs): array
    {
        return $this->seedingSourceReader->read($userId, $ownerId, $driveReferencedSlugs);
    }

    public function copyCatalogIngredients(string $userId, string $ownerId, array $copySlugBySourceSlug): int
    {
        return (int) $this->connection()->executeStatement(
            'INSERT INTO ingredient (slug, name, image, slugged_name, department, default_cooking_unit, default_purchase_unit, creator, compatible_units, adopted_from, is_base)'
            . ' SELECT m.value, c.name, c.image, c.slugged_name, c.department, c.default_cooking_unit, c.default_purchase_unit, :u, c.compatible_units, c.slug, FALSE'
            . ' FROM jsonb_each_text(CAST(:map AS jsonb)) AS m JOIN ingredient c ON c.slug = m.key AND ' . IngredientVisibility::isCatalogSql('c', 'owner'),
            ['u' => $userId, 'owner' => $ownerId, 'map' => $this->json($copySlugBySourceSlug)],
        );
    }

    /**
     * Repointe les lignes de l'utilisateur de `from_slug` vers `to_slug` (les paires viennent de `$pairsSql`) : d'abord les lignes de
     * ses recettes, puis celles de ses listes, chacune dans UNE requête qui monte aussi de 1 la version des seuls agrégats modifiés.
     * Recettes et listes vont ensemble : le calcul d'une liste relit le slug courant des lignes de la recette (`subtractRow`).
     *
     * @param array<string, mixed> $params
     */
    private function repoint(string $userId, string $pairsSql, array $params): AdoptionRepointed
    {
        $connection = $this->connection();

        $recipes = $connection->fetchNumeric(
            'WITH pairs AS (' . $pairsSql . '),'
            . ' changed AS (UPDATE recipe_row rr SET ingredient = pairs.to_slug FROM pairs, recipe r'
            . '   WHERE rr.ingredient = pairs.from_slug AND r.id = rr.recipe AND r.author = :u RETURNING rr.recipe),'
            . ' bumped AS (UPDATE recipe SET version = version + 1 WHERE id IN (SELECT recipe FROM changed) RETURNING id)'
            . ' SELECT (SELECT count(*) FROM changed), (SELECT count(*) FROM bumped)',
            $params,
        );

        $lists = $connection->fetchNumeric(
            'WITH pairs AS (' . $pairsSql . '),'
            . ' list_rows AS (UPDATE shopping_list_row x SET ingredient = pairs.to_slug FROM pairs, shopping_list l'
            . '   WHERE x.ingredient = pairs.from_slug AND l.id = x.shopping_list AND l.owner = :u RETURNING x.shopping_list),'
            . ' list_ingredients AS (UPDATE shopping_list_ingredient x SET ingredient = pairs.to_slug FROM pairs, shopping_list l'
            . '   WHERE x.ingredient = pairs.from_slug AND l.id = x.shopping_list AND l.owner = :u RETURNING x.shopping_list),'
            . ' bumped AS (UPDATE shopping_list SET version = version + 1'
            . '   WHERE id IN (SELECT shopping_list FROM list_rows UNION SELECT shopping_list FROM list_ingredients) RETURNING id)'
            . ' SELECT (SELECT count(*) FROM list_rows), (SELECT count(*) FROM list_ingredients), (SELECT count(*) FROM bumped)',
            $params,
        );

        return new AdoptionRepointed((int) $recipes[0], (int) $recipes[1], (int) $lists[0], (int) $lists[1], (int) $lists[2]);
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
