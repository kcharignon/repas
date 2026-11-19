<?php

namespace Repas\Repas\Infrastructure\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Interface\ShoppingListArchiveRepository;
use Repas\Repas\Domain\Model\ShoppingListArchiveCause;
use Repas\Repas\Infrastructure\Entity\ShoppingListArchive as ShoppingListArchiveEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

readonly class ShoppingListArchivePostgreSQLRepository extends PostgreSQLRepository implements ShoppingListArchiveRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, ShoppingListArchiveEntity::class);
    }

    public function archive(string $archiveId, string $shoppingListId, ShoppingListArchiveCause $cause, DateTimeImmutable $at): void
    {
        // Une seule instruction, sans hydrater aucun modèle : la liste, ses lignes et ses repas sont copiés tels qu'ils sont en base, avec les noms
        // (ingrédient, unité, recette, type de liste) que l'archive doit porter pour se lire sans jointure. Les paramètres sont typés : la sélection
        // d'un INSERT ne donne pas son type à un paramètre
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO shopping_list_archive'
            . ' (id, shopping_list_id, owner_id, name, status, list_type_id, list_type_name, created_at, sent_at, version, archived_at, cause, line_count, lines, meals, part_shares)'
            . ' SELECT CAST(:id AS VARCHAR), l.id, l.owner, l.name, l.status, l.list_type_id, t.name, l.created_at, l.sent_at, l.version,'
            . ' CAST(:at AS TIMESTAMP(0)), CAST(:cause AS VARCHAR),'
            . ' (SELECT count(*) FROM shopping_list_row r WHERE r.shopping_list = l.id),'
            . ' COALESCE((SELECT jsonb_agg(jsonb_build_object('
            . "'id', r.id, 'ingredient_slug', r.ingredient, 'ingredient_name', i.name, 'quantity', r.quantity, 'unit_slug', r.unit, 'unit_symbol', u.symbol,"
            . "'checked', r.checked, 'checked_by', r.checked_by, 'checked_at', r.checked_at,"
            . "'drive_outcome', r.drive_outcome, 'drive_reason', r.drive_reason, 'drive_attempted_at', r.drive_attempted_at) ORDER BY i.name, r.id)"
            . ' FROM shopping_list_row r LEFT JOIN ingredient i ON i.slug = r.ingredient LEFT JOIN unit u ON u.slug = r.unit WHERE r.shopping_list = l.id),'
            . " '[]'::jsonb),"
            . ' COALESCE((SELECT jsonb_agg(jsonb_build_object('
            . "'id', m.id, 'recipe_id', m.recipe, 'recipe_name', rc.name, 'serving', m.serving, 'planned_group_id', m.planned_group_id,"
            . "'placed_ingredients', m.placed_ingredients) ORDER BY rc.name, m.id)"
            . ' FROM meal m LEFT JOIN recipe rc ON rc.id = m.recipe WHERE m.shopping_list = l.id),'
            . " '[]'::jsonb),"
            . ' l.part_shares'
            . ' FROM shopping_list l LEFT JOIN shopping_list_type t ON t.id = l.list_type_id'
            . ' WHERE l.id = :list',
            ['id' => $archiveId, 'at' => $at, 'cause' => $cause->value, 'list' => $shoppingListId],
            ['at' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteByOwner(string $ownerId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ShoppingListArchiveEntity::class, 'a')
            ->where('a.ownerId = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->execute();
    }
}
