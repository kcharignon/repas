<?php

namespace Repas\User\Infrastructure\Security;


use RuntimeException;

/**
 * Aucune clé publique de Google utilisable : jamais téléchargées, ou il y a plus de 24 h, et Google ne répond pas ou répond
 * mal. Le vérificateur refuse alors le jeton (échec fermé). La raison est technique (statut HTTP, réseau, forme), jamais une
 * donnée de la personne.
 */
final class GoogleSigningKeysUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('Clés publiques de Google indisponibles (%s).', $reason));
    }
}
