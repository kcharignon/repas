<?php

namespace Repas\Repas\Infrastructure\Repository;


use Repas\Shared\Infrastructure\Repository\PostgreSQLRepository;
use Doctrine\Persistence\ManagerRegistry;
use Repas\Repas\Domain\Exception\RecipeException;
use Repas\Repas\Domain\Interface\RecipeRepository;
use Repas\Repas\Domain\Interface\RecipeTypeRepository;
use Repas\Repas\Domain\Model\Recipe;
use Repas\Repas\Domain\Model\RecipeComponent;
use Repas\Repas\Domain\Model\RecipeRow;
use Repas\Repas\Domain\Model\RecipeType;
use Repas\Repas\Infrastructure\Entity\Recipe as RecipeEntity;
use Repas\Repas\Infrastructure\Entity\RecipeComponent as RecipeComponentEntity;
use Repas\Repas\Infrastructure\Entity\RecipeRow as RecipeRowEntity;
use Repas\Repas\Infrastructure\Entity\RecipeType as RecipeTypeEntity;
use Repas\Shared\Domain\Tool\Tab;

readonly class RecipePostgreSQLRepository extends PostgreSQLRepository implements RecipeRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private RecipeTypeRepository $recipeTypeRepository,
        private RecipeRowPostgreSQLRepository $recipeRowRepository,
        private IngredientPostgreSQLRepository $ingredientRepository,
        private RecipeComponentPostgreSQLRepository $recipeComponentRepository,
    ) {
        parent::__construct($managerRegistry, RecipeEntity::class);
    }

    /**
     * @throws RecipeException
     */
    public function findOneById(string $id): Recipe
    {
        if (($entity = $this->entityRepository->find($id)) !== null) {
            return $this->convertEntityToModel($entity);
        }

        throw RecipeException::notFound($id);
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        // Les recettes en une requête ; un identifiant inconnu n'y est pas et n'est pas dans le résultat
        $entities = $this->entityRepository->findBy(['id' => $ids]);
        if ($entities === []) {
            return [];
        }

        // Les ingrédients (avec rayons et unités), les types et les lignes (avec leurs unités) de TOUTES les recettes : une lecture chacun, puis
        // les lectures unitaires qui suivent viennent de l'identity map
        $foundIds = array_map(static fn(RecipeEntity $entity): string => $entity->getId(), $entities);
        $this->ingredientRepository->preloadByRecipes($foundIds);
        $this->entityManager->getRepository(RecipeTypeEntity::class)->findBy(['slug' => array_values(array_unique(array_map(static fn(RecipeEntity $entity): string => $entity->getTypeSlug(), $entities)))]);
        $rows = $this->recipeRowRepository->findByRecipeIds($foundIds);

        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->getId()] = $entity;
        }

        // Dans l'ordre demandé ; sans leurs arêtes (la profondeur est 1), mais `component_count` dit que la recette en a
        $recipes = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $recipes[$id] = $this->toModel($byId[$id], $rows[$id], components: null);
            }
        }

        return $recipes;
    }

    public function findByAuthor(string $authorId): Tab
    {
        return $this->findBy(['authorId' => $authorId]);
    }

    public function findBy(array $criteria, ?array $orderBy = null): Tab
    {
        $entities = new Tab($this->entityRepository->findBy($criteria, $orderBy), RecipeEntity::class);
        return $this->convertEntitiesToModels($entities);
    }


    /**
     * @throws RecipeException une autre requête a enregistré la recette depuis sa lecture (ADR 0016)
     */
    public function save(Recipe $recipe): void
    {
        $recipeEntity = $this->entityRepository->find($recipe->getId());
        if ($recipeEntity === null) {
            $recipeEntity = RecipeEntity::fromModel($recipe);
            $this->entityManager->persist($recipeEntity);
            // Une recette neuve n'a encore aucune arête : elle écrit celles qu'elle porte
            $writeComponents = !$recipe->getComponents()->empty();
        } else {
            // Avant toute écriture : si la recette a changé depuis sa lecture, rien n'est enregistré
            if (!$this->bumpVersion($recipeEntity->getId(), $recipeEntity->getVersion())) {
                throw RecipeException::concurrentModification($recipe->getId());
            }
            $recipeEntity->setVersion($recipeEntity->getVersion() + 1);
            $recipeEntity->updateFromModel($recipe);
            // Supprime les anciennes lignes de la recette
            $this->recipeRowRepository->deleteByRecipeIdExceptIds(
                $recipeEntity->getId(),
                $recipe->getRows()->map(fn(RecipeRow $item) => $item->getId())
            );
            // Les composants (ADR 0040 décision 25) : écrits SEULEMENT si le modèle les a posés. Un modèle chargé puis enregistré sans y toucher ne
            // les lit ni ne les efface, et `component_count` n'est pas réécrit
            $writeComponents = $recipe->areComponentsChanged();
            if ($writeComponents) {
                $recipeEntity->setComponentCount($recipe->getComponents()->count());
            }
        }
        // Ajoute les nouvelles lignes ou modifie les lignes existantes
        $this->recipeRowRepository->saveAll($recipe->getRows());
        if ($writeComponents) {
            $this->recipeComponentRepository->replace($recipe->getId(), $recipe->getComponents());
        }

        $this->entityManager->flush();
    }

    public function findMarmitonNeutralId(string $marmitonId): ?string
    {
        $id = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(RecipeEntity::class, 'r')
            ->where('r.marmitonId = :marmitonId')
            ->setParameter('marmitonId', $marmitonId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $id === null ? null : $id['id'];
    }

    public function findCopyIdOf(string $originalId, string $authorId): ?string
    {
        // Du plus ancien au plus récent : s'il y en a plusieurs (deux imports simultanés d'avant le verrou), la même réponse à chaque appel
        $copy = $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(RecipeEntity::class, 'r')
            ->where('r.originalId = :originalId')
            ->andWhere('r.authorId = :authorId')
            ->orderBy('r.id', 'ASC')
            ->setParameter('originalId', $originalId)
            ->setParameter('authorId', $authorId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $copy === null ? null : $copy['id'];
    }

    /**
     * @throws RecipeException
     */
    private function convertEntityToModel(RecipeEntity $entity): Recipe
    {
        $this->ingredientRepository->preloadByRecipe($entity->getId());

        // Les arêtes ne sont lues que si la recette en a (`component_count`, lu avec la recette) : zéro composant, aucune requête de plus
        return $this->toModel(
            $entity,
            $this->recipeRowRepository->findByRecipeId($entity->getId()),
            $entity->getComponentCount() > 0 ? $this->recipeComponentRepository->findByRecipeId($entity->getId()) : null,
        );
    }

    /**
     * @param Tab<RecipeRow> $rows
     * @param Tab<RecipeComponent>|null $components `null` : les arêtes ne sont pas lues, la recette sait pourtant combien elle en a
     */
    private function toModel(RecipeEntity $entity, Tab $rows, ?Tab $components): Recipe
    {
        return Recipe::load([
            'id' => $entity->getId(),
            'name' => $entity->getName(),
            'serving' => $entity->getServing(),
            'author_id' => $entity->getAuthorId(),
            'type' => $this->recipeTypeRepository->findOneBySlug($entity->getTypeSlug()),
            'rows' => $rows,
            'original_id' => $entity->getOriginalId(),
            'version' => $entity->getVersion(),
            'secret' => $entity->isSecret(),
            'favorite' => $entity->isFavorite(),
            'marmiton_id' => $entity->getMarmitonId(),
            'source_url' => $entity->getSourceUrl(),
            'components' => $components,
            // Les arêtes lues font foi ; sinon le compte de la ligne de la recette
            'component_count' => $components?->count() ?? $entity->getComponentCount(),
        ]);
    }

    public function isIngredientUsed(string $ingredientSlug): bool
    {
        return $this->entityManager->createQueryBuilder()
            ->select('rr.recipeId')
            ->from(RecipeRowEntity::class, 'rr')
            ->where('rr.ingredientSlug = :ingredient')
            ->setParameter('ingredient', $ingredientSlug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function isUsedAsComponent(string $recipeId): bool
    {
        // Sur l'index des recettes utilisées : une ligne lue au plus
        return $this->entityManager->createQueryBuilder()
            ->select('rc.id')
            ->from(RecipeComponentEntity::class, 'rc')
            ->where('rc.componentId = :recipe')
            ->setParameter('recipe', $recipeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function isUsedByOrdinaryRecipe(string $recipeId): bool
    {
        // L'arête jointe à la recette composée, ordinaire : une ligne lue au plus. Une arête dont la recette composée n'existe plus ne compte pas
        return $this->entityManager->createQueryBuilder()
            ->select('rc.id')
            ->from(RecipeComponentEntity::class, 'rc')
            ->innerJoin(RecipeEntity::class, 'parent', 'WITH', 'parent.id = rc.recipeId')
            ->where('rc.componentId = :recipe')
            ->andWhere('parent.secret = false')
            ->setParameter('recipe', $recipeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    public function hasSecretComponent(string $recipeId): bool
    {
        // L'arête jointe à la recette utilisée, secrète : une ligne lue au plus, sur l'index unique `(recipe, component)`
        return $this->entityManager->createQueryBuilder()
            ->select('rc.id')
            ->from(RecipeComponentEntity::class, 'rc')
            ->innerJoin(RecipeEntity::class, 'used', 'WITH', 'used.id = rc.componentId')
            ->where('rc.recipeId = :recipe')
            ->andWhere('used.secret = true')
            ->setParameter('recipe', $recipeId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    private function convertEntitiesToModels(Tab $entities): Tab
    {
        return $entities->map(fn(RecipeEntity $entity) => $this->convertEntityToModel($entity), Recipe::class);
    }

    /**
     * @throws RecipeException une autre requête a enregistré la recette depuis sa lecture (ADR 0016)
     */
    public function delete(Recipe $recipe): void
    {
        // La version est celle de l'entité (partagée par toutes les copies du modèle dans la requête), pas celle du modèle
        $entity = $this->entityRepository->find($recipe->getId());
        if ($entity instanceof RecipeEntity) {
            // Avant les lignes : si la recette a changé depuis sa lecture, rien n'est supprimé
            $deleted = $this->entityRepository->createQueryBuilder('r')
                ->delete()
                ->where('r.id = :id')
                ->andWhere('r.version = :version')
                ->setParameter('id', $recipe->getId())
                ->setParameter('version', $entity->getVersion())
                ->getQuery()
                ->execute();
            if ($deleted === 0) {
                throw RecipeException::concurrentModification($recipe->getId());
            }
        }

        $this->recipeRowRepository->deleteByRecipeId($recipe->getId());
        // Ses arêtes (la recette composée) partent avec elle, jamais les recettes qu'elle utilisait ; une instruction, seulement si elle en a
        if ($recipe->hasComponents()) {
            $this->recipeComponentRepository->deleteByRecipeId($recipe->getId());
        }
    }

    public function authorHasAtLeast(string $authorId, int $count): bool
    {
        // La `$count`-ième recette existe-t-elle ? Une ligne lue après avoir sauté les `$count - 1` premières
        return $this->entityManager->createQueryBuilder()
            ->select('r.id')
            ->from(RecipeEntity::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $authorId)
            ->setFirstResult(max(0, $count - 1))
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    public function findIdsByAuthor(string $authorId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id AS id')
            ->from(RecipeEntity::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $authorId)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'id');
    }

    public function makeCopiesOriginal(array $recipeIds): void
    {
        if ($recipeIds === []) {
            return;
        }

        // La colonne `version` s'écrit en SQL, comme `bumpVersion()` : une copie modifiée entre-temps est vue comme périmée
        $this->entityManager->createQueryBuilder()
            ->update(RecipeEntity::class, 'r')
            ->set('r.originalId', 'NULL')
            ->set('r.version', 'r.version + 1')
            ->where('r.originalId IN (:originalIds)')
            ->setParameter('originalIds', $recipeIds)
            ->getQuery()
            ->execute();
    }

    public function deleteByAuthor(string $authorId): void
    {
        // Les lignes ET les arêtes de composants de ses recettes (ADR 0040 décision 37), AVANT les recettes, en UNE instruction : un `WITH` qui
        // efface les arêtes sans que la requête principale les lise (PostgreSQL l'exécute toujours, jusqu'au bout). Un seul aller-retour, comme
        // avant la table des composants : la suppression d'un compte ne coûte pas une requête de plus. Les arêtes n'ont aucune colonne
        // `owner|author|creator|user` (`UserOwnedDataCoverageTest` ne les voit pas) : cette instruction est leur purge
        $this->entityManager->getConnection()->executeStatement(
            'WITH mine AS (SELECT id FROM recipe WHERE author = :author), '
            . 'edges AS (DELETE FROM recipe_component WHERE recipe IN (SELECT id FROM mine)) '
            . 'DELETE FROM recipe_row WHERE recipe IN (SELECT id FROM mine)',
            ['author' => $authorId],
        );

        $this->entityManager->createQueryBuilder()
            ->delete(RecipeEntity::class, 'r')
            ->where('r.authorId = :author')
            ->setParameter('author', $authorId)
            ->getQuery()
            ->execute();
    }

    public function moveToType(string $fromTypeSlug, string $toTypeSlug): void
    {
        // La colonne `version` s'écrit en SQL, comme `bumpVersion()` : une recette lue avant le déplacement est vue comme périmée
        $this->entityManager->createQueryBuilder()
            ->update(RecipeEntity::class, 'r')
            ->set('r.typeSlug', ':to')
            ->set('r.version', 'r.version + 1')
            ->where('r.typeSlug = :from')
            ->setParameter('from', $fromTypeSlug)
            ->setParameter('to', $toTypeSlug)
            ->getQuery()
            ->execute();
    }
}
