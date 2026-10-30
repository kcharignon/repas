<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveRetailerDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Shared\Application\Interface\DriveFeature;

/**
 * Une seule requête quel que soit le nombre d'enseignes : les magasins de l'utilisateur chez toutes les enseignes. Une ligne dont
 * l'enseigne n'est pas (ou plus) connue du back est ignorée. `retailers` ne liste que les enseignes allumées (`DRIVE_RETAILERS`, DRV-702),
 * dans l'ordre du registre.
 */
final readonly class DriveSettingsPostgreSQLLoader implements DriveSettingsLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DriveFeature $driveFeature,
    ) {
    }

    public function get(string $userId, Retailer $retailer): DriveSettingsDTO
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.retailer AS retailer', 's.storeId AS id', 's.storeLabel AS label', 's.baseUrl AS baseUrl')
            ->from(DriveSettings::class, 's')
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        $stores = [];
        foreach ($rows as $row) {
            $stores[$row['retailer']] = new DriveStoreDTO($row['id'], $row['label'], $row['baseUrl']);
        }

        return new DriveSettingsDTO(
            enabled: $this->driveFeature->isEnabled(),
            retailer: $retailer->value,
            store: $stores[$retailer->value] ?? null,
            // Seules les enseignes allumées (`DRIVE_RETAILERS`, DRV-702) : une enseigne éteinte n'existe pas pour l'utilisateur, ses données restent en base
            retailers: array_map(
                static fn(Retailer $known): DriveRetailerDTO => new DriveRetailerDTO($known->value, $known->profile()->name, isset($stores[$known->value]), $stores[$known->value] ?? null),
                array_values(array_filter(Retailer::cases(), fn(Retailer $known): bool => $this->driveFeature->isRetailerEnabled($known->value))),
            ),
        );
    }
}
