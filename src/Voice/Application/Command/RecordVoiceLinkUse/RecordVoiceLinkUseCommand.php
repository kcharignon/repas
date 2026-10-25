<?php

namespace Repas\Voice\Application\Command\RecordVoiceLinkUse;


use DateTimeImmutable;

/**
 * Une requête authentique d'Alexa vient de servir cette liaison (ADR 0029, VOX-204). Tout vient de l'authentification qui vient de
 * réussir : `linkId` et `userId` du jeton résolu, `knownAlexaUserId` et `lastUsedAt` de la liaison telle que cette résolution l'a lue,
 * `alexaUserId` de la requête. Jamais le jeton, jamais la phrase dite.
 */
final readonly class RecordVoiceLinkUseCommand
{
    public function __construct(
        public string $linkId,
        public string $userId,
        /** L'identifiant d'utilisateur Alexa de cette requête (`session.user.userId`), s'il y en a un. */
        public ?string $alexaUserId,
        /** Celui que la liaison connaît déjà, `null` tant qu'Alexa ne s'est pas présentée. */
        public ?string $knownAlexaUserId,
        /** La date de dernier usage que la liaison portait quand le jeton a été résolu. */
        public ?DateTimeImmutable $lastUsedAt,
    ) {
    }
}
