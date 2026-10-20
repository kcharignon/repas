<?php

namespace Repas\Tests\Helper\Builder;


use DateTimeImmutable;
use Repas\Drive\Domain\Model\AcceptedProduct;
use Repas\Drive\Domain\Model\Choice;
use Repas\Drive\Domain\Model\ChoiceKind;
use Repas\Drive\Domain\Model\Retailer;
use Repas\Drive\Domain\Model\SearchQuery;
use Repas\Shared\Domain\Tool\UuidGenerator;

/**
 * Valeurs factices uniquement : jamais un vrai numéro de magasin.
 */
class ChoiceBuilder implements Builder
{
    private string $id;
    private string $userId;
    private string $storeId;
    private string $ingredientSlug;
    private ChoiceKind $kind;
    private ?SearchQuery $searchQuery = null;
    /** @var list<string> */
    private array $accepted = [];
    /** @var list<string> */
    private array $rejected = [];
    private DateTimeImmutable $updatedAt;

    private function initialize(): void
    {
        $this->id ??= UuidGenerator::new();
        $this->userId ??= UuidGenerator::new();
        $this->storeId ??= '123456';
        $this->ingredientSlug ??= 'farine';
        $this->kind ??= ChoiceKind::PRODUCTS;
        $this->updatedAt ??= new DateTimeImmutable('2026-10-18 10:00:00');
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function withStoreId(string $storeId): self
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function withIngredientSlug(string $ingredientSlug): self
    {
        $this->ingredientSlug = $ingredientSlug;
        return $this;
    }

    /**
     * @param list<string> $productIds identifiants des produits acceptés, le préféré d'abord
     */
    public function withAccepted(array $productIds): self
    {
        $this->accepted = $productIds;
        return $this;
    }

    /**
     * @param list<string> $productIds
     */
    public function withRejected(array $productIds): self
    {
        $this->rejected = $productIds;
        return $this;
    }

    public function withSearchQuery(string $searchQuery): self
    {
        $this->searchQuery = SearchQuery::of($searchQuery);
        return $this;
    }

    public function haveAtHome(): self
    {
        $this->kind = ChoiceKind::HAVE_AT_HOME;
        return $this;
    }

    public function withUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function build(): Choice
    {
        $this->initialize();
        return Choice::load([
            'id' => $this->id,
            'user_id' => $this->userId,
            'retailer' => Retailer::LECLERC,
            'store_id' => $this->storeId,
            'ingredient_slug' => $this->ingredientSlug,
            'kind' => $this->kind,
            'accepted' => array_map(static fn(string $productId, int $index): AcceptedProduct => new AcceptedProduct($productId, $index + 1), $this->accepted, array_keys($this->accepted)),
            'rejected' => $this->rejected,
            'search_query' => $this->searchQuery,
            'updated_at' => $this->updatedAt,
        ]);
    }
}
