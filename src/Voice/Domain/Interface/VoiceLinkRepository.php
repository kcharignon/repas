<?php

namespace Repas\Voice\Domain\Interface;


use Repas\Voice\Domain\Model\VoiceLink;

/**
 * Charger pour modifier : les liaisons d'un compte avec Alexa (ADR 0029). Pour afficher, `VoiceLinkLoader`.
 */
interface VoiceLinkRepository
{
    public function findOneById(string $id): ?VoiceLink;

    /**
     * La liaison du compte qu'Alexa n'a pas encore utilisée (identifiant d'utilisateur Alexa inconnu) et qui n'est pas révoquée, la plus
     * récente s'il y en a plusieurs : un nouvel échange de jetons la réutilise au lieu d'en créer une deuxième (« sinon une seule
     * liaison `alexa` », VOX-202).
     */
    public function findAwaitingAlexaOfUser(string $userId): ?VoiceLink;

    public function save(VoiceLink $link): void;
}
