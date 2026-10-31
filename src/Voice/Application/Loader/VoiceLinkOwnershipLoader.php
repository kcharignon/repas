<?php

namespace Repas\Shared\Application\Interface;


use Repas\Shared\Domain\Exception\DomainException;

/**
 * Port de lecture du propriétaire d'une liaison avec Alexa (ADR 0029), pour le voter des droits (`OwnerVoter`, attribut
 * `VOICE_LINK_OWNER`). À part de `OwnershipLoader` : les liaisons sont au contexte `Voice`, que `Repas` ne connaît pas. Il ne renvoie
 * qu'un identifiant d'utilisateur, jamais un agrégat.
 */
interface VoiceLinkOwnershipLoader
{
    /**
     * @throws DomainException la liaison n'existe pas (code 404)
     */
    public function voiceLinkOwnerId(string $linkId): string;
}
