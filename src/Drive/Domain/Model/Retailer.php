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
    case AUCHAN = 'auchan';

    /**
     * Les identifiants, pour les attributs OpenAPI (qui n'acceptent qu'une expression constante) ; `RetailerTest` la garde contre
     * `cases()`.
     *
     * @var list<string>
     */
    public const array IDS = ['leclerc', 'auchan'];

    /**
     * Le repli de la sélection de l'enseigne (`RetailerSelection::retailerToUse()`, DRV-703) : **un seul endroit** (DRV-701). Une demande qui ne
     * nomme aucune enseigne prend celle de la sélection ; ce n'est que sans enseigne configurée qu'elle retombe sur celle-ci.
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
                // Pas d'offre : un produit n'a que son identifiant (DRV-721)
                offerIdPattern: null,
            ),
            // DRV-716 : déclarée, mais éteinte tant que `DRIVE_RETAILERS` ne la contient pas (DRV-702)
            self::AUCHAN => new RetailerProfile(
                name: 'Auchan',
                nameDe: 'd’Auchan',
                // Adresse constante : le magasin n'est pas dans l'adresse, l'agent lit son identifiant sur le site (DRV-720) ; aucun groupe
                storeAddressPattern: '^https://www\.auchan\.fr/$',
                storeAddressExample: 'https://www.auchan.fr/',
                // Le produit, son offre dans le magasin (DRV-721) et le magasin sont des UUID (36 caractères, sous la borne de 40)
                productIdPattern: RetailerProfile::UUID_ID,
                storeIdSource: StoreIdSource::EXPLICIT,
                storeIdPattern: RetailerProfile::UUID_ID,
                offerIdPattern: RetailerProfile::UUID_ID,
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

    /**
     * L'identifiant a la forme d'un identifiant d'offre de l'enseigne ; toujours `false` quand elle n'a pas d'offre (DRV-721).
     */
    public function isValidOfferId(string $offerId): bool
    {
        return $this->profile()->isValidOfferId($offerId);
    }
}
