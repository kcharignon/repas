<?php

namespace Repas\Drive\Infrastructure\Repository;


use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Infrastructure\Entity\Choice as ChoiceEntity;
use Repas\Shared\Application\Dto\DriveChoiceFingerprint;
use Repas\Shared\Application\Dto\DriveMergeImpact;
use Repas\Shared\Application\Interface\DriveIngredientReferences;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Le port `Shared` qui permet à `Repas` de repointer les choix du drive d'un utilisateur quand ses ingrédients changent de slug
 * (migration d'adoption ING-003). SQL direct, quelques requêtes quel que soit le nombre de choix ; aucune enseigne n'est nommée.
 */
readonly class DriveIngredientReferencesPostgreSQLRepository extends PostgreSQLRepository implements DriveIngredientReferences
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, ChoiceEntity::class);
    }

    public function fingerprint(string $userId): DriveChoiceFingerprint
    {
        $connection = $this->connection();

        $choices = $connection->fetchAllNumeric(
            'SELECT c.id, c.ingredient_slug, (SELECT count(*) FROM drive_choice_product cp WHERE cp.choice_id = c.id) AS products'
            . ' FROM drive_choice c WHERE c.user_id = ? ORDER BY c.id',
            [$userId],
        );
        $slugByChoice = $productsByChoice = [];
        $products = 0;
        foreach ($choices as [$id, $slug, $count]) {
            $slugByChoice[$id] = $slug;
            $productsByChoice[$id] = (int) $count;
            $products += (int) $count;
        }

        // Tout ce qui n'est PAS le slug : un choix est résumé en une ligne de texte, les lignes sont triées avant d'être hachées
        // (`COLLATE "C"` : l'ordre ne dépend pas de la locale de la base). Les identifiants de choix n'en font pas partie : ils ne
        // changent pas, et le lien choix -> slug est vérifié à part.
        [$packSizeSum, $contentHash] = $connection->fetchNumeric(
            "SELECT coalesce((SELECT sum(cp.pack_size_value) FROM drive_choice_product cp JOIN drive_choice c ON c.id = cp.choice_id WHERE c.user_id = :u), 0)::text,"
            . " md5(coalesce(string_agg(x.line, E'\\n' ORDER BY x.line COLLATE \"C\"), ''))"
            . ' FROM ('
            . "   SELECT c.retailer || '|' || c.store_id || '|' || c.kind || '|' || coalesce(c.search_query, '') || '|' || c.updated_at::text || '|' ||"
            . "          coalesce((SELECT string_agg(cp.product_id || ':' || cp.verdict || ':' || coalesce(cp.rank::text, '') || ':' || coalesce(cp.pack_size_value::text, '') || ':' || coalesce(cp.pack_size_unit_slug, ''),"
            . "                                     ',' ORDER BY cp.product_id COLLATE \"C\") FROM drive_choice_product cp WHERE cp.choice_id = c.id), '') AS line"
            . '   FROM drive_choice c WHERE c.user_id = :u'
            . ' ) x',
            ['u' => $userId],
        );

        return new DriveChoiceFingerprint(count($choices), $products, (string) $packSizeSum, (string) $contentHash, $slugByChoice, $productsByChoice);
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

    /**
     * Le gardé a déjà un choix dans le même magasin que le choix `b` de l'absorbé (même utilisateur, même enseigne, même magasin) : le
     * choix de l'absorbé est alors supprimé avec ses produits ; sinon il passe sur le gardé.
     */
    private const string KEPT_HAS_A_CHOICE = 'EXISTS (SELECT 1 FROM drive_choice kept WHERE kept.user_id = b.user_id AND kept.retailer = b.retailer'
        . ' AND kept.store_id = b.store_id AND kept.ingredient_slug = :kept)';

    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): DriveMergeImpact
    {
        [$moved, $dropped, $products, $foreign] = $this->connection()->fetchNumeric(
            'SELECT'
            . ' (SELECT count(*) FROM drive_choice b WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND NOT ' . self::KEPT_HAS_A_CHOICE . '),'
            . ' (SELECT count(*) FROM drive_choice b WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND ' . self::KEPT_HAS_A_CHOICE . '),'
            . ' (SELECT count(*) FROM drive_choice_product p JOIN drive_choice b ON b.id = p.choice_id'
            . '   WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND ' . self::KEPT_HAS_A_CHOICE . '),'
            . ' EXISTS (SELECT 1 FROM drive_choice o WHERE o.ingredient_slug = :absorbed AND o.user_id <> :u)',
            ['u' => $userId, 'absorbed' => $absorbedSlug, 'kept' => $keptSlug],
        );

        return new DriveMergeImpact((int) $moved, (int) $dropped, (int) $products, (bool) $foreign);
    }

    public function merge(string $userId, string $absorbedSlug, string $keptSlug): DriveMergeImpact
    {
        // Une seule requête : les branches lisent le même instantané, donc un choix qui passe sur le gardé n'est jamais l'un de ceux qui
        // sont supprimés, et aucune clé unique (utilisateur, enseigne, magasin, ingrédient) n'est violée.
        [$moved, $dropped, $products] = $this->connection()->fetchNumeric(
            'WITH clashing AS (SELECT b.id FROM drive_choice b WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND ' . self::KEPT_HAS_A_CHOICE . '),'
            . ' dropped_products AS (DELETE FROM drive_choice_product WHERE choice_id IN (SELECT id FROM clashing) RETURNING 1),'
            . ' dropped_choices AS (DELETE FROM drive_choice WHERE id IN (SELECT id FROM clashing) RETURNING 1),'
            . ' moved AS (UPDATE drive_choice b SET ingredient_slug = :kept WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND NOT ' . self::KEPT_HAS_A_CHOICE . ' RETURNING 1)'
            . ' SELECT (SELECT count(*) FROM moved), (SELECT count(*) FROM dropped_choices), (SELECT count(*) FROM dropped_products)',
            ['u' => $userId, 'absorbed' => $absorbedSlug, 'kept' => $keptSlug],
        );

        return new DriveMergeImpact((int) $moved, (int) $dropped, (int) $products);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
