<?php

namespace Repas\Drive\Domain\Model;


/**
 * Enseigne dont on sait remplir le panier. L'enum est la liste des identifiants (ceux de la colonne `retailer`, jamais renommés) ;
 * `profile()` porte le reste (nom, règle d'adresse du magasin, forme des identifiants de produit, `RetailerProfile`). La règle
 * d'adresse est la règle d'hôte autorisé : l'adresse d'un magasin n'est acceptée que si elle est sur le domaine de l'enseigne
 * (jamais une adresse libre, qu'un lien piégé pourrait détourner).
 *
 * **Seul endroit du back où une enseigne est nommée** (avec `RetailerProfile`) : `RetailersAreNamedInOnePlaceTest`.
 */
enum Retailer: string
{
    case LECLERC = 'leclerc';

    /**
     * Les identifiants, pour les attributs OpenAPI (qui n'acceptent qu'une expression constante) ; `RetailerTest` la garde contre
     * `cases()`.
     *
     * @var list<string>
     */
    public const array IDS = ['leclerc'];

    /**
     * L'enseigne des lectures et des écritures qui n'en reçoivent pas : **un seul endroit** (DRV-701). La sélection de l'enseigne
     * (DRV-703) la remplace.
     */
    public static function default(): self
    {
        return self::LECLERC;
    }

    public function profile(): RetailerProfile
    {
        // Un enum n'a pas de propriété : le profil, valeur immuable, est construit une fois par processus
        static $profiles = [];

        return $profiles[$this->value] ??= match ($this) {
            self::LECLERC => new RetailerProfile(
                name: 'Leclerc',
                nameDe: 'de Leclerc',
                // Adresse de base d'un magasin E.Leclerc Drive : `https://<sous-domaine>.leclercdrive.fr/magasin-<id>-<id>`
                storeAddressPattern: '^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-([0-9]+)-([0-9]+)$',
                storeAddressExample: 'https://exemple.leclercdrive.fr/magasin-123456-123456',
                productIdPattern: RetailerProfile::NUMERIC_PRODUCT_ID,
                // L'identifiant se déduit de l'adresse : le premier groupe de chiffres, 20 au plus (ce que `Store::create` acceptait déjà, DRV-720)
                storeIdSource: StoreIdSource::URL,
                storeIdPattern: '^[0-9]{1,20}$',
            ),
        };
    }

    public function isValidStoreUrl(string $baseUrl): bool
    {
        return $this->profile()->isValidStoreUrl($baseUrl);
    }

    /**
     * Identifiant du magasin d'une adresse de base (le premier groupe de chiffres, zéros initiaux conservés), `null` si l'adresse
     * n'est pas celle d'un magasin de l'enseigne ou si l'identifiant du magasin est explicite chez elle (DRV-720 : rien ne s'en déduit).
     */
    public function storeIdOf(string $baseUrl): ?string
    {
        return $this->profile()->storeIdOf($baseUrl);
    }

    /**
     * L'identifiant a la forme d'un identifiant de magasin de l'enseigne (`storeIdPattern`, longueur comprise).
     */
    public function isValidStoreId(string $storeId): bool
    {
        return $this->profile()->isValidStoreId($storeId);
    }

    public function isValidProductId(string $retailerProductId): bool
    {
        return $this->profile()->isValidProductId($retailerProductId);
    }
}
