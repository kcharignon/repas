<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Repas\Domain\Interface\IngredientCatalogHandoverRepository;
use Repas\Repas\Domain\Model\CatalogHandoverState;
use Repas\Repas\Infrastructure\Adoption\CatalogHandoverReader;

/**
 * Les lectures et écritures de la passation du catalogue (ING-009) en SQL direct : une requête par étape, quel que soit le nombre d'ingrédients,
 * aucun agrégat hydraté. La passation n'écrit que deux colonnes de `ingredient`, et seulement pour les ingrédients SANS CRÉATEUR : `is_base`
 * (le classement, phase 1) puis `creator` (le propriétaire, phase 4). Aucun `INSERT`, aucun `DELETE`.
 */
readonly class IngredientCatalogHandoverPostgreSQLRepository implements IngredientCatalogHandoverRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CatalogHandoverReader $reader,
    ) {
    }

    public function lock(): void
    {
        $this->connection()->executeQuery("SELECT pg_advisory_xact_lock(hashtext('catalog-handover'))")->free();
    }

    public function slugsWithoutCreator(): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn('SELECT slug FROM ingredient WHERE creator IS NULL ORDER BY slug COLLATE "C"');
    }

    public function state(?string $ownerId, array $classification): CatalogHandoverState
    {
        return $this->reader->read($ownerId, $classification);
    }

    public function classify(array $classification): int
    {
        if ($classification === []) {
            return 0;
        }

        // Seuls les ingrédients sans créateur, et seulement ceux dont le marqueur change : un rejeu ne réécrit aucune ligne
        return (int) $this->connection()->executeStatement(
            'UPDATE ingredient i SET is_base = CAST(m.value AS boolean)'
            . ' FROM jsonb_each_text(CAST(:map AS jsonb)) AS m'
            . ' WHERE i.slug = m.key AND i.creator IS NULL AND i.is_base IS DISTINCT FROM CAST(m.value AS boolean)',
            ['map' => json_encode($classification, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)],
        );
    }

    public function assignToOwner(string $ownerId, array $slugs): int
    {
        if ($slugs === []) {
            return 0;
        }

        return (int) $this->connection()->executeStatement(
            'UPDATE ingredient SET creator = :owner WHERE creator IS NULL AND slug IN (SELECT jsonb_array_elements_text(CAST(:slugs AS jsonb)))',
            ['owner' => $ownerId, 'slugs' => json_encode(array_values($slugs), JSON_THROW_ON_ERROR)],
        );
    }

    public function fingerprint(array $slugs): string
    {
        $null = "'∅'";

        return (string) $this->connection()->fetchOne(
            "SELECT coalesce(md5(string_agg(t.line, E'\\n' ORDER BY t.slug COLLATE \"C\")), '') FROM ("
            . "   SELECT i.slug, concat_ws('|', i.slug, coalesce(i.name, $null), coalesce(i.slugged_name, $null), coalesce(i.image, $null), coalesce(i.department, $null),"
            . "       coalesce(i.default_cooking_unit, $null), coalesce(i.default_purchase_unit, $null), coalesce(i.compatible_units::text, $null), coalesce(i.adopted_from, $null),"
            . "       (SELECT coalesce(string_agg(v.start_unit || '>' || v.end_unit || '=' || v.coefficient::text, ',' ORDER BY v.start_unit, v.end_unit, v.coefficient), '') FROM conversion v WHERE v.ingredient = i.slug)) AS line"
            . '   FROM ingredient i WHERE i.slug IN (SELECT jsonb_array_elements_text(CAST(:slugs AS jsonb)))'
            . ') t',
            ['slugs' => json_encode(array_values($slugs), JSON_THROW_ON_ERROR)],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
