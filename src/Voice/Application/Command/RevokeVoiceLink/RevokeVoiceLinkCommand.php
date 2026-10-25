<?php

namespace Repas\Voice\Application\Command\RevokeVoiceLink;


/**
 * L'utilisateur retire une liaison avec Alexa (ADR 0029 décision 6) : la liaison et tous ses jetons.
 */
final readonly class RevokeVoiceLinkCommand
{
    public function __construct(
        public string $userId,
        public string $linkId,
    ) {
    }
}
