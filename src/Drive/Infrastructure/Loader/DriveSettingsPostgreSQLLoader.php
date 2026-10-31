<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Application\Query\GetDriveSettings\RetailerSelectionDTO;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Selection;
use Repas\Drive\Domain\Service\RetailerSelection;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Drive\Infrastructure\Entity\RetailerPreference;
use Repas\Drive\Domain\Interface\DriveFeature;

/**
 * Deux requêtes quel que soit le nombre d'enseignes : les magasins de l'utilisateur chez toutes les enseignes, et sa préférence d'enseigne
 * (drive habituel, dernière enseigne). Une ligne dont l'enseigne n'est pas (ou plus) connue du back est ignorée. `retailers` ne liste que
 * les enseignes allumées (`DRIVE_RETAILERS`, DRV-702), dans l'ordre du registre.
 *
 * La sélection (DRV-703) est celle du service de domaine `RetailerSelection` : ce loader ne fait que lire les faits (magasins et date de
 * leur enregistrement, préférence, enseignes allumées) et mettre en forme. `defaultRetailer`, `lastUsedRetailer` et `isDefault` ne montrent
 * qu'une enseigne candidate (allumée et configurée) : une préférence qui pointe vers une enseigne éteinte reste en base, mais n'existe pas
 * pour l'utilisateur tant qu'elle est éteinte.
 */
final readonly class DriveSettingsPostgreSQLLoader implements DriveSettingsLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DriveFeature $driveFeature,
        private RetailerSelection $selection,
    ) {
    }

    public function get(string $userId, ?Retailer $retailer = null): DriveSettingsDTO
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.retailer AS retailer', 's.storeId AS id', 's.storeLabel AS label', 's.baseUrl AS baseUrl', 's.updatedAt AS updatedAt')
            ->from(DriveSettings::class, 's')
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();
        $preference = $this->entityManager->createQueryBuilder()
            ->select('p.defaultRetailer AS defaultRetailer', 'p.lastUsedRetailer AS lastUsedRetailer')
            ->from(RetailerPreference::class, 'p')
            ->where('p.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        // Les magasins par enseigne connue, et les enseignes configurées dans l'ordre de leur enregistrement (à égalité, celui du registre)
        $registry = array_flip(array_map(static fn(Retailer $known): string => $known->value, Retailer::cases()));
        $known = array_values(array_filter($rows, static fn(array $row): bool => isset($registry[$row['retailer']])));
        usort($known, static fn(array $a, array $b): int => [$a['updatedAt'], $registry[$a['retailer']]] <=> [$b['updatedAt'], $registry[$b['retailer']]]);
        $stores = [];
        foreach ($known as $row) {
            $stores[$row['retailer']] = new DriveStoreDTO($row['id'], $row['label'], $row['baseUrl']);
        }

        $enabled = array_values(array_filter(Retailer::cases(), fn(Retailer $candidate): bool => $this->driveFeature->isRetailerEnabled($candidate->value)));
        $selection = $this->selection->resolve(
            $enabled,
            array_map(static fn(array $row): Retailer => Retailer::from($row['retailer']), $known),
            $this->retailerOf($preference['defaultRetailer'] ?? null),
            $this->retailerOf($preference['lastUsedRetailer'] ?? null),
        );
        $retailer ??= $this->selection->retailerToUse($selection, $enabled);
        $default = $this->candidateOf($preference['defaultRetailer'] ?? null, $selection);
        $lastUsed = $this->candidateOf($preference['lastUsedRetailer'] ?? null, $selection);

        return new DriveSettingsDTO(
            enabled: $this->driveFeature->isEnabled(),
            retailer: $retailer->value,
            store: $stores[$retailer->value] ?? null,
            // Seules les enseignes allumées (`DRIVE_RETAILERS`, DRV-702) : une enseigne éteinte n'existe pas pour l'utilisateur, ses données restent en base
            retailers: array_map(
                static fn(Retailer $one): DriveRetailerDTO => new DriveRetailerDTO($one->value, $one->profile()->name, isset($stores[$one->value]), $one === $default, $stores[$one->value] ?? null),
                $enabled,
            ),
            defaultRetailer: $default?->value,
            lastUsedRetailer: $lastUsed?->value,
            selection: new RetailerSelectionDTO(
                $selection->mode->value,
                $selection->retailer?->value,
                array_map(static fn(Retailer $candidate): string => $candidate->value, $selection->candidates),
            ),
        );
    }

    public function store(string $userId, Retailer $retailer): ?DriveStoreDTO
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

    private function retailerOf(?string $value): ?Retailer
    {
        return $value === null ? null : Retailer::tryFrom($value);
    }

    /**
     * L'enseigne enregistrée, seulement si elle est candidate (allumée et configurée).
     */
    private function candidateOf(?string $value, Selection $selection): ?Retailer
    {
        $retailer = $this->retailerOf($value);

        return $retailer !== null && in_array($retailer, $selection->candidates, true) ? $retailer : null;
    }
}
