<?php

namespace Repas\Repas\Application\Command\StoreImageFromUrl;


/**
 * Télécharger l'image dont l'administrateur a collé le lien direct, et la garder (ADR 0039). `url` est le texte reçu : `ImageUrl::parse` le juge
 * strictement avant tout appel sortant. `userId` est l'administrateur du jeton (le plafond des téléchargements est le sien).
 */
readonly class StoreImageFromUrlCommand
{
    public function __construct(
        public string $url,
        public string $userId,
    ) {
    }
}
