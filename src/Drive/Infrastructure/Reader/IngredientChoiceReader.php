<?php

namespace Repas\Drive\Infrastructure\Reader;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les lectures SQL des choix de produits par ingrédient, écrites une seule fois : `IngredientChoicePostgreSQLRepository` (pour l'écriture en
 * cours) et `IngredientChoicePostgreSQLLoader` (pour les rapports) rendent donc exactement la même chose. Lecture seule, quelques requêtes
 * quel que soit le nombre de choix ; aucune enseigne n'est nommée.
 */
final readonly class IngredientChoiceReader
{
    /**
     * Le gardé a déjà un choix dans le même magasin que le choix `b` de l'absorbé (même utilisateur, même enseigne, même magasin) : le
     * choix de l'absorbé est alors supprimé avec ses produits ; sinon il passe sur le gardé.
     */
    public const string KEPT_HAS_A_CHOICE = 'EXISTS (SELECT 1 FROM drive_choice kept WHERE kept.user_id = b.user_id AND kept.retailer = b.retailer'
        . ' AND kept.store_id = b.store_id AND kept.ingredient_slug = :kept)';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{choices: int, products: int, packSizeSum: string, contentHash: string, ingredientSlugByChoice: array<string, string>, productCountByChoice: array<string, int>}
     */
    public function fingerprint(string $userId): array
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
            . "          coalesce((SELECT string_agg(cp.product_id || ':' || cp.verdict || ':' || coalesce(cp.rank::text, '') || ':' || coalesce(cp.pack_size_value::text, '') || ':' || coalesce(cp.pack_size_unit_slug, '') || ':' ||"
            . "                                     coalesce(cp.label, '') || ':' || coalesce(cp.price_cents::text, '') || ':' || coalesce(cp.price_seen_at::text, ''),"
            . "                                     ',' ORDER BY cp.product_id COLLATE \"C\") FROM drive_choice_product cp WHERE cp.choice_id = c.id), '') AS line"
            . '   FROM drive_choice c WHERE c.user_id = :u'
            . ' ) x',
            ['u' => $userId],
        );

        return [
            'choices' => count($choices),
            'products' => $products,
            'packSizeSum' => (string) $packSizeSum,
            'contentHash' => (string) $contentHash,
            'ingredientSlugByChoice' => $slugByChoice,
            'productCountByChoice' => $productsByChoice,
        ];
    }

    /**
     * @return list<string>
     */
    public function referencedSlugs(string $userId): array
    {
        /** @var list<string> */
        return $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT ingredient_slug FROM drive_choice WHERE user_id = ? ORDER BY ingredient_slug',
            [$userId],
        );
    }

    /**
     * @return array{moved: int, dropped: int, products: int}
     */
    public function mergeImpact(string $userId, string $absorbedSlug, string $keptSlug): array
    {
        // Seuls les choix de l'utilisateur comptent. Le choix d'un autre compte sur l'un de ses ingrédients n'est l'usage de personne (un choix
        // ne se pose que sur un ingrédient de son auteur) : il ne s'ajoute pas à ce que la fusion déplace et ne l'empêche pas
        [$moved, $dropped, $products] = $this->connection()->fetchNumeric(
            'SELECT'
            . ' (SELECT count(*) FROM drive_choice b WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND NOT ' . self::KEPT_HAS_A_CHOICE . '),'
            . ' (SELECT count(*) FROM drive_choice b WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND ' . self::KEPT_HAS_A_CHOICE . '),'
            . ' (SELECT count(*) FROM drive_choice_product p JOIN drive_choice b ON b.id = p.choice_id'
            . '   WHERE b.user_id = :u AND b.ingredient_slug = :absorbed AND ' . self::KEPT_HAS_A_CHOICE . ')',
            ['u' => $userId, 'absorbed' => $absorbedSlug, 'kept' => $keptSlug],
        );

        return ['moved' => (int) $moved, 'dropped' => (int) $dropped, 'products' => (int) $products];
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
