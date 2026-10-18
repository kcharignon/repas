<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Les réglages du drive d'un utilisateur pour une enseigne : le magasin où il retire ses courses. Un seul par utilisateur et
 * par enseigne (changer de magasin remplace le précédent). Agrégat distinct de `User` (ADR 0020).
 */
final class DriveSettings implements ModelInterface
{
    use ModelTrait;

    private function __construct(
        private string $userId,
        private Retailer $retailer,
        private Store $store,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(string $userId, Retailer $retailer, Store $store, DateTimeImmutable $now): self
    {
        return new self($userId, $retailer, $store, $now);
    }

    /**
     * @param array{user_id: string, retailer: Retailer, store: Store, updated_at: DateTimeImmutable} $datas
     */
    public static function load(array $datas): self
    {
        return new self($datas['user_id'], $datas['retailer'], $datas['store'], $datas['updated_at']);
    }

    /**
     * Identifiant composite : un réglage est désigné par son utilisateur et son enseigne.
     */
    public function getId(): string
    {
        return $this->userId . ':' . $this->retailer->value;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getRetailer(): Retailer
    {
        return $this->retailer;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeStore(Store $store, DateTimeImmutable $now): self
    {
        $this->store = $store;
        $this->updatedAt = $now;

        return $this;
    }
}
