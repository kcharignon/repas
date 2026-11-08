<?php

namespace Repas\Repas\Infrastructure\Repository;


use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Model\RecipeComponent as RecipeComponentModel;
use Repas\Repas\Infrastructure\Entity\RecipeComponent as RecipeComponentEntity;
use Repas\Shared\Domain\Tool\Tab;
use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;

/**
 * Les arêtes « cette recette en utilise une autre » (ADR 0040), lues et écrites par `RecipePostgreSQLRepository` seulement : un composant n'existe
 * que dans sa recette composée, il n'a pas de port à lui. Aucune clé étrangère : une arête dont une des recettes n'existe plus est lue sans erreur
 * (le modèle ne porte que des identifiants).
 */
readonly class RecipeComponentPostgreSQLRepository extends PostgreSQLRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, RecipeComponentEntity::class);
    }

    /**
     * Les composants de la recette dans l'ordre de leurs positions : UNE requête (l'index unique `(recipe, component)` couvre la recherche).
     *
     * @return Tab<RecipeComponentModel>
     */
    public function findByRecipeId(string $recipeId): Tab
    {
        $entities = $this->entityRepository->findBy(['recipeId' => $recipeId], ['position' => 'ASC', 'id' => 'ASC']);

        return new Tab(
            array_map(static fn(RecipeComponentEntity $entity): RecipeComponentModel => RecipeComponentModel::load([
                'id' => $entity->getId(),
                'recipe_id' => $entity->getRecipeId(),
                'component_id' => $entity->getComponentId(),
                'multiplier' => $entity->getMultiplier(),
                'position' => $entity->getPosition(),
            ]), $entities),
            RecipeComponentModel::class,
        );
    }

    /**
     * Remplace les composants de la recette par ceux-là : les arêtes absentes sont effacées AVANT que les autres soient écrites (une arête d'une
     * paire connue sous un autre identifiant partirait sinon contre l'index unique), puis chacune est écrite avec sa position. Un seul `flush`.
     *
     * @param Tab<RecipeComponentModel> $components
     */
    public function replace(string $recipeId, Tab $components): void
    {
        if ($components->empty()) {
            $this->deleteByRecipeId($recipeId);

            return;
        }

        $this->entityManager->createQueryBuilder()
            ->delete(RecipeComponentEntity::class, 'rc')
            ->where('rc.recipeId = :recipeId')
            ->andWhere('rc.id NOT IN (:ids)')
            ->setParameter('recipeId', $recipeId)
            ->setParameter('ids', array_values($components->map(static fn(RecipeComponentModel $component): string => $component->getId())->toArray()))
            ->getQuery()
            ->execute();

        $this->preload($components->map(static fn(RecipeComponentModel $component): string => $component->getId()), 'id');
        foreach ($components as $component) {
            $entity = $this->findManaged($component->getId());
            if ($entity instanceof RecipeComponentEntity) {
                $entity->updateFromModel($component);
            } else {
                $this->entityManager->persist(RecipeComponentEntity::fromModel($component));
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Écrit les composants d'une recette NEUVE : aucune arête n'existe, il n'y a rien à chercher ni à effacer (l'écriture part au `flush` de l'appelant).
     *
     * @param Tab<RecipeComponentModel> $components
     */
    public function insertAll(Tab $components): void
    {
        foreach ($components as $component) {
            $this->entityManager->persist(RecipeComponentEntity::fromModel($component));
        }
    }

    public function deleteByRecipeId(string $recipeId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(RecipeComponentEntity::class, 'rc')
            ->where('rc.recipeId = :recipeId')
            ->setParameter('recipeId', $recipeId)
            ->getQuery()
            ->execute();
    }
}
