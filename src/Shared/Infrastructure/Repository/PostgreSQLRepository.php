<?php

namespace Repas\Shared\Infrastructure\Repository;


use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Repas\Shared\Domain\Model\ModelInterface;

abstract readonly class PostgreSQLRepository
{
    protected EntityManagerInterface $entityManager;
    protected ObjectRepository $entityRepository;

    public function __construct(ManagerRegistry $managerRegistry,string $className) {
        $entityManager = $managerRegistry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Expected EntityManagerInterface, got ' . get_class($entityManager));
        }
        $this->entityManager = $entityManager;
        $this->entityRepository = $entityManager->getRepository($className);
    }

    /**
     * Lit en une seule requête les entités absentes de l'identity map de Doctrine : les `find()` qui suivent, un par
     * identifiant, ne coûtent alors plus aucune requête (préchargement par lot).
     *
     * @param iterable<string> $ids valeurs de la clé primaire
     */
    protected function preload(iterable $ids, string $field = 'slug'): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $className = $this->entityRepository->getClassName();

        $missing = [];
        foreach ($ids as $id) {
            if (!isset($missing[$id]) && $unitOfWork->tryGetById($id, $className) === false) {
                $missing[$id] = $id;
            }
        }

        if ($missing !== []) {
            $this->entityRepository->findBy([$field => array_values($missing)]);
        }
    }

    /**
     * Entité déjà connue de l'identity map, sans requête (`null` si elle n'y est pas). Après un `preload()` des mêmes
     * identifiants, `null` veut dire que l'entité n'existe pas en base : l'enregistrement peut l'insérer sans la chercher
     * une deuxième fois.
     */
    protected function findManaged(string $id): ?object
    {
        $entity = $this->entityManager->getUnitOfWork()->tryGetById($id, $this->entityRepository->getClassName());

        return $entity === false ? null : $entity;
    }

    /**
     * Contrôle de modification concurrente (ADR 0016) : augmente de 1 la version de la ligne `$id` si elle vaut encore
     * `$version`, celle que cette requête a lue. `false` : quelqu'un a enregistré entre la lecture et l'écriture, rien
     * n'a été écrit. Une requête SQL, la colonne `version` n'est écrite que par ici (jamais par le flush de Doctrine).
     */
    protected function bumpVersion(string $id, int $version): bool
    {
        $table = $this->entityManager->getConnection()->quoteSingleIdentifier(
            $this->entityManager->getClassMetadata($this->entityRepository->getClassName())->getTableName(),
        );

        return $this->entityManager->getConnection()->executeStatement(
            sprintf('UPDATE %s SET version = version + 1 WHERE id = :id AND version = :version', $table),
            ['id' => $id, 'version' => $version],
        ) === 1;
    }

    // TODO: refacto saves Methode()
//    protected function saveEntity(ModelInterface $model): void
//    {
//        $entity = $this->entityRepository->find($model->getId());
//
//        if ($entity !== null) {
//            // The entity already exists: update it
//            $entity->updateFromModel($model);
//        } else {
//            // Create a new entity from the model using the static factory method.
//            $entity = $this->getEntityClass()::fromModel($model);
//            $this->entityManager->persist($entity);
//        }
//
//        $this->entityManager->flush();
//    }
//
//    abstract protected function getEntityClass(): string;
}
