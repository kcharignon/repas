<?php

namespace Repas\Drive\Domain\Model;


use InvalidArgumentException;

/**
 * Ce que le back sait d'une enseigne : son nom, la règle d'adresse de ses magasins et la forme de ses identifiants de produit
 * (DRV-701, `docs/drive/multi-enseignes.md` § 4.1). Valeur pure PHP, sans identifiant : `Retailer::profile()` l'attache à l'enseigne.
 *
 * Les motifs sont écrits **sans délimiteur**, ancrés (`^…$`) et dans le sous-ensemble commun à PCRE et à JavaScript (`[0-9]` et
 * jamais `\d`, aucun drapeau) : le contrat `docs/drive/samples/retailers.sample.json` les donne tels quels au web, à l'agent et à
 * l'application, qui les compilent avec leur propre moteur.
 *
 * **L'identifiant du magasin** (DRV-720) vient soit de l'adresse (`StoreIdSource::URL` : le premier groupe capturant de la règle
 * d'adresse), soit de l'agent (`StoreIdSource::EXPLICIT` : l'adresse est une constante d'enseigne, rien ne s'en déduit). Dans les
 * deux cas `storeIdPattern` dit la forme d'un identifiant valable, longueur comprise (jamais plus de `Store::ID_MAX_LENGTH`).
 *
 * **L'identifiant d'offre** (DRV-721) : une enseigne dont un produit a deux identifiants (le produit et son offre dans le magasin,
 * nécessaire à l'ajout au panier) donne la forme du second dans `offerIdPattern` (longueur comprise, jamais plus de
 * `Product::OFFER_ID_MAX_LENGTH`) ; `null` pour une enseigne sans offre, qui n'en reçoit ni n'en rend jamais.
 *
 * Le registre ne contient ni secret ni numéro de magasin réel : l'exemple d'adresse est inventé.
 */
final readonly class RetailerProfile
{
    /** Identifiant de produit composé de 1 à 12 chiffres. */
    public const string NUMERIC_PRODUCT_ID = '^[0-9]{1,12}$';

    /** Identifiant qui est un UUID en minuscules (36 caractères). */
    public const string UUID_ID = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    /**
     * @param string        $name                Nom que les proches disent à voix haute (« Leclerc »)
     * @param string        $nameDe              Forme avec « de » et son élision (« de Leclerc », « d’Auchan »)
     * @param string        $storeAddressPattern Règle d'adresse de base d'un magasin ; avec `StoreIdSource::URL`, le premier groupe est l'identifiant du magasin
     * @param string        $storeAddressExample Adresse inventée qui respecte la règle, pour la documentation et les tests
     * @param string        $productIdPattern    Forme de l'identifiant d'un produit chez l'enseigne
     * @param StoreIdSource $storeIdSource       D'où vient l'identifiant du magasin : de l'adresse (`URL`) ou lu par l'agent (`EXPLICIT`)
     * @param string        $storeIdPattern      Forme de l'identifiant d'un magasin chez l'enseigne (celui qu'on extrait de l'adresse, ou celui que l'agent lit)
     * @param ?string       $offerIdPattern      Forme de l'identifiant d'offre d'un produit dans le magasin ; `null` (par défaut) quand l'enseigne n'a pas d'offre (DRV-721)
     */
    public function __construct(
        public string $name,
        public string $nameDe,
        public string $storeAddressPattern,
        public string $storeAddressExample,
        public string $productIdPattern,
        public StoreIdSource $storeIdSource,
        public string $storeIdPattern,
        public ?string $offerIdPattern = null,
    ) {
        foreach (array_filter([$storeAddressPattern, $productIdPattern, $storeIdPattern, $offerIdPattern], static fn(?string $pattern): bool => $pattern !== null) as $pattern) {
            if (!self::compiles($pattern)) {
                throw new InvalidArgumentException('Motif d\'enseigne invalide : ' . $pattern);
            }
        }
        if (!$this->isValidStoreUrl($storeAddressExample)) {
            throw new InvalidArgumentException('L\'exemple d\'adresse ne respecte pas la règle de l\'enseigne.');
        }
        // Un identifiant déduit de l'adresse doit avoir la forme annoncée : sinon le contrat mentirait au web, à l'agent et à l'application
        if ($storeIdSource === StoreIdSource::URL && !$this->isValidStoreId((string) $this->storeIdOf($storeAddressExample))) {
            throw new InvalidArgumentException('L\'identifiant que donne l\'exemple d\'adresse ne respecte pas le motif d\'identifiant de magasin.');
        }
    }

    /**
     * `true` quand l'adresse est celle d'un magasin de l'enseigne. Si l'identifiant s'en déduit (`URL`), la règle doit en plus en donner
     * un ; s'il est explicite, l'adresse est une constante d'enseigne et aucun groupe n'est attendu.
     */
    public function isValidStoreUrl(string $baseUrl): bool
    {
        if (preg_match(self::compile($this->storeAddressPattern), $baseUrl, $matches) !== 1) {
            return false;
        }

        return $this->storeIdSource === StoreIdSource::EXPLICIT || isset($matches[1]);
    }

    /**
     * Identifiant du magasin d'une adresse de base (le premier groupe, zéros initiaux conservés), `null` si l'adresse n'est pas celle
     * d'un magasin de l'enseigne **ou si l'identifiant est explicite** : rien ne se déduit alors de l'adresse.
     */
    public function storeIdOf(string $baseUrl): ?string
    {
        if ($this->storeIdSource !== StoreIdSource::URL) {
            return null;
        }

        return preg_match(self::compile($this->storeAddressPattern), $baseUrl, $matches) === 1 ? ($matches[1] ?? null) : null;
    }

    /**
     * L'identifiant a-t-il la forme d'un identifiant de magasin de l'enseigne ? Forme seulement : `Store::create` juge en plus qu'il
     * est celui de l'adresse quand il s'en déduit.
     */
    public function isValidStoreId(string $storeId): bool
    {
        return preg_match(self::compile($this->storeIdPattern), $storeId) === 1;
    }

    public function isValidProductId(string $retailerProductId): bool
    {
        return preg_match(self::compile($this->productIdPattern), $retailerProductId) === 1;
    }

    /**
     * L'enseigne a-t-elle des offres (un identifiant d'offre par produit, en plus de l'identifiant du produit) ?
     */
    public function hasOffers(): bool
    {
        return $this->offerIdPattern !== null;
    }

    /**
     * L'identifiant a-t-il la forme d'un identifiant d'offre de l'enseigne ? Toujours `false` pour une enseigne sans offre : un
     * identifiant d'offre fourni pour elle est refusé (DRV-721).
     */
    public function isValidOfferId(string $offerId): bool
    {
        return $this->offerIdPattern !== null && preg_match(self::compile($this->offerIdPattern), $offerId) === 1;
    }

    /**
     * Le motif se compile-t-il ? PCRE avertit d'un motif invalide : l'avertissement est tu, seul le refus compte.
     */
    private static function compiles(string $pattern): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            return preg_match(self::compile($pattern), '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * `D` : `$` ne tolère pas un retour à la ligne final, comme en JavaScript sans drapeau.
     */
    private static function compile(string $pattern): string
    {
        return '#' . str_replace('#', '\#', $pattern) . '#D';
    }
}
