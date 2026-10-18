<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * La préférence privée d'un utilisateur pour un ingrédient dans un magasin : les produits qu'il accepte, du préféré aux produits
 * de secours (5 au plus), ceux qu'il refuse (20 au plus), ou « j'en ai toujours ». Un produit est désigné par son identifiant
 * (ADR 0013). Unique par `(userId, retailer, storeId, ingredientSlug)`.
 *
 * Invariants, vrais après chaque méthode : un produit n'apparaît qu'une fois, jamais à la fois accepté et refusé ; les rangs
 * sont contigus de 1 à n (ils se déduisent de l'ordre de la liste) ; « j'en ai toujours » n'a ni produit accepté ni refusé.
 * Une méthode qui échoue (`DriveException::invalidChoice()`) ne change rien.
 */
final class Choice implements ModelInterface
{
    use ModelTrait;

    public const int MAX_ACCEPTED = 5;
    public const int MAX_REJECTED = 20;
    public const string SLUG_PATTERN = '/^[a-z0-9-]{1,255}$/D';

    /**
     * @param list<string> $accepted identifiants des produits acceptés, le préféré d'abord (rang = position + 1)
     * @param list<string> $rejected identifiants des produits refusés
     */
    private function __construct(
        private string $id,
        private string $userId,
        private Retailer $retailer,
        private string $storeId,
        private string $ingredientSlug,
        private ChoiceKind $kind,
        private array $accepted,
        private array $rejected,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Préférence vide (aucun produit) : le premier `addPrimary()` la remplit.
     *
     * @throws DriveException `invalidChoice()` si le slug de l'ingrédient n'est pas un slug
     */
    public static function create(
        string $id,
        string $userId,
        Retailer $retailer,
        string $storeId,
        string $ingredientSlug,
        DateTimeImmutable $now,
    ): self {
        if (preg_match(self::SLUG_PATTERN, $ingredientSlug) !== 1) {
            throw DriveException::invalidChoice();
        }

        return new self($id, $userId, $retailer, $storeId, $ingredientSlug, ChoiceKind::PRODUCTS, [], [], $now);
    }

    /**
     * Reconstruction depuis la persistance : aucun contrôle (les produits acceptés sont classés par rang).
     *
     * @param array{id: string, user_id: string, retailer: Retailer, store_id: string, ingredient_slug: string, kind: ChoiceKind, accepted: list<AcceptedProduct>, rejected: list<string>, updated_at: DateTimeImmutable} $datas
     */
    public static function load(array $datas): self
    {
        $accepted = $datas['accepted'];
        usort($accepted, static fn(AcceptedProduct $a, AcceptedProduct $b): int => $a->rank <=> $b->rank);

        return new self(
            $datas['id'],
            $datas['user_id'],
            $datas['retailer'],
            $datas['store_id'],
            $datas['ingredient_slug'],
            $datas['kind'],
            array_map(static fn(AcceptedProduct $product): string => $product->productId, $accepted),
            $datas['rejected'],
            $datas['updated_at'],
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getRetailer(): Retailer
    {
        return $this->retailer;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getIngredientSlug(): string
    {
        return $this->ingredientSlug;
    }

    public function getKind(): ChoiceKind
    {
        return $this->kind;
    }

    public function isHaveAtHome(): bool
    {
        return $this->kind === ChoiceKind::HAVE_AT_HOME;
    }

    /**
     * Les produits acceptés avec leur rang, le préféré (rang 1) d'abord.
     *
     * @return list<AcceptedProduct>
     */
    public function getAccepted(): array
    {
        return array_map(
            static fn(string $productId, int $index): AcceptedProduct => new AcceptedProduct($productId, $index + 1),
            $this->accepted,
            array_keys($this->accepted),
        );
    }

    /**
     * @return list<string>
     */
    public function getAcceptedProductIds(): array
    {
        return $this->accepted;
    }

    /**
     * @return list<string>
     */
    public function getRejectedProductIds(): array
    {
        return $this->rejected;
    }

    public function isAccepted(string $productId): bool
    {
        return in_array($productId, $this->accepted, true);
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(DateTimeImmutable $now): self
    {
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Le produit devient le préféré (rang 1), les autres descendent d'un rang. Déjà accepté : il remonte. Un produit refusé
     * qu'on accepte n'est plus refusé.
     *
     * @throws DriveException `invalidChoice()` s'il y a déjà 5 produits acceptés et que celui-ci est nouveau
     */
    public function addPrimary(string $productId): self
    {
        return $this->acceptAs([$productId, ...array_diff($this->accepted, [$productId])], $productId);
    }

    /**
     * Le produit est ajouté après les autres (produit de secours). Déjà accepté : rien ne change.
     *
     * @throws DriveException `invalidChoice()` s'il y a déjà 5 produits acceptés et que celui-ci est nouveau
     */
    public function addFallback(string $productId): self
    {
        $accepted = $this->isAccepted($productId) ? $this->accepted : [...$this->accepted, $productId];

        return $this->acceptAs($accepted, $productId);
    }

    /**
     * Le produit n'est plus accepté (les rangs sont recompactés) et ne sera plus jamais proposé.
     *
     * @throws DriveException `invalidChoice()` s'il y a déjà 20 produits refusés et que celui-ci est nouveau
     */
    public function reject(string $productId): self
    {
        $rejected = $this->rejected;
        if (!in_array($productId, $rejected, true)) {
            $rejected[] = $productId;
        }
        if (count($rejected) > self::MAX_REJECTED) {
            throw DriveException::invalidChoice();
        }

        $this->accepted = array_values(array_diff($this->accepted, [$productId]));
        $this->rejected = $rejected;
        $this->kind = ChoiceKind::PRODUCTS;

        return $this;
    }

    /**
     * Le produit n'est plus refusé.
     */
    public function unreject(string $productId): self
    {
        $this->rejected = array_values(array_diff($this->rejected, [$productId]));

        return $this;
    }

    /**
     * Le produit n'est plus accepté (les rangs sont recompactés), sans devenir refusé.
     */
    public function remove(string $productId): self
    {
        $this->accepted = array_values(array_diff($this->accepted, [$productId]));

        return $this;
    }

    /**
     * Nouvel ordre des produits acceptés : les mêmes produits, une fois chacun.
     *
     * @param list<string> $productIds
     *
     * @throws DriveException `invalidChoice()` si ce n'est pas une permutation des produits acceptés
     */
    public function reorder(array $productIds): self
    {
        $sameProducts = count($productIds) === count($this->accepted)
            && array_diff($productIds, $this->accepted) === []
            && count(array_unique($productIds)) === count($productIds);
        if (!$sameProducts) {
            throw DriveException::invalidChoice();
        }

        $this->accepted = array_values($productIds);

        return $this;
    }

    /**
     * « J'en ai toujours » : les produits acceptés et refusés sont effacés.
     */
    public function markHaveAtHome(): self
    {
        $this->kind = ChoiceKind::HAVE_AT_HOME;
        $this->accepted = [];
        $this->rejected = [];

        return $this;
    }

    /**
     * L'utilisateur achète de nouveau cet ingrédient : une préférence de produits, vide.
     */
    public function resumeBuying(): self
    {
        $this->kind = ChoiceKind::PRODUCTS;

        return $this;
    }

    /**
     * @param list<string> $accepted nouvelle liste des produits acceptés, qui contient `$productId`
     */
    private function acceptAs(array $accepted, string $productId): self
    {
        if (count($accepted) > self::MAX_ACCEPTED) {
            throw DriveException::invalidChoice();
        }

        $this->accepted = array_values($accepted);
        $this->rejected = array_values(array_diff($this->rejected, [$productId]));
        $this->kind = ChoiceKind::PRODUCTS;

        return $this;
    }
}
