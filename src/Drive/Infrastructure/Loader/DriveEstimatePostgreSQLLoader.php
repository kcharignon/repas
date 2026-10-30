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
use Repas\Drive\Domain\Model\PackSize;
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
 * forme. Seuls les produits des préférences de l'utilisateur sont lus (décision D22). Le libellé et le prix sont ceux que le compte a
 * lui-même observés (sa ligne de produit) ; la fiche commune ne sert que de repli à une ligne qui n'a pas les siens.
 */
final readonly class DriveEstimatePostgreSQLLoader implements DriveEstimateLoader
{
    private const string ACCEPTED = 'ACCEPTED';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DriveSettingsLoader $settings,
        private ShoppingListForDriveLoader $shoppingLists,
        private ListCostEstimator $estimator,
    ) {
    }

    public function get(string $userId, string $shoppingListId, Retailer $retailer): DriveEstimateDTO
    {
        $store = $this->settings->get($userId, $retailer)->store ?? throw DriveException::notConfigured();
        $list = $this->shoppingLists->get($shoppingListId);
        if ($list->ownerId !== $userId) {
            throw DriveException::shoppingListForbidden();
        }

        $choices = $this->choices($userId, $retailer, $store->id, array_map(static fn(ShoppingListLineForDrive $line): string => $line->ingredientSlug, $list->lines));
        $estimate = $this->estimator->estimate(array_map(fn(ShoppingListLineForDrive $line): EstimateLineInput => $this->input($line, $choices[$line->ingredientSlug] ?? null), $list->lines));

        return new DriveEstimateDTO(
            shoppingListId: $list->id,
            retailer: $retailer->value,
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
    private function choices(string $userId, Retailer $retailer, string $storeId, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'c.ingredientSlug AS slug',
                'c.kind AS kind',
                'p.id AS productId',
                // Les valeurs de la ligne du compte d'abord, la fiche commune en repli : le libellé en SQL, le prix avec sa date par paire
                // (jamais le prix d'un côté et la date de l'autre) dans `product()`
                'COALESCE(cp.label, p.label) AS label',
                'p.contentValue AS contentValue',
                'p.contentUnit AS contentUnit',
                'cp.priceCents AS ownPriceCents',
                'cp.priceSeenAt AS ownPriceSeenAt',
                'p.priceCents AS sharedPriceCents',
                'p.priceSeenAt AS sharedPriceSeenAt',
                'cp.packSizeValue AS packSizeValue',
                'cp.packSizeUnitSlug AS packSizeUnitSlug',
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
            ->setParameter('retailer', $retailer->value)
            ->setParameter('storeId', $storeId)
            ->setParameter('slugs', $slugs)
            ->orderBy('cp.rank')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $choices[$row['slug']] ??= ['haveAtHome' => $row['kind'] === ChoiceKind::HAVE_AT_HOME->value, 'product' => null];
            if ($choices[$row['slug']]['product'] === null && $row['productId'] !== null) {
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
        // Le prix que le compte a lui-même observé (avec sa date), sinon celui de la fiche commune (avec la sienne) : les deux vont ensemble
        $own = $row['ownPriceCents'] !== null && $row['ownPriceSeenAt'] instanceof DateTimeImmutable;
        $priceCents = $own ? $row['ownPriceCents'] : $row['sharedPriceCents'];
        $priceSeenAt = $own ? $row['ownPriceSeenAt'] : $row['sharedPriceSeenAt'];

        return new PreferredProduct(
            label: $row['label'],
            content: $row['contentValue'] === null ? null : new ProductContent((float) $row['contentValue'], ContentUnit::from($row['contentUnit'])),
            price: $priceCents === null ? null : new ProductPrice($priceCents),
            priceSeenAt: $priceSeenAt instanceof DateTimeImmutable ? $priceSeenAt : null,
            // La contenance donnée par la personne pour ce produit, dans l'unité de la liste (DRV-652) : la même règle que le plan
            packSize: $row['packSizeValue'] === null || $row['packSizeUnitSlug'] === null ? null : PackSize::load((float) $row['packSizeValue'], $row['packSizeUnitSlug']),
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
            new LineQuantity($line->quantity, $dimension, $line->unitBaseFactor, $line->unitSlug),
            $choice['haveAtHome'] ?? false,
            $choice['product'] ?? null,
        );
    }
}
