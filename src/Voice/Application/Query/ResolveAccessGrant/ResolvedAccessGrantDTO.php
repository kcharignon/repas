<?php

namespace Repas\Voice\Application\Query\ResolveAccessGrant;


use DateTimeImmutable;

/**
 * Le compte et la liaison d'un jeton d'accès valable. Jamais le jeton, ni son haché.
 *
 * `alexaUserId` et `lastUsedAt` sont ce que la liaison retient déjà d'Alexa, lus dans la même requête que le jeton : le webhook en juge,
 * sans requête de plus, s'il faut compléter l'identifiant d'utilisateur Alexa ou rafraîchir la date de dernier usage
 * (`RecordVoiceLinkUse`, VOX-204).
 */
final readonly class ResolvedAccessGrantDTO
{
    public function __construct(
        public string $userId,
        public string $linkId,
        public ?string $alexaUserId = null,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {
    }
}
