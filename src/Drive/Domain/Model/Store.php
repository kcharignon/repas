<?php

namespace Repas\Drive\Domain\Model;


use Repas\Drive\Domain\Exception\DriveException;

/**
 * Le magasin où l'utilisateur retire ses courses : lu par l'agent sur le site de l'enseigne, jamais saisi à la main.
 */
final readonly class Store
{
    /** Limites des colonnes de `drive_settings` : une valeur plus longue est refusée plutôt que tronquée. */
    public const int ID_MAX_LENGTH = 20;
    public const int LABEL_MAX_LENGTH = 255;
    public const int BASE_URL_MAX_LENGTH = 255;

    private function __construct(
        public string $id,
        public ?string $label,
        public string $baseUrl,
    ) {
    }

    /**
     * @throws DriveException `storeInvalid()` si l'adresse n'est pas celle d'un magasin de l'enseigne, si l'identifiant n'est
     *                        pas celui de l'adresse ou si une valeur dépasse sa limite. Un libellé vide devient `null`.
     */
    public static function create(Retailer $retailer, string $id, ?string $label, string $baseUrl): self
    {
        $label = $label === null || trim($label) === '' ? null : trim($label);

        if (
            !$retailer->isValidStoreUrl($baseUrl)
            || $retailer->storeIdOf($baseUrl) !== $id
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
}
