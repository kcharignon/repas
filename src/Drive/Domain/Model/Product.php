<?php

namespace Repas\Drive\Domain\Model;


use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Repas\Drive\Domain\Exception\DriveException;
use Repas\Shared\Domain\Model\ModelInterface;
use Repas\Shared\Domain\Model\ModelTrait;

/**
 * Un produit d'une enseigne dans un magasin, tel qu'un agent l'a lu : fait public, sans propriétaire, partagé par tous les
 * utilisateurs qui le choisissent. On ne garde que le strict nécessaire (identifiant chez l'enseigne, libellé, contenu net,
 * prix daté : décision D22) ; pas de photo.
 *
 * Clé naturelle `(retailer, storeId, retailerProductId)`. L'identifiant Repas en est déduit (UUID v5) : deux requêtes qui
 * découvrent en même temps le même produit lui donnent le même identifiant, aucune préférence ne désigne un produit inexistant.
 *
 * Le contenu vient d'un agent : il est borné ici, jamais pris tel quel.
 */
final class Product implements ModelInterface
{
    use ModelTrait;

    /** Identifiant d'un produit chez l'enseigne : des chiffres. */
    public const string RETAILER_PRODUCT_ID_PATTERN = '/^[0-9]{1,12}$/D';
    /** Code-barres : 8 à 14 chiffres. */
    public const string EAN_PATTERN = '/^[0-9]{8,14}$/D';
    public const int LABEL_MAX_LENGTH = 255;

    private const string ID_NAMESPACE = '7d1c1f3a-5a0e-4f0b-9c3e-2f6a8d4b1e57';

    private function __construct(
        private string $id,
        private Retailer $retailer,
        private string $storeId,
        private string $retailerProductId,
        private ?string $ean,
        private string $label,
        private ?ProductContent $content,
        private ?ProductPrice $price,
        private ?DateTimeImmutable $priceSeenAt,
        private DateTimeImmutable $lastSeenAt,
    ) {
    }

    /**
     * @throws DriveException `invalidChoice()` si une valeur sort des bornes
     */
    public static function create(
        Retailer $retailer,
        string $storeId,
        string $retailerProductId,
        ?string $ean,
        string $label,
        ?ProductContent $content,
        ?ProductPrice $price,
        DateTimeImmutable $now,
    ): self {
        if (preg_match(self::RETAILER_PRODUCT_ID_PATTERN, $retailerProductId) !== 1) {
            throw DriveException::invalidChoice();
        }

        return new self(
            id: self::idFor($retailer, $storeId, $retailerProductId),
            retailer: $retailer,
            storeId: $storeId,
            retailerProductId: $retailerProductId,
            ean: self::checkedEan($ean),
            label: self::checkedLabel($label),
            content: self::checkedContent($content),
            price: $price,
            priceSeenAt: $price === null ? null : $now,
            lastSeenAt: $now,
        );
    }

    /**
     * @param array{id: string, retailer: Retailer, store_id: string, retailer_product_id: string, ean: ?string, label: string, content: ?ProductContent, price: ?ProductPrice, price_seen_at: ?DateTimeImmutable, last_seen_at: DateTimeImmutable} $datas
     */
    public static function load(array $datas): self
    {
        return new self(
            $datas['id'],
            $datas['retailer'],
            $datas['store_id'],
            $datas['retailer_product_id'],
            $datas['ean'],
            $datas['label'],
            $datas['content'],
            $datas['price'],
            $datas['price_seen_at'],
            $datas['last_seen_at'],
        );
    }

    /**
     * Identifiant Repas d'un produit, déduit de sa clé naturelle.
     */
    public static function idFor(Retailer $retailer, string $storeId, string $retailerProductId): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, $retailer->value . '|' . $storeId . '|' . $retailerProductId)->toString();
    }

    /**
     * Clé naturelle sous forme de texte, pour retrouver un produit dans une liste.
     */
    public static function keyOf(Retailer $retailer, string $storeId, string $retailerProductId): string
    {
        return $retailer->value . '|' . $storeId . '|' . $retailerProductId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return self::keyOf($this->retailer, $this->storeId, $this->retailerProductId);
    }

    public function getRetailer(): Retailer
    {
        return $this->retailer;
    }

    public function getStoreId(): string
    {
        return $this->storeId;
    }

    public function getRetailerProductId(): string
    {
        return $this->retailerProductId;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getContent(): ?ProductContent
    {
        return $this->content;
    }

    public function getPrice(): ?ProductPrice
    {
        return $this->price;
    }

    public function getPriceSeenAt(): ?DateTimeImmutable
    {
        return $this->priceSeenAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /**
     * L'agent revoit le produit : le libellé est remplacé, un code-barres, un contenu ou un prix lus remplacent les précédents,
     * un champ que l'agent n'a pas su lire (`null`) garde sa valeur connue.
     *
     * @throws DriveException `invalidChoice()` si une valeur sort des bornes
     */
    public function see(?string $ean, string $label, ?ProductContent $content, ?ProductPrice $price, DateTimeImmutable $now): self
    {
        $this->ean = self::checkedEan($ean) ?? $this->ean;
        $this->label = self::checkedLabel($label);
        $this->content = self::checkedContent($content) ?? $this->content;
        $this->seePrice($price, $now);
        $this->lastSeenAt = $now;

        return $this;
    }

    /**
     * Rafraîchit le prix (sans rien changer d'autre) : `null` ne change rien.
     */
    public function seePrice(?ProductPrice $price, DateTimeImmutable $now): self
    {
        if ($price !== null) {
            $this->price = $price;
            $this->priceSeenAt = $now;
            $this->lastSeenAt = $now;
        }

        return $this;
    }

    private static function checkedEan(?string $ean): ?string
    {
        if ($ean !== null && preg_match(self::EAN_PATTERN, $ean) !== 1) {
            throw DriveException::invalidChoice();
        }

        return $ean;
    }

    /**
     * Libellé borné, sans caractère de contrôle (un saut de ligne dans un libellé est une anomalie).
     */
    private static function checkedLabel(string $label): string
    {
        $label = trim($label);
        $controlCharacters = preg_match('/\p{Cc}/u', $label);

        if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH || $controlCharacters !== 0) {
            throw DriveException::invalidChoice();
        }

        return $label;
    }

    private static function checkedContent(?ProductContent $content): ?ProductContent
    {
        if ($content !== null && !$content->isPlausible()) {
            throw DriveException::invalidChoice();
        }

        return $content;
    }
}
