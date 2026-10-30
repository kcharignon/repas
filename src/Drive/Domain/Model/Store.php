<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Le magasin où l'utilisateur retire ses courses : lu par l'agent sur le site de l'enseigne, jamais saisi à la main.
 *
 * Son identifiant vient de l'adresse ou de l'agent selon l'enseigne (`StoreIdSource`, DRV-720) : chez une enseigne où le magasin est
 * une adresse, l'identifiant s'en déduit et doit être celui de l'adresse ; chez une enseigne où l'adresse est une constante,
 * l'identifiant est lu par l'agent et le back ne contrôle que sa forme.
 */
final readonly class Store
{
    /**
     * Limites des colonnes `store_id` (`drive_settings`, `drive_product`, `drive_choice`), de `store_label` et de `base_url` : une
     * valeur plus longue est refusée plutôt que tronquée. `ID_MAX_LENGTH` est la borne absolue de toute enseigne (un UUID y tient) ;
     * le `storeIdPattern` d'une enseigne peut être plus strict.
     */
    public const int ID_MAX_LENGTH = 40;
    public const int LABEL_MAX_LENGTH = 255;
    public const int BASE_URL_MAX_LENGTH = 255;

    private function __construct(
        public string $id,
        public ?string $label,
        public string $baseUrl,
    ) {
    }

    /**
     * @throws DriveException `storeInvalid()` si l'adresse n'est pas celle d'un magasin de l'enseigne, si l'identifiant n'a pas la
     *                        forme de l'enseigne ou, quand il se déduit de l'adresse, n'est pas celui de l'adresse, ou si une valeur
     *                        dépasse sa limite. Un libellé vide devient `null`.
     */
    public static function create(Retailer $retailer, string $id, ?string $label, string $baseUrl): self
    {
        return self::createFor($retailer->profile(), $id, $label, $baseUrl);
    }

    /**
     * Comme `create()`, d'après un profil d'enseigne explicite (le profil d'une enseigne : `Retailer::profile()`).
     *
     * @throws DriveException `storeInvalid()` (mêmes cas que `create()`)
     */
    public static function createFor(RetailerProfile $profile, string $id, ?string $label, string $baseUrl): self
    {
        $label = $label === null || trim($label) === '' ? null : trim($label);

        if (
            !$profile->isValidStoreUrl($baseUrl)
            || !self::idMatchesAddress($profile, $id, $baseUrl)
            || !$profile->isValidStoreId($id)
            || strlen($id) > self::ID_MAX_LENGTH
            || strlen($baseUrl) > self::BASE_URL_MAX_LENGTH
            || ($label !== null && mb_strlen($label) > self::LABEL_MAX_LENGTH)
        ) {
            throw DriveException::storeInvalid();
        }

        return new self($id, $label, $baseUrl);
    }

    /**
     * Reconstruction depuis la persistance : aucun contrôle.
     *
     * @param array{id: string, label: ?string, base_url: string} $datas
     */
    public static function load(array $datas): self
    {
        return new self($datas['id'], $datas['label'], $datas['base_url']);
    }

    /**
     * Un identifiant déduit de l'adresse doit être celui de l'adresse ; un identifiant explicite n'a aucun rapport avec elle (une
     * constante d'enseigne), rien à comparer.
     */
    private static function idMatchesAddress(RetailerProfile $profile, string $id, string $baseUrl): bool
    {
        return $profile->storeIdSource === StoreIdSource::EXPLICIT || $profile->storeIdOf($baseUrl) === $id;
    }
}
