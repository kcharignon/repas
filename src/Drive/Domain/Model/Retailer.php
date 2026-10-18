<?php

namespace Repas\Drive\Domain\Model;


/**
 * Enseigne dont on sait remplir le panier. Porte la règle d'hôte autorisé : l'adresse d'un magasin n'est acceptée que si elle
 * est sur le domaine de l'enseigne (jamais une adresse libre, qu'un lien piégé pourrait détourner).
 */
enum Retailer: string
{
    case LECLERC = 'leclerc';

    /** @var string Adresse de base d'un magasin E.Leclerc Drive : `https://<sous-domaine>.leclercdrive.fr/magasin-<id>-<id>` */
    private const string LECLERC_STORE_URL = '#^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$#D';

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
        $pattern = match ($this) {
            self::LECLERC => self::LECLERC_STORE_URL,
        };

        return preg_match($pattern, $baseUrl, $matches) === 1 ? $matches[1] : null;
    }
}
