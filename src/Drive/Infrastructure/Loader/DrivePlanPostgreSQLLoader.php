<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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
use Repas\Drive\Application\Query\ListChoices\PackSizeDTO;
use Repas\Drive\Application\Query\ListChoices\ProductContentDTO;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\ContentUnit;
use Repas\Drive\Domain\Model\LineQuantity;
use Repas\Drive\Domain\Model\PackSize;
use Repas\Drive\Domain\Model\PlanLineStatus;
use Repas\Drive\Domain\Model\ProductContent;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Service\PackCalculator;
use Repas\Drive\Infrastructure\Entity\Choice;
use Repas\Drive\Infrastructure\Entity\ChoiceProduct;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Drive\Infrastructure\Entity\Product;
use Repas\Drive\Domain\Model\ShoppingListLineForDrive;
use Repas\Drive\Application\Port\ShoppingListQueryPort;
use Repas\Drive\Domain\Model\Dimension;

/**
 * Cinq requêtes au plus, quelle que soit la taille de la liste : les réglages, la liste avec ses lignes (port `Shared`, deux
 * requêtes), les préférences du magasin courant pour les ingrédients de la liste avec leurs produits, puis les rayons de l'enseigne
 * appris pour les rayons de Repas des lignes (`rayonHints`, DRV-642 ; aucune requête si aucune ligne n'a de rayon de Repas). Seuls
 * les produits de ces préférences sont lus (décision D22). Les préférences d'un autre magasin ne comptent pas : un produit connu
 * dont le magasin a changé n'existe plus, la ligne est `NEW`. Les rayons appris, eux, valent pour l'enseigne (pas pour un magasin).
 * La recherche mémorisée (DRV-650) vient de la préférence du magasin courant : jamais d'une autre enseigne ni d'un autre magasin.
 * La contenance dans l'unité de la liste (DRV-652) vient de la ligne du produit dans cette même préférence (colonnes de
 * `drive_choice_product`) : aucune requête de plus, et `PackCalculator` ne s'en sert que pour l'unité exacte de la ligne.
 * Le libellé d'un produit, accepté ou refusé, est celui que le compte a lui-même lu (sa ligne de produit) ; la fiche commune ne sert que
 * de repli à une ligne qui n'a pas le sien. Le contenu net et le code-barres restent ceux de la fiche (faits publics du catalogue).
 */
final readonly class DrivePlanPostgreSQLLoader implements DrivePlanLoader
{
    private const string SENT = 'SENT';
    private const string ACCEPTED = 'ACCEPTED';
    private const int UNIT_LABEL_MAX_LENGTH = 32;
    /** Nombre de rayons de l'enseigne que le plan propose pour une ligne. */
    public const int RAYON_HINTS_MAX = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShoppingListQueryPort $shoppingLists,
        private PackCalculator $packCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function get(string $userId, string $shoppingListId, Retailer $retailer): DrivePlanDTO
    {
        $store = $this->store($userId, $retailer) ?? throw DriveException::notConfigured();
        $list = $this->shoppingLists->get($shoppingListId);
        if ($list->ownerId !== $userId) {
            throw DriveException::shoppingListForbidden();
        }
        if ($list->status !== self::SENT) {
            throw DriveException::shoppingListNotSent();
        }

        $choices = $this->choices($userId, $retailer, $store->id, array_map(static fn(ShoppingListLineForDrive $line): string => $line->ingredientSlug, $list->lines));

        $hints = $this->rayonHints($retailer, $list->lines);

        $lines = array_map(
            fn(ShoppingListLineForDrive $line): DrivePlanLineDTO => $this->line($line, $choices[$line->ingredientSlug] ?? null, $line->departmentSlug === null ? [] : ($hints[$line->departmentSlug] ?? [])),
            $list->lines,
        );
        $count = static fn(PlanLineStatus $status): int => count(array_filter($lines, static fn(DrivePlanLineDTO $line): bool => $line->status === $status->value));

        return new DrivePlanDTO(
            shoppingListId: $list->id,
            listVersion: $list->version,
            retailer: $retailer->value,
            store: $store,
            generatedAt: $this->clock->now()->format(DATE_ATOM),
            counts: new DrivePlanCountsDTO($count(PlanLineStatus::KNOWN), $count(PlanLineStatus::NEW), $count(PlanLineStatus::HAVE_AT_HOME)),
            lines: $lines,
        );
    }

    private function store(string $userId, Retailer $retailer): ?DriveStoreDTO
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('s.storeId AS id', 's.storeLabel AS label', 's.baseUrl AS baseUrl')
            ->from(DriveSettings::class, 's')
            ->where('s.userId = :userId')
            ->andWhere('s.retailer = :retailer')
            ->setParameter('userId', $userId)
            ->setParameter('retailer', $retailer->value)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : new DriveStoreDTO($row['id'], $row['label'], $row['baseUrl']);
    }

    /**
     * Les préférences du magasin pour ces ingrédients, par slug : `haveAtHome`, produits acceptés par rang (avec leur contenu),
     * produits refusés par libellé. Un produit introuvable dans le catalogue est ignoré.
     *
     * @param list<string> $slugs
     * @return array<string, array{haveAtHome: bool, searchQuery: ?string, accepted: list<array<string, mixed>>, rejected: list<DrivePlanRejectedDTO>}>
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
                'c.searchQuery AS searchQuery',
                'cp.verdict AS verdict',
                'cp.rank AS position',
                'p.retailerProductId AS retailerProductId',
                'p.ean AS ean',
                'p.retailerOfferId AS offerId',
                // Le libellé que le compte a lui-même lu (sa ligne de produit), sinon celui de la fiche commune
                'COALESCE(cp.label, p.label) AS label',
                'p.contentValue AS contentValue',
                'p.contentUnit AS contentUnit',
                'cp.packSizeValue AS packSizeValue',
                'cp.packSizeUnitSlug AS packSizeUnitSlug',
            )
            ->from(Choice::class, 'c')
            ->leftJoin(ChoiceProduct::class, 'cp', 'WITH', 'cp.choiceId = c.id')
            ->leftJoin(Product::class, 'p', 'WITH', 'p.id = cp.productId')
            ->where('c.userId = :userId')
            ->andWhere('c.retailer = :retailer')
            ->andWhere('c.storeId = :storeId')
            ->andWhere('c.ingredientSlug IN (:slugs)')
            ->setParameter('userId', $userId)
            ->setParameter('retailer', $retailer->value)
            ->setParameter('storeId', $storeId)
            ->setParameter('slugs', $slugs)
            ->orderBy('cp.rank')
            ->addOrderBy('label')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $slug = $row['slug'];
            $haveAtHome = $row['kind'] === ChoiceKind::HAVE_AT_HOME->value;
            // La recherche mémorisée est celle du choix de cette enseigne et de ce magasin (filtre de la requête) ; « j'en ai toujours » ne cherche rien
            $choices[$slug] ??= ['haveAtHome' => $haveAtHome, 'searchQuery' => $haveAtHome ? null : $row['searchQuery'], 'accepted' => [], 'rejected' => []];
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
     * Les rayons de l'enseigne les plus choisis pour chaque rayon de Repas des lignes (`RAYON_HINTS_MAX` au plus, du plus choisi au
     * moins choisi, à égalité le plus petit identifiant d'abord : un ordre qui ne change pas d'un appel à l'autre). « Choisi » se compte
     * en comptes distincts : chaque compte a au plus un vote par rayon (`drive_rayon_vote`), quel que soit le nombre de ses envois. Une
     * seule requête, et pas de lecture sans rayon de Repas ; le classement se fait en SQL pour ne lire que ce qui sort dans le plan.
     *
     * @param list<ShoppingListLineForDrive> $lines
     *
     * @return array<string, list<int>> identifiants de rayon de l'enseigne par slug de rayon de Repas ; absent si rien n'est appris
     */
    private function rayonHints(Retailer $retailer, array $lines): array
    {
        $departmentSlugs = array_values(array_unique(array_filter(array_map(static fn(ShoppingListLineForDrive $line): ?string => $line->departmentSlug, $lines))));
        if ($departmentSlugs === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT department_slug, rayon_id FROM ('
            . 'SELECT department_slug, rayon_id, ROW_NUMBER() OVER (PARTITION BY department_slug ORDER BY count(*) DESC, rayon_id ASC) AS place '
            . 'FROM drive_rayon_vote WHERE retailer = ? AND department_slug IN (?) GROUP BY department_slug, rayon_id'
            . ') AS ranked WHERE place <= ? ORDER BY department_slug, place',
            [$retailer->value, $departmentSlugs, self::RAYON_HINTS_MAX],
            [ParameterType::STRING, ArrayParameterType::STRING, ParameterType::INTEGER],
        );

        $hints = [];
        foreach ($rows as $row) {
            $hints[(string) $row['department_slug']][] = (int) $row['rayon_id'];
        }

        return $hints;
    }

    /**
     * @param array{haveAtHome: bool, searchQuery: ?string, accepted: list<array<string, mixed>>, rejected: list<DrivePlanRejectedDTO>}|null $choice
     * @param list<int> $rayonHints
     */
    private function line(ShoppingListLineForDrive $line, ?array $choice, array $rayonHints): DrivePlanLineDTO
    {
        $dimension = $line->unitDimension === null ? null : Dimension::from($line->unitDimension);
        $quantity = new LineQuantity($line->quantity, $dimension, $line->unitBaseFactor, $line->unitSlug);
        $status = match (true) {
            $choice !== null && $choice['haveAtHome'] => PlanLineStatus::HAVE_AT_HOME,
            $choice !== null && $choice['accepted'] !== [] => PlanLineStatus::KNOWN,
            default => PlanLineStatus::NEW,
        };

        return new DrivePlanLineDTO(
            ingredientSlug: $line->ingredientSlug,
            ingredientName: $line->ingredientName,
            needed: new DrivePlanNeededDTO($line->quantity, $this->unitLabel($line), $line->unitDimension, $line->unitBaseFactor, $line->unitSlug, $line->unitName),
            status: $status->value,
            products: array_map(fn(array $row): DrivePlanProductDTO => $this->product($row, $quantity), $choice['accepted'] ?? []),
            rejected: $choice['rejected'] ?? [],
            // La recherche qui a fait trouver le produit choisi (DRV-650), sinon le nom de l'ingrédient ; le nom affiché ne change pas
            searchQuery: $choice['searchQuery'] ?? $line->ingredientName,
            rayonHints: $rayonHints,
        );
    }

    /**
     * Le protocole de l'agent veut un texte non vide de 32 caractères au plus (`plan.schema.json`) : à défaut de symbole (l'unité
     * « unité » n'en a pas), le nom de l'unité, puis son slug.
     */
    private function unitLabel(ShoppingListLineForDrive $line): string
    {
        foreach ([$line->unitSymbol, $line->unitName, $line->unitSlug] as $candidate) {
            if (trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, self::UNIT_LABEL_MAX_LENGTH);
            }
        }

        return $line->unitSlug;
    }

    /**
     * Chaque produit a son propre nombre de produits : les contenus (et les contenances données par la personne, DRV-652) diffèrent
     * d'un produit à l'autre.
     *
     * @param array<string, mixed> $row
     */
    private function product(array $row, LineQuantity $quantity): DrivePlanProductDTO
    {
        $content = $row['contentValue'] === null ? null : new ProductContent((float) $row['contentValue'], ContentUnit::from($row['contentUnit']));
        $packSize = $row['packSizeValue'] === null || $row['packSizeUnitSlug'] === null ? null : PackSize::load((float) $row['packSizeValue'], $row['packSizeUnitSlug']);
        $packs = $this->packCalculator->packsFor($quantity, $content, $packSize);

        return new DrivePlanProductDTO(
            rank: (int) $row['position'],
            retailerProductId: $row['retailerProductId'],
            ean: $row['ean'],
            label: $row['label'],
            content: $content === null ? null : new ProductContentDTO($content->value, $content->unit->value),
            packs: $packs->packs,
            packsStatus: $packs->status->value,
            packSize: $packSize === null ? null : new PackSizeDTO($packSize->value, $packSize->unitSlug),
            offerId: $row['offerId'],
        );
    }
}
