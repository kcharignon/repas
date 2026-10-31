<?php

namespace Repas\Voice\Application\Loader;


use Repas\Shared\Domain\Exception\DomainException;

/**
 * Port de lecture du propriétaire d'une liaison avec Alexa (ADR 0029), pour le voter des droits du contexte
 * (`Infrastructure\Security\VoiceLinkOwnerVoter`, attribut `VOICE_LINK_OWNER`). Il ne renvoie qu'un identifiant d'utilisateur, jamais un
 * agrégat.
 */
interface VoiceLinkOwnershipLoader
{
    /**
     * @throws DomainException la liaison n'existe pas (code 404)
     */
    public function voiceLinkOwnerId(string $linkId): string;
}
