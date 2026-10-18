<?php

namespace Repas\Drive\Infrastructure\Loader;

use Doctrine\ORM\EntityManagerInterface;
use Repas\Drive\Application\Loader\DriveSettingsLoader;
use Repas\Drive\Application\Query\GetDriveSettings\DriveSettingsDTO;
use Repas\Drive\Application\Query\GetDriveSettings\DriveStoreDTO;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Infrastructure\Entity\DriveSettings;
use Repas\Shared\Application\Interface\DriveFeature;

final readonly class DriveSettingsPostgreSQLLoader implements DriveSettingsLoader
{
    /** Une seule enseigne pour l'instant : la lecture ne porte pas encore de paramètre `retailer`. */
    private const Retailer RETAILER = Retailer::LECLERC;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DriveFeature $driveFeature,
    ) {
    }

    public function get(string $userId): DriveSettingsDTO
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

        return new DriveSettingsDTO(
            enabled: $this->driveFeature->isEnabled(),
            retailer: self::RETAILER->value,
            store: $row === null ? null : new DriveStoreDTO($row['id'], $row['label'], $row['baseUrl']),
        );
    }
}
