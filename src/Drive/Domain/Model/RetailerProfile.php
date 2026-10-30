<?php

namespace Repas\Drive\Domain\Model;


use InvalidArgumentException;

/**
 * Ce que le back sait d'une enseigne : son nom, la règle d'adresse de ses magasins et la forme de ses identifiants de produit
 * (DRV-701, `docs/drive/multi-enseignes.md` § 4.1). Valeur pure PHP, sans identifiant : `Retailer::profile()` l'attache à l'enseigne.
 *
 * Les motifs sont écrits **sans délimiteur**, ancrés (`^…$`) et dans le sous-ensemble commun à PCRE et à JavaScript (`[0-9]` et
 * jamais `\d`, aucun drapeau) : le contrat `docs/drive/samples/retailers.sample.json` les donne tels quels au web, à l'agent et à
 * l'application, qui les compilent avec leur propre moteur. Le premier groupe capturant de la règle d'adresse est l'identifiant
 * du magasin.
 *
 * Le registre ne contient ni secret ni numéro de magasin réel : l'exemple d'adresse est inventé.
 */
final readonly class RetailerProfile
{
    /** Identifiant de produit composé de 1 à 12 chiffres. */
    public const string NUMERIC_PRODUCT_ID = '^[0-9]{1,12}$';

    /**
     * @param string $name                Nom que les proches disent à voix haute (« Leclerc »)
     * @param string $nameDe              Forme avec « de » et son élision (« de Leclerc », « d’Auchan »)
     * @param string $storeAddressPattern Règle d'adresse de base d'un magasin ; le premier groupe est l'identifiant du magasin
     * @param string $storeAddressExample Adresse inventée qui respecte la règle, pour la documentation et les tests
     * @param string $productIdPattern    Forme de l'identifiant d'un produit chez l'enseigne
     */
    public function __construct(
        public string $name,
        public string $nameDe,
        public string $storeAddressPattern,
        public string $storeAddressExample,
        public string $productIdPattern,
    ) {
        foreach ([$storeAddressPattern, $productIdPattern] as $pattern) {
            if (preg_match(self::compile($pattern), '') === false) {
                throw new InvalidArgumentException('Motif d\'enseigne invalide : ' . $pattern);
            }
        }
        if (!$this->isValidStoreUrl($storeAddressExample)) {
            throw new InvalidArgumentException('L\'exemple d\'adresse ne respecte pas la règle de l\'enseigne.');
        }
    }

    public function isValidStoreUrl(string $baseUrl): bool
    {
        return $this->storeIdOf($baseUrl) !== null;
    }

    /**
     * Identifiant du magasin d'une adresse de base (le premier groupe de chiffres, zéros initiaux conservés), `null` si l'adresse
     * n'est pas celle d'un magasin de l'enseigne.
     */
    public function storeIdOf(string $baseUrl): ?string
    {
        return preg_match(self::compile($this->storeAddressPattern), $baseUrl, $matches) === 1 ? $matches[1] : null;
    }

    public function isValidProductId(string $retailerProductId): bool
    {
        return preg_match(self::compile($this->productIdPattern), $retailerProductId) === 1;
    }

    /**
     * `D` : `$` ne tolère pas un retour à la ligne final, comme en JavaScript sans drapeau.
     */
    private static function compile(string $pattern): string
    {
        return '#' . str_replace('#', '\#', $pattern) . '#D';
    }
}
