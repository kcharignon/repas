<?php

namespace Repas\Repas\Domain\Interface;


use Repas\Shared\Domain\Exception\RateLimitedException;

/**
 * Le plafond des téléchargements d'image par lien d'un administrateur (ADR 0039) : 30 par heure et par utilisateur du jeton. Il compte les APPELS
 * SORTANTS ; l'envoi d'un fichier ne le consomme pas. L'état est dans le cache, aucune requête SQL.
 */
interface ImageDownloadLimiter
{
    /**
     * Consomme un téléchargement de ce plafond.
     *
     * @throws RateLimitedException le plafond est atteint (`429 IMAGE_DOWNLOAD_RATE_LIMITED` et le délai d'attente) : rien n'est téléchargé
     */
    public function consume(string $userId): void;
}
