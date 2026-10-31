<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\IngredientException;
use Repas\Repas\Domain\Interface\IngredientRepository;
use Repas\Repas\Domain\Model\Department;
use Repas\Repas\Domain\Model\Ingredient;
use Repas\Shared\Domain\Tool\Tab;

/**
 * @extends AbstractInMemoryRepository<Ingredient>
 */
class IngredientInMemoryRepository extends AbstractInMemoryRepository implements IngredientRepository
{
    use SaveModelTrait;

    protected static function getClassName(): string
    {
        return Ingredient::class;
    }

    public function findPurchaseUnitSlug(string $slug): string
    {
        return $this->findOneBySlug($slug)->getDefaultPurchaseUnit()->getSlug();
    }

    public function findPurchaseUnitSlugUsableBy(string $slug, string $userId): string
    {
        $ingredient = $this->findOneBySlug($slug);
        if ($ingredient->getCreatorId() !== $userId) {
            throw IngredientException::notFound($slug);
        }

        return $ingredient->getDefaultPurchaseUnit()->getSlug();
    }

    /** @var list<list<string>> slugs reçus à chaque appel de `departmentSlugsOf()`, dans l'ordre des appels */
    public array $departmentSlugsOfCalls = [];

    public function departmentSlugsOf(array $slugs): array
    {
        $this->departmentSlugsOfCalls[] = $slugs;

        $departments = [];
        foreach ($this->models as $ingredient) {
            if (in_array($ingredient->getSlug(), $slugs, true)) {
                $departments[$ingredient->getSlug()] = $ingredient->getDepartment()->getSlug();
            }
        }

        return $departments;
    }

    public function creatorHasAtLeast(string $creatorId, int $count): bool
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getCreatorId() === $creatorId)->count() >= $count;
    }

    public function exists(string $slug): bool
    {
        return isset($this->models[$slug]);
    }

    public function creatorHasNameInDepartment(string $creatorId, string $sluggedName, string $departmentSlug, ?string $exceptSlug = null): bool
    {
        return $this->models->find(
            fn(Ingredient $ingredient) => $ingredient->getCreatorId() === $creatorId
                && $ingredient->getSluggedName() === $sluggedName
                && $ingredient->getDepartment()->getSlug() === $departmentSlug
                && $ingredient->getSlug() !== $exceptSlug,
        ) !== null;
    }

    public function findOneBySlug(string $slug): Ingredient
    {
        return $this->models->find(fn(Ingredient $ingredient) => $ingredient->getSlug() === $slug) ?? throw IngredientException::notFound($slug);
    }

    public function findBySlugs(Tab $slugs): Tab
    {
        $ingredients = Tab::newEmptyTyped(Ingredient::class);
        foreach ($slugs->unique() as $slug) {
            $ingredients[] = $this->findOneBySlug($slug);
        }

        return $ingredients;
    }

    public function findByDepartmentAndOwner(Department $department, string $ownerId): Tab
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getDepartment()->isEqual($department) && $ingredient->getCreatorId() === $ownerId);
    }

    public function findByOwner(string $ownerId): Tab
    {
        return $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getCreatorId() === $ownerId);
    }

    /**
     * @return Tab<Ingredient>
     */
    public function findAll(): Tab
    {
        return $this->models;
    }

    public function delete(Ingredient $ingredient): void
    {
        unset($this->models[$ingredient->getId()]);
    }

    public function deleteByCreator(string $creatorId): void
    {
        $this->models = $this->models->filter(fn(Ingredient $ingredient) => $ingredient->getCreatorId() !== $creatorId);
    }

    public function clearListDestination(string $creatorId, string $destination): void
    {
        foreach ($this->models as $ingredient) {
            /** @var Ingredient $ingredient */
            if ($ingredient->getCreatorId() === $creatorId && $ingredient->getListDestination() === $destination) {
                $ingredient->changeListDestination(null);
            }
        }
    }
}
