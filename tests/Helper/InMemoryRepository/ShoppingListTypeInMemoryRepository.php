<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Repas\Repas\Domain\Exception\ShoppingListTypeException;
use Repas\Repas\Domain\Interface\ShoppingListTypeRepository;
use Repas\Repas\Domain\Model\ShoppingListType;

/**
 * Double en mémoire du port : le même contrat que les deux tables. Les rattachements sont gardés à part, une ligne par (utilisateur, rayon)
 * comme la clé de `department_list_type` : rattacher un rayon déjà pris le déplace, et un type se relit toujours avec les rayons que la
 * table lui donne, jamais ceux d'un modèle resté en mémoire.
 */
class ShoppingListTypeInMemoryRepository implements ShoppingListTypeRepository
{
    /** @var array<string, ShoppingListType> */
    private array $types = [];

    /** @var array<string, string> l'identifiant du type, par `propriétaire|rayon` */
    private array $attachments = [];

    /**
     * @param list<ShoppingListType> $types
     */
    public function __construct(array $types = [])
    {
        foreach ($types as $type) {
            $this->save($type);
        }
    }

    public function findOneById(string $id): ShoppingListType
    {
        $type = $this->types[$id] ?? throw ShoppingListTypeException::notFound();

        return ShoppingListType::load([
            'id' => $type->getId(),
            'owner_id' => $type->getOwnerId(),
            'name' => $type->getName(),
            'slugged_name' => $type->getSluggedName(),
            'created_at' => $type->getCreatedAt(),
            'department_slugs' => $this->departmentSlugsOf($type),
        ]);
    }

    public function ownerHasAtLeast(string $ownerId, int $count): bool
    {
        return count($this->ofOwner($ownerId)) >= $count;
    }

    public function ownerHasTypeNamed(string $ownerId, string $sluggedName, ?string $exceptId = null): bool
    {
        foreach ($this->ofOwner($ownerId) as $type) {
            if ($type->getSluggedName() === $sluggedName && $type->getId() !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function save(ShoppingListType $listType): void
    {
        $this->types[$listType->getId()] = ShoppingListType::load([
            'id' => $listType->getId(),
            'owner_id' => $listType->getOwnerId(),
            'name' => $listType->getName(),
            'slugged_name' => $listType->getSluggedName(),
            'created_at' => $listType->getCreatedAt(),
        ]);
        foreach ($this->departmentSlugsOf($listType) as $slug) {
            unset($this->attachments[self::key($listType->getOwnerId(), $slug)]);
        }
        foreach ($listType->getDepartmentSlugs() as $slug) {
            $this->attachments[self::key($listType->getOwnerId(), $slug)] = $listType->getId();
        }
    }

    public function delete(ShoppingListType $listType): void
    {
        foreach ($this->departmentSlugsOf($listType) as $slug) {
            unset($this->attachments[self::key($listType->getOwnerId(), $slug)]);
        }
        unset($this->types[$listType->getId()]);
    }

    public function deleteByOwner(string $ownerId): void
    {
        foreach ($this->ofOwner($ownerId) as $type) {
            $this->delete($type);
        }
    }

    /**
     * @return list<ShoppingListType> tous les types, de tous les propriétaires
     */
    public function all(): array
    {
        return array_map(fn(ShoppingListType $type): ShoppingListType => $this->findOneById($type->getId()), array_values($this->types));
    }

    /**
     * @return list<string> les rayons rattachés à un type, selon la table
     */
    public function departmentSlugsOf(ShoppingListType $type): array
    {
        $slugs = [];
        foreach ($this->attachments as $key => $typeId) {
            if ($typeId === $type->getId()) {
                $slugs[] = substr($key, strlen($type->getOwnerId()) + 1);
            }
        }
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @return list<ShoppingListType>
     */
    private function ofOwner(string $ownerId): array
    {
        return array_values(array_filter($this->types, static fn(ShoppingListType $type): bool => $type->getOwnerId() === $ownerId));
    }

    private static function key(string $ownerId, string $departmentSlug): string
    {
        return $ownerId . '|' . $departmentSlug;
    }
}
