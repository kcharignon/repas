<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Repas\Drive\Application\Loader\DrivePlanLoader;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanCountsDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanLineDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanNeededDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanProductDTO;
use Repas\Drive\Application\Query\GetDrivePlan\DrivePlanRejectedDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PlanLineStatus;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Drive\Infrastructure\Entity\Choice;
use Repas\Drive\Infrastructure\Entity\ChoiceProduct;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Drive\Infrastructure\Entity\Product;
use Repas\Shared\Application\Dto\ShoppingListLineForDrive;
use Repas\Shared\Application\Interface\ShoppingListForDriveLoader;
use Repas\Shared\Domain\Model\Dimension;

/**
 * Quatre requêtes au plus, quelle que soit la taille de la liste : les réglages, la liste avec ses lignes (port `Shared`, deux
 * requêtes), puis les préférences du magasin courant pour les ingrédients de la liste avec leurs produits. Seuls les produits
 * de ces préférences sont lus (décision D22). Les préférences d'un autre magasin ne comptent pas : un produit connu dont le
 * magasin a changé n'existe plus, la ligne est `NEW`.
 */
final readonly class DrivePlanPostgreSQLLoader implements DrivePlanLoader
{
    /** Une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer`. */
    private const Retailer RETAILER = Retailer::LECLERC;
    private const string SENT = 'SENT';
    private const string ACCEPTED = 'ACCEPTED';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShoppingListForDriveLoader $shoppingLists,
        private PackCalculator $packCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function get(string $userId, string $shoppingListId): DrivePlanDTO
    {
        $store = $this->store($userId) ?? throw DriveException::notConfigured();
        $list = $this->shoppingLists->get($shoppingListId);
        if ($list->ownerId !== $userId) {
            throw DriveException::shoppingListForbidden();
        }
        if ($list->status !== self::SENT) {
            throw DriveException::shoppingListNotSent();
        }

        $choices = $this->choices($userId, $store->id, array_map(static fn(ShoppingListLineForDrive $line): string => $line->ingredientSlug, $list->lines));

        $lines = array_map(fn(ShoppingListLineForDrive $line): DrivePlanLineDTO => $this->line($line, $choices[$line->ingredientSlug] ?? null), $list->lines);
        $count = static fn(PlanLineStatus $status): int => count(array_filter($lines, static fn(DrivePlanLineDTO $line): bool => $line->status === $status->value));

        return new DrivePlanDTO(
            shoppingListId: $list->id,
            listVersion: $list->version,
            retailer: self::RETAILER->value,
            store: $store,
            generatedAt: $this->clock->now()->format(DATE_ATOM),
            counts: new DrivePlanCountsDTO($count(PlanLineStatus::KNOWN), $count(PlanLineStatus::NEW), $count(PlanLineStatus::HAVE_AT_HOME)),
            lines: $lines,
        );
    }

    private function store(string $userId): ?DriveStoreDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('s.storeId AS id', 's.storeLabel AS label', 's.baseUrl AS baseUrl')
            ->from(DriveSettings::class, 's')
            ->where('s.userId = :userId')
            ->andWhere('s.retailer = :retailer')
            ->setParameter('userId', $userId)
            ->setParameter('retailer', self::RETAILER->value)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : new DriveStoreDTO($row['id'], $row['label'], $row['baseUrl']);
    }

    /**
     * Les préférences du magasin pour ces ingrédients, par slug : `haveAtHome`, produits acceptés par rang (avec leur contenu),
     * produits refusés par libellé. Un produit introuvable dans le catalogue est ignoré.
     *
     * @param list<string> $slugs
     * @return array<string, array{haveAtHome: bool, accepted: list<array<string, mixed>>, rejected: list<DrivePlanRejectedDTO>}>
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
                'cp.verdict AS verdict',
                'cp.rank AS position',
                'p.retailerProductId AS retailerProductId',
                'p.ean AS ean',
                'p.label AS label',
                'p.contentValue AS contentValue',
                'p.contentUnit AS contentUnit',
            )
            ->from(Choice::class, 'c')
            ->leftJoin(ChoiceProduct::class, 'cp', 'WITH', 'cp.choiceId = c.id')
            ->leftJoin(Product::class, 'p', 'WITH', 'p.id = cp.productId')
            ->where('c.userId = :userId')
            ->andWhere('c.retailer = :retailer')
            ->andWhere('c.storeId = :storeId')
            ->andWhere('c.ingredientSlug IN (:slugs)')
            ->setParameter('userId', $userId)
            ->setParameter('retailer', self::RETAILER->value)
            ->setParameter('storeId', $storeId)
            ->setParameter('slugs', $slugs)
            ->orderBy('cp.rank')
            ->addOrderBy('p.label')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $slug = $row['slug'];
            $choices[$slug] ??= ['haveAtHome' => $row['kind'] === ChoiceKind::HAVE_AT_HOME->value, 'accepted' => [], 'rejected' => []];
            if ($row['retailerProductId'] === null) {
                continue;
            }
            if ($row['verdict'] === self::ACCEPTED) {
                $choices[$slug]['accepted'][] = $row;
            } else {
                $choices[$slug]['rejected'][] = new DrivePlanRejectedDTO($row['retailerProductId'], $row['label']);
            }
        }

        return $choices;
    }

    /**
     * @param array{haveAtHome: bool, accepted: list<array<string, mixed>>, rejected: list<DrivePlanRejectedDTO>}|null $choice
     */
    private function line(ShoppingListLineForDrive $line, ?array $choice): DrivePlanLineDTO
    {
        $dimension = $line->unitDimension === null ? null : Dimension::from($line->unitDimension);
        $quantity = new LineQuantity($line->quantity, $dimension, $line->unitBaseFactor);
        $status = match (true) {
            $choice !== null && $choice['haveAtHome'] => PlanLineStatus::HAVE_AT_HOME,
            $choice !== null && $choice['accepted'] !== [] => PlanLineStatus::KNOWN,
            default => PlanLineStatus::NEW,
        };

        return new DrivePlanLineDTO(
            ingredientSlug: $line->ingredientSlug,
            ingredientName: $line->ingredientName,
            needed: new DrivePlanNeededDTO($line->quantity, $line->unitSymbol, $line->unitDimension, $line->unitBaseFactor),
            status: $status->value,
            products: array_map(fn(array $row): DrivePlanProductDTO => $this->product($row, $quantity), $choice['accepted'] ?? []),
            rejected: $choice['rejected'] ?? [],
            searchQuery: $line->ingredientName,
        );
    }

    /**
     * Chaque produit a son propre nombre de produits : les contenus diffèrent d'un produit à l'autre.
     *
     * @param array<string, mixed> $row
     */
    private function product(array $row, LineQuantity $quantity): DrivePlanProductDTO
    {
        $content = $row['contentValue'] === null ? null : new ProductContent((float) $row['contentValue'], ContentUnit::from($row['contentUnit']));
        $packs = $this->packCalculator->packsFor($quantity, $content);

        return new DrivePlanProductDTO(
            rank: (int) $row['position'],
            retailerProductId: $row['retailerProductId'],
            ean: $row['ean'],
            label: $row['label'],
            content: $content === null ? null : new ProductContentDTO($content->value, $content->unit->value),
            packs: $packs->packs,
            packsStatus: $packs->status->value,
        );
    }
}
