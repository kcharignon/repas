<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Application\Loader\ChoiceLoader;
use Repas\Drive\Application\Query\ListChoices\AcceptedProductDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceDTO;
use Repas\Drive\Application\Query\ListChoices\ChoiceProductDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\Choice;
use Repas\Drive\Infrastructure\Entity\ChoiceProduct;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Drive\Infrastructure\Entity\Product;
use Repas\Shared\Application\Interface\IngredientNameLoader;

/**
 * Seuls les produits des préférences de l'utilisateur sont lus : jamais le catalogue `drive_product` en dehors de ses choix
 * (décision D22). Deux requêtes, quel que soit le nombre de préférences : les préférences avec leurs produits, puis les noms
 * d'ingrédients.
 */
final readonly class ChoicePostgreSQLLoader implements ChoiceLoader
{
    private const string ACCEPTED = 'ACCEPTED';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private IngredientNameLoader $ingredientNameLoader,
    ) {
    }

    public function list(string $userId, Retailer $retailer): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'c.ingredientSlug AS slug',
                'c.kind AS kind',
                'c.updatedAt AS updatedAt',
                'cp.verdict AS verdict',
                'cp.rank AS position',
                'p.id AS productId',
                'p.retailerProductId AS retailerProductId',
                'p.ean AS ean',
                'p.label AS label',
                'p.contentValue AS contentValue',
                'p.contentUnit AS contentUnit',
            )
            ->from(Choice::class, 'c')
            // Le magasin courant : celui des réglages de l'utilisateur
            ->innerJoin(DriveSettings::class, 's', 'WITH', 's.userId = c.userId AND s.retailer = c.retailer AND s.storeId = c.storeId')
            ->leftJoin(ChoiceProduct::class, 'cp', 'WITH', 'cp.choiceId = c.id')
            ->leftJoin(Product::class, 'p', 'WITH', 'p.id = cp.productId')
            ->where('c.userId = :userId')
            ->andWhere('c.retailer = :retailer')
            ->setParameter('userId', $userId)
            ->setParameter('retailer', $retailer->value)
            ->orderBy('c.ingredientSlug')
            ->addOrderBy('cp.rank')
            ->addOrderBy('p.label')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $choices[$row['slug']] ??= ['row' => $row, 'accepted' => [], 'rejected' => []];
            if ($row['productId'] === null) {
                continue;
            }
            $product = $this->product($row);
            if ($row['verdict'] === self::ACCEPTED) {
                $choices[$row['slug']]['accepted'][] = new AcceptedProductDTO((int) $row['position'], $product);
            } else {
                $choices[$row['slug']]['rejected'][] = $product;
            }
        }

        $names = $this->ingredientNameLoader->namesBySlug(array_map('strval', array_keys($choices)));

        $result = [];
        foreach ($choices as $slug => $choice) {
            $result[] = new ChoiceDTO(
                ingredientSlug: (string) $slug,
                ingredientName: $names[$slug] ?? (string) $slug,
                haveAtHome: $choice['row']['kind'] === ChoiceKind::HAVE_AT_HOME->value,
                accepted: $choice['accepted'],
                rejected: $choice['rejected'],
                updatedAt: $choice['row']['updatedAt']->format(DATE_ATOM),
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function product(array $row): ChoiceProductDTO
    {
        return new ChoiceProductDTO(
            productId: $row['productId'],
            retailerProductId: $row['retailerProductId'],
            ean: $row['ean'],
            label: $row['label'],
            content: $row['contentValue'] === null ? null : new ProductContentDTO((float) $row['contentValue'], $row['contentUnit']),
        );
    }
}
