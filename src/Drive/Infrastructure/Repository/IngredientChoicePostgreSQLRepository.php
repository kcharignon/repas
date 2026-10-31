<?php

namespace Repas\Drive\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Domain\Interface\IngredientChoiceRepository;
use Repas\Drive\Domain\Model\IngredientChoiceFingerprint;
use Repas\Drive\Domain\Model\IngredientChoiceMergeImpact;
use Repas\Drive\Infrastructure\Entity\Choice as ChoiceEntity;
use Repas\Drive\Infrastructure\Reader\IngredientChoiceReader;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Les choix de produits par ingrédient pour un autre contexte dont les ingrédients changent de slug ou fusionnent (amorçage d'un compte,
 * migration d'adoption, ING-003, fusion, ING-005). SQL direct, quelques requêtes quel que soit le nombre de choix ; aucune enseigne n'est
 * nommée. Les lectures sont celles de `IngredientChoiceReader`, le même code que le Loader des rapports.
 */
readonly class IngredientChoicePostgreSQLRepository extends PostgreSQLRepository implements IngredientChoiceRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private IngredientChoiceReader $reader,
    ) {
        parent::__construct($managerRegistry, ChoiceEntity::class);
    }

    public function fingerprint(string $userId): IngredientChoiceFingerprint
    {
        $fingerprint = $this->reader->fingerprint($userId);

        return new IngredientChoiceFingerprint(
            $fingerprint['choices'],
            $fingerprint['products'],
            $fingerprint['packSizeSum'],
            $fingerprint['contentHash'],
            $fingerprint['ingredientSlugByChoice'],
            $fingerprint['productCountByChoice'],
        );
    }

    public function referencedSlugs(string $userId): array
    {
        return $this->reader->referencedSlugs($userId);
    }

    public function repoint(string $userId, array $slugMap): int
    {
        if ($slugMap === []) {
            return 0;
        }

        // La clé unique (utilisateur, enseigne, magasin, ingrédient) : un choix dont le slug cible est déjà pris n'est pas touché
        return $this->connection()->executeStatement(
            'UPDATE drive_choice c SET ingredient_slug = m.value'
            . ' FROM jsonb_each_text(CAST(:map AS jsonb)) AS m'
            . ' WHERE c.user_id = :u AND c.ingredient_slug = m.key'
            . '   AND NOT EXISTS (SELECT 1 FROM drive_choice o WHERE o.user_id = c.user_id AND o.retailer = c.retailer AND o.store_id = c.store_id AND o.ingredient_slug = m.value)',
            ['map' => json_encode($slugMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'u' => $userId],
        );
    }

    public function merge(string $userId, string $absorbedSlug, string $keptSlug): IngredientChoiceMergeImpact
    {
        // Une seule requête : les branches lisent le même instantané, donc un choix qui passe sur le gardé n'est jamais l'un de ceux qui
        // sont supprimés, et aucune clé unique (utilisateur, enseigne, magasin, ingrédient) n'est violée.
        [$moved, $dropped, $products] = $this->connection()->fetchNumeric(
            'WITH clashing AS (SELECT b.id FROM drive_choice b WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND ' . IngredientChoiceReader::KEPT_HAS_A_CHOICE . '),'
            . ' dropped_products AS (DELETE FROM drive_choice_product WHERE choice_id IN (SELECT id FROM clashing) RETURNING 1),'
            . ' dropped_choices AS (DELETE FROM drive_choice WHERE id IN (SELECT id FROM clashing) RETURNING 1),'
            . ' moved AS (UPDATE drive_choice b SET ingredient_slug = :kept WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND NOT ' . IngredientChoiceReader::KEPT_HAS_A_CHOICE . ' RETURNING 1)'
            . ' SELECT (SELECT count(*) FROM moved), (SELECT count(*) FROM dropped_choices), (SELECT count(*) FROM dropped_products)',
            ['u' => $userId, 'absorbed' => $absorbedSlug, 'kept' => $keptSlug],
        );

        return new IngredientChoiceMergeImpact((int) $moved, (int) $dropped, (int) $products);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
