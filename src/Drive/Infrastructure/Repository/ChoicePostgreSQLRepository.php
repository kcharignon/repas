<?php

namespace Repas\Drive\Infrastructure\Repository;


use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Model\AcceptedProduct;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\Choice as ChoiceEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Lectures et écritures en SQL direct : un lot de préférences coûte une requête par table, quelle que soit sa taille. Les
 * lignes de produits d'une préférence sont réécrites en entier (supprimées puis insérées dans la même transaction), ce qui évite
 * tout conflit sur `UNIQUE (choice_id, rank)` quand les rangs se décalent. Aucune préférence n'est donc dans l'identity map.
 */
readonly class ChoicePostgreSQLRepository extends PostgreSQLRepository implements ChoiceRepository
{
    private const string ACCEPTED = 'ACCEPTED';
    private const string REJECTED = 'REJECTED';
    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, ChoiceEntity::class);
    }

    public function findByUserStoreAndSlugs(string $userId, Retailer $retailer, string $storeId, array $ingredientSlugs): array
    {
        if ($ingredientSlugs === []) {
            return [];
        }

        return $this->fetch(
            'c.user_id = ? AND c.retailer = ? AND c.store_id = ? AND c.ingredient_slug IN (?)',
            [$userId, $retailer->value, $storeId, $ingredientSlugs],
            [3 => ArrayParameterType::STRING],
        );
    }

    public function findOne(string $userId, Retailer $retailer, string $storeId, string $ingredientSlug): ?Choice
    {
        return $this->findByUserStoreAndSlugs($userId, $retailer, $storeId, [$ingredientSlug])[0] ?? null;
    }

    public function saveAll(array $choices): void
    {
        if ($choices === []) {
            return;
        }

        $connection = $this->connection();
        $choiceParams = $productParams = [];
        $ids = [];
        foreach ($choices as $choice) {
            $ids[] = $choice->getId();
            array_push(
                $choiceParams,
                $choice->getId(),
                $choice->getUserId(),
                $choice->getRetailer()->value,
                $choice->getStoreId(),
                $choice->getIngredientSlug(),
                $choice->getKind()->value,
                $choice->getUpdatedAt()->format(self::DATE_FORMAT),
            );
            foreach ($choice->getAccepted() as $accepted) {
                array_push($productParams, $choice->getId(), $accepted->productId, self::ACCEPTED, $accepted->rank);
            }
            foreach ($choice->getRejectedProductIds() as $productId) {
                array_push($productParams, $choice->getId(), $productId, self::REJECTED, null);
            }
        }

        $connection->executeStatement(
            'INSERT INTO drive_choice (id, user_id, retailer, store_id, ingredient_slug, kind, updated_at) VALUES '
            . implode(', ', array_fill(0, count($choices), '(?, ?, ?, ?, ?, ?, ?)'))
            . ' ON CONFLICT (id) DO UPDATE SET kind = EXCLUDED.kind, updated_at = EXCLUDED.updated_at',
            $choiceParams,
        );
        $connection->executeStatement('DELETE FROM drive_choice_product WHERE choice_id IN (?)', [$ids], [ArrayParameterType::STRING]);
        if ($productParams !== []) {
            $connection->executeStatement(
                'INSERT INTO drive_choice_product (choice_id, product_id, verdict, rank) VALUES '
                . implode(', ', array_fill(0, intdiv(count($productParams), 4), '(?, ?, ?, ?)')),
                $productParams,
            );
        }
    }

    public function save(Choice $choice): void
    {
        $this->saveAll([$choice]);
    }

    public function delete(Choice $choice): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM drive_choice_product WHERE choice_id = ?', [$choice->getId()]);
        $connection->executeStatement('DELETE FROM drive_choice WHERE id = ?', [$choice->getId()]);
    }

    public function deleteProductsByUser(string $userId): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM drive_choice_product WHERE choice_id IN (SELECT id FROM drive_choice WHERE user_id = ?)',
            [$userId],
        );
    }

    public function deleteByUser(string $userId): void
    {
        $this->connection()->executeStatement('DELETE FROM drive_choice WHERE user_id = ?', [$userId]);
    }

    /**
     * Les préférences qui répondent au filtre, avec leurs lignes de produits, en une requête.
     *
     * @param list<mixed> $params
     * @param array<int, mixed> $types
     *
     * @return list<Choice>
     */
    private function fetch(string $where, array $params, array $types): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT c.id, c.user_id, c.retailer, c.store_id, c.ingredient_slug, c.kind, c.updated_at, cp.product_id, cp.verdict, cp.rank '
            . 'FROM drive_choice c LEFT JOIN drive_choice_product cp ON cp.choice_id = c.id '
            . 'WHERE ' . $where . ' ORDER BY c.id, cp.rank, cp.product_id',
            $params,
            $types,
        );

        $choices = [];
        foreach ($rows as $row) {
            $choices[$row['id']] ??= ['row' => $row, 'accepted' => [], 'rejected' => []];
            if ($row['verdict'] === self::ACCEPTED) {
                $choices[$row['id']]['accepted'][] = new AcceptedProduct($row['product_id'], (int) $row['rank']);
            } elseif ($row['verdict'] === self::REJECTED) {
                $choices[$row['id']]['rejected'][] = $row['product_id'];
            }
        }

        return array_values(array_map(
            static fn(array $choice): Choice => Choice::load([
                'id' => $choice['row']['id'],
                'user_id' => $choice['row']['user_id'],
                'retailer' => Retailer::from($choice['row']['retailer']),
                'store_id' => $choice['row']['store_id'],
                'ingredient_slug' => $choice['row']['ingredient_slug'],
                'kind' => ChoiceKind::from($choice['row']['kind']),
                'accepted' => $choice['accepted'],
                'rejected' => $choice['rejected'],
                'updated_at' => new DateTimeImmutable($choice['row']['updated_at']),
            ]),
            $choices,
        ));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
