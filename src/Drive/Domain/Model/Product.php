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
 * prix daté : décision D22) ; pas de photo. Le rayon et la famille de l'enseigne (`rayonId`, `familyId`, DRV-642) sont des faits
 * du catalogue au même titre : les agents les lisent dans la réponse du site, Repas en tire le rayon où chercher un ingrédient.
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
    /** Un identifiant de rayon ou de famille de l'enseigne est un entier de 1 à cette borne, la taille d'un `INT` PostgreSQL. */
    public const int CATALOG_ID_MAX = 2147483647;
    /**
     * Caractères refusés dans un libellé : tout ce qui ne se voit pas ou ne sert qu'à changer le sens de lecture. La fiche est
     * partagée entre utilisateurs et le libellé vient de la page d'une enseigne : un caractère invisible permet d'usurper ou de
     * masquer du texte. Un `preg_match` qui renvoie `false` (UTF-8 invalide) n'est pas `0` : le libellé est refusé aussi.
     *
     * Par catégorie Unicode (la liste suit la version d'Unicode de PCRE, elle n'est pas recopiée ici) :
     * - `Cc` : contrôles ; `Zl` et `Zp` : séparateurs de ligne et de paragraphe (U+2028, U+2029) ;
     * - `Cf` : caractères de format, sans tracé : trait d'union conditionnel (U+00AD), marques et inversions du sens de lecture
     *   (U+061C, U+200E, U+200F, U+202A à U+202E, U+2066 à U+2069), espaces et jointures de largeur nulle (U+200B à U+200D),
     *   opérateurs mathématiques invisibles (U+2060 à U+2064), marque d'ordre des octets (U+FEFF), caractères de TAG (U+E0001,
     *   U+E0020 à U+E007F).
     *
     * Exceptions listées : invisibles aussi, mais d'une autre catégorie.
     * - U+034F (`Mn`, joint de graphèmes), U+17B4 et U+17B5 (`Mn`, voyelles inhérentes khmères) ;
     * - U+115F, U+1160, U+3164, U+FFA0 (`Lo`, remplisseurs hangul : des lettres vides) ;
     * - U+180B à U+180F (`Mn`, sélecteurs de variation mongols ; U+180E est `Cf` ou non selon la version d'Unicode) ;
     * - U+FE00 à U+FE0F et U+E0100 à U+E01EF (`Mn`, sélecteurs de variation : deux libellés paraissent identiques) ;
     * - U+E0000 à U+E007F (tout le bloc des TAG, points réservés compris).
     *
     * Ce qui reste accepté, voulu : lettres accentuées et ligatures (é, è, ç, œ), accents combinants (`Mn` : e puis U+0301),
     * symboles courants (®, ™, ©, °, €, %, &, ’, « »), l'espace et les espaces insécables U+00A0 et U+202F (`Zs`, étiquettes
     * de prix et de poids ; un libellé fait seulement d'espaces est refusé à part). Revers assumé : un emoji écrit avec une
     * jointure (U+200D : famille, couple) ou avec le sélecteur U+FE0F (cœur, soleil) n'est pas accepté ; un emoji d'un seul
     * caractère (🥚) l'est.
     *
     * Ce motif est recopié côté front (`drive-agent/test/back-label-rules.ts`) : le changer impose de changer l'agent aussi.
     */
    private const string LABEL_FORBIDDEN_CHARACTERS_PATTERN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}'
        . '\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}'
        . '\x{E0000}-\x{E007F}\x{E0100}-\x{E01EF}]/u';

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
        private ?int $rayonId = null,
        private ?int $familyId = null,
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
        ?int $rayonId = null,
        ?int $familyId = null,
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
            rayonId: self::checkedCatalogId($rayonId),
            familyId: self::checkedCatalogId($familyId),
        );
    }

    /**
     * @param array{id: string, retailer: Retailer, store_id: string, retailer_product_id: string, ean: ?string, label: string, content: ?ProductContent, price: ?ProductPrice, price_seen_at: ?DateTimeImmutable, last_seen_at: DateTimeImmutable, rayon_id: ?int, family_id: ?int} $datas
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
            $datas['rayon_id'],
            $datas['family_id'],
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
     * Rayon de l'enseigne où le site range ce produit (`iIdRayon` chez Leclerc), `null` tant qu'aucun agent ne l'a rapporté.
     */
    public function getRayonId(): ?int
    {
        return $this->rayonId;
    }

    /**
     * Famille de l'enseigne, le niveau sous le rayon (`iIdFamille` chez Leclerc), `null` tant qu'aucun agent ne l'a rapportée.
     */
    public function getFamilyId(): ?int
    {
        return $this->familyId;
    }

    /**
     * Un identifiant de rayon ou de famille est-il dans les bornes (1 à `CATALOG_ID_MAX`) ?
     */
    public static function isCatalogId(int $id): bool
    {
        return $id >= 1 && $id <= self::CATALOG_ID_MAX;
    }

    /**
     * Un agent revoit le produit : premier écrivain gagnant (constat C1 de la revue de sécurité, DRV-613). La fiche est commune
     * à tous les utilisateurs du magasin : le libellé, le code-barres, le contenu et le prix d'une fiche connue ne sont jamais
     * réécrits par un autre. L'agent ne **complète** que ce qui est encore vide (code-barres, contenu, prix) ; le libellé ne
     * change jamais. Le rayon et la famille suivent la même règle (DRV-642) : renseignés une fois, ils ne sont plus réécrits, et
     * `null` n'efface rien. Les valeurs reçues sont contrôlées même quand elles ne servent pas : une saisie hors des bornes reste
     * refusée. Changer un prix est `seePrice()`, réservé à l'utilisateur qui accepte le produit.
     *
     * @throws DriveException `invalidChoice()` si une valeur sort des bornes
     */
    public function complete(?string $ean, string $label, ?ProductContent $content, ?ProductPrice $price, DateTimeImmutable $now, ?int $rayonId = null, ?int $familyId = null): self
    {
        $ean = self::checkedEan($ean);
        self::checkedLabel($label);
        $content = self::checkedContent($content);
        $rayonId = self::checkedCatalogId($rayonId);
        $familyId = self::checkedCatalogId($familyId);

        $this->ean ??= $ean;
        $this->content ??= $content;
        $this->rayonId ??= $rayonId;
        $this->familyId ??= $familyId;
        if ($this->price === null) {
            $this->seePrice($price, $now);
        }
        $this->lastSeenAt = $now;

        return $this;
    }

    /**
     * Rafraîchit le prix (sans rien changer d'autre) : `null` ne change rien. Le handler ne l'appelle que pour un utilisateur qui
     * accepte le produit pour l'ingrédient (`REFRESH_PRICE`, ou `ADD_PRODUCT` d'un produit qu'il accepte déjà).
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
     * Libellé borné, sans caractère de contrôle ni caractère invisible (voir `LABEL_FORBIDDEN_CHARACTERS_PATTERN` : un saut de
     * ligne dans un libellé est une anomalie ; une marque de sens de lecture ou une espace de largeur nulle ne sert qu'à tromper
     * l'œil). Un libellé qui ne contient que des espaces, insécables ou autres, est vide.
     */
    private static function checkedLabel(string $label): string
    {
        $label = trim($label);
        $forbiddenCharacters = preg_match(self::LABEL_FORBIDDEN_CHARACTERS_PATTERN, $label);
        $blank = preg_match('/^[\p{Z}\s]*$/u', $label);

        if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH || $forbiddenCharacters !== 0 || $blank !== 0) {
            throw DriveException::invalidChoice();
        }

        return $label;
    }

    private static function checkedCatalogId(?int $id): ?int
    {
        if ($id !== null && !self::isCatalogId($id)) {
            throw DriveException::invalidChoice();
        }

        return $id;
    }

    private static function checkedContent(?ProductContent $content): ?ProductContent
    {
        if ($content !== null && !$content->isPlausible()) {
            throw DriveException::invalidChoice();
        }

        return $content;
    }
}
