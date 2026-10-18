<?php

namespace Repas\Drive\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Drive\Domain\Interface\DriveSettingsRepository;
use Repas\Drive\Domain\Model\DriveSettings;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\Store;
use Repas\Drive\Infrastructure\Entity\DriveSettings as DriveSettingsEntity;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

readonly class DriveSettingsPostgreSQLRepository extends PostgreSQLRepository implements DriveSettingsRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
    ) {
        parent::__construct($managerRegistry, DriveSettingsEntity::class);
    }

    public function findOneByUser(string $userId, Retailer $retailer): ?DriveSettings
    {
        $entity = $this->findEntity($userId, $retailer);

        return $entity === null ? null : $this->convertEntityToModel($entity);
    }

    public function save(DriveSettings $settings): void
    {
        $entity = $this->findEntity($settings->getUserId(), $settings->getRetailer());

        if ($entity === null) {
            $this->entityManager->persist(DriveSettingsEntity::fromModel($settings));
        } else {
            $entity->updateFromModel($settings);
        }

        $this->entityManager->flush();
    }

    public function deleteByUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(DriveSettingsEntity::class, 's')
            ->where('s.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->execute();

        // Les lignes supprimées par DQL restent dans l'identity map : un `find()` ultérieur les rendrait comme vivantes
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[DriveSettingsEntity::class] ?? [] as $entity) {
            if ($entity->getUserId() === $userId) {
                $this->entityManager->detach($entity);
            }
        }
    }

    private function findEntity(string $userId, Retailer $retailer): ?DriveSettingsEntity
    {
        $entity = $this->entityRepository->find(['userId' => $userId, 'retailer' => $retailer->value]);

        return $entity instanceof DriveSettingsEntity ? $entity : null;
    }

    private function convertEntityToModel(DriveSettingsEntity $entity): DriveSettings
    {
        return DriveSettings::load([
            'user_id' => $entity->getUserId(),
            'retailer' => Retailer::from($entity->getRetailer()),
            'store' => Store::load([
                'id' => $entity->getStoreId(),
                'label' => $entity->getStoreLabel(),
                'base_url' => $entity->getBaseUrl(),
            ]),
            'updated_at' => $entity->getUpdatedAt(),
            'product_writes_day' => $entity->getProductWritesDay(),
            'product_writes_count' => $entity->getProductWritesCount(),
        ]);
    }
}
