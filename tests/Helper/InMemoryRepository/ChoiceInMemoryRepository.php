<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Drive\Domain\Interface\ChoiceRepository;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\Retailer;

/**
 * @extends AbstractInMemoryRepository<Choice>
 */
class ChoiceInMemoryRepository extends AbstractInMemoryRepository implements ChoiceRepository
{
    /** @var int nombre d'appels à `findByUserStoreAndSlugs()` */
    public int $findByUserStoreAndSlugsCalls = 0;

    /** @var int nombre d'appels à `countByUserAndStore()` */
    public int $countCalls = 0;

    /** @var list<int> nombre de préférences reçues à chaque `saveAll()` */
    public array $saveAllSizes = [];

    /** @var list<string> utilisateurs dont `deleteProductsByUser()` a été appelé, dans l'ordre */
    public array $productsDeletedForUsers = [];

    protected static function getClassName(): string
    {
        return Choice::class;
    }

    public function findByUserStoreAndSlugs(string $userId, Retailer $retailer, string $storeId, array $ingredientSlugs): array
    {
        $this->findByUserStoreAndSlugsCalls++;

        return array_values(array_map(
            static fn(Choice $choice): Choice => clone $choice,
            array_filter(
                $this->models->toArray(),
                static fn(Choice $choice): bool => $choice->getUserId() === $userId
                    && $choice->getRetailer()->value === $retailer->value
                    && $choice->getStoreId() === $storeId
                    && in_array($choice->getIngredientSlug(), $ingredientSlugs, true),
            ),
        ));
    }

    public function findOne(string $userId, Retailer $retailer, string $storeId, string $ingredientSlug): ?Choice
    {
        return $this->findByUserStoreAndSlugs($userId, $retailer, $storeId, [$ingredientSlug])[0] ?? null;
    }

    public function countByUserAndStore(string $userId, Retailer $retailer, string $storeId): int
    {
        $this->countCalls++;

        return count(array_filter(
            $this->models->toArray(),
            static fn(Choice $choice): bool => $choice->getUserId() === $userId
                && $choice->getRetailer()->value === $retailer->value
                && $choice->getStoreId() === $storeId,
        ));
    }

    public function saveAll(array $choices): void
    {
        $this->saveAllSizes[] = count($choices);
        foreach ($choices as $choice) {
            $this->models[$choice->getId()] = clone $choice;
        }
    }

    public function save(Choice $choice): void
    {
        $this->saveAll([$choice]);
    }

    public function delete(Choice $choice): void
    {
        unset($this->models[$choice->getId()]);
    }

    public function deleteProductsByUser(string $userId): void
    {
        $this->productsDeletedForUsers[] = $userId;
    }

    public function deleteByUser(string $userId): void
    {
        foreach ($this->models->toArray() as $key => $choice) {
            if ($choice->getUserId() === $userId) {
                unset($this->models[$key]);
            }
        }
    }

    /**
     * @return list<Choice>
     */
    public function all(): array
    {
        return array_values($this->models->toArray());
    }
}
