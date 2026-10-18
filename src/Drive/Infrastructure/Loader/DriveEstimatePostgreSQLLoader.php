<?php

namespace Repas\Drive\Infrastructure\Loader;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Application\Loader\DriveEstimateLoader;
use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateDTO;
use Repas\Drive\Application\Query\GetDriveEstimate\DriveEstimateLineDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\EstimatedLine;
use Repas\Drive\Domain\Model\EstimateLineInput;
use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PreferredProduct;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\ProductPrice;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Service\ListCostEstimator;
use Repas\Drive\Infrastructure\Entity\Choice;
use Repas\Drive\Infrastructure\Entity\ChoiceProduct;
use Repas\Drive\Infrastructure\Entity\Product;
use Repas\Shared\Application\Dto\ShoppingListLineForDrive;
use Repas\Shared\Application\Interface\ShoppingListForDriveLoader;
use Repas\Shared\Domain\Model\Dimension;

/**
 * Quatre requêtes au plus, quelle que soit la taille de la liste : les réglages, la liste avec ses lignes (port `Shared`, deux
 * requêtes), puis les préférences du magasin courant pour les ingrédients de la liste avec le prix des produits acceptés. Le
 * calcul (statuts, total, couverture) est celui du service de domaine `ListCostEstimator` ; ici on ne fait que lire et mettre en
 * forme. Seuls les produits des préférences de l'utilisateur sont lus (décision D22).
 */
final readonly class DriveEstimatePostgreSQLLoader implements DriveEstimateLoader
{
    /** Une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer`. */
    private const Retailer RETAILER = Retailer::LECLERC;
    private const string ACCEPTED = 'ACCEPTED';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DriveSettingsLoader $settings,
        private ShoppingListForDriveLoader $shoppingLists,
        private ListCostEstimator $estimator,
    ) {
    }

    public function get(string $userId, string $shoppingListId): DriveEstimateDTO
    {
        $store = $this->settings->get($userId)->store ?? throw DriveException::notConfigured();
        $list = $this->shoppingLists->get($shoppingListId);
        if ($list->ownerId !== $userId) {
            throw DriveException::shoppingListForbidden();
        }

        $choices = $this->choices($userId, $store->id, array_map(static fn(ShoppingListLineForDrive $line): string => $line->ingredientSlug, $list->lines));
        $estimate = $this->estimator->estimate(array_map(fn(ShoppingListLineForDrive $line): EstimateLineInput => $this->input($line, $choices[$line->ingredientSlug] ?? null), $list->lines));

        return new DriveEstimateDTO(
            shoppingListId: $list->id,
            retailer: self::RETAILER->value,
            store: $store,
            estimatedTotalCents: $estimate->totalCents,
            estimatedLines: $estimate->estimatedLines,
            unestimatedLines: $estimate->unestimatedLines,
            coverage: $estimate->coverage,
            oldestPriceAt: $estimate->oldestPriceAt?->format(DATE_ATOM),
            newestPriceAt: $estimate->newestPriceAt?->format(DATE_ATOM),
            lines: array_map(static fn(EstimatedLine $line): DriveEstimateLineDTO => new DriveEstimateLineDTO(
                $line->ingredientSlug,
                $line->ingredientName,
                $line->productLabel,
                $line->packs,
                $line->unitPriceCents,
                $line->subtotalCents,
                $line->priceSeenAt?->format(DATE_ATOM),
                $line->status->value,
            ), $estimate->lines),
            disclaimer: DriveEstimateDTO::DISCLAIMER,
        );
    }

    /**
     * Pour chaque ingrédient qui a une préférence dans le magasin : « j'en ai toujours » ou le produit préféré (le mieux classé
     * des produits acceptés). Un produit introuvable dans le catalogue est ignoré.
     *
     * @param list<string> $slugs
     * @return array<string, array{haveAtHome: bool, product: ?PreferredProduct}>
     */
    private function choices(string $userId, string $storeId, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'c.ingredientSlug AS slug',
                'c.kind AS kind',
                'p.label AS label',
                'p.contentValue AS contentValue',
                'p.contentUnit AS contentUnit',
                'p.priceCents AS priceCents',
                'p.priceSeenAt AS priceSeenAt',
            )
            ->from(Choice::class, 'c')
            ->leftJoin(ChoiceProduct::class, 'cp', 'WITH', 'cp.choiceId = c.id AND cp.verdict = :accepted')
            ->leftJoin(Product::class, 'p', 'WITH', 'p.id = cp.productId')
            ->where('c.userId = :userId')
            ->andWhere('c.retailer = :retailer')
            ->andWhere('c.storeId = :storeId')
            ->andWhere('c.ingredientSlug IN (:slugs)')
            ->setParameter('accepted', self::ACCEPTED)
            ->setParameter('userId', $userId)
            ->setParameter('retailer', self::RETAILER->value)
            ->setParameter('storeId', $storeId)
            ->setParameter('slugs', $slugs)
            ->orderBy('cp.rank')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $choices[$row['slug']] ??= ['haveAtHome' => $row['kind'] === ChoiceKind::HAVE_AT_HOME->value, 'product' => null];
            if ($choices[$row['slug']]['product'] === null && $row['label'] !== null) {
                $choices[$row['slug']]['product'] = $this->product($row);
            }
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function product(array $row): PreferredProduct
    {
        return new PreferredProduct(
            label: $row['label'],
            content: $row['contentValue'] === null ? null : new ProductContent((float) $row['contentValue'], ContentUnit::from($row['contentUnit'])),
            price: $row['priceCents'] === null ? null : new ProductPrice($row['priceCents']),
            priceSeenAt: $row['priceSeenAt'] instanceof DateTimeImmutable ? $row['priceSeenAt'] : null,
        );
    }

    /**
     * @param array{haveAtHome: bool, product: ?PreferredProduct}|null $choice
     */
    private function input(ShoppingListLineForDrive $line, ?array $choice): EstimateLineInput
    {
        $dimension = $line->unitDimension === null ? null : Dimension::from($line->unitDimension);

        return new EstimateLineInput(
            $line->ingredientSlug,
            $line->ingredientName,
            new LineQuantity($line->quantity, $dimension, $line->unitBaseFactor),
            $choice['haveAtHome'] ?? false,
            $choice['product'] ?? null,
        );
    }
}
