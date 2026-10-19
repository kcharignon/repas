<?php

namespace Repas\User\Domain\Model;


/**
 * Ce que le serveur sait des versions publiées de l'application (DRV-637) : la dernière (`APP_LATEST_VERSION`) et la plus
 * ancienne encore attendue (`APP_MIN_VERSION`). Chacune peut être inconnue (réglage vide ou mal formé) : rien n'est alors
 * comparé à elle, et sans aucune des deux personne n'est invité à mettre à jour.
 */
final readonly class AppRelease
{
    public function __construct(
        public ?AppVersion $latest,
        public ?AppVersion $minimum,
    ) {
    }

    /**
     * Depuis les réglages du serveur : une valeur vide, faite d'espaces ou qui n'est pas une version vaut « inconnue », sans
     * erreur (une faute de frappe dans un réglage ne doit pas faire échouer la lecture du compte).
     */
    public static function fromSettings(?string $latest, ?string $minimum): self
    {
        return new self(
            AppVersion::tryFromString($latest === null ? null : trim($latest)),
            AppVersion::tryFromString($minimum === null ? null : trim($minimum)),
        );
    }

    /**
     * La version installée est-elle à mettre à jour : plus ancienne que la dernière publiée, ou que la minimale ? Une version
     * inconnue (navigateur, application qui ne l'a jamais envoyée) n'est jamais à mettre à jour.
     */
    public function isOutdated(?AppVersion $installed): bool
    {
        if ($installed === null) {
            return false;
        }

        return ($this->latest !== null && $installed->isOlderThan($this->latest))
            || ($this->minimum !== null && $installed->isOlderThan($this->minimum));
    }
}
