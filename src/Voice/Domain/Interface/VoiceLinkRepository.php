<?php

namespace Repas\Voice\Domain\Interface;


use DateTimeImmutable;
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

    /**
     * Retient que la liaison vient de servir : une SEULE instruction qui ne touche que `last_used_at` (jamais `save()`, qui recopierait
     * tout le modèle et écraserait ce qu'une autre requête vient de changer, une révocation par exemple). Sans effet sur une liaison
     * révoquée, et ne recule jamais la date.
     */
    public function recordUse(string $linkId, DateTimeImmutable $at): void;

    /**
     * Donne à la liaison l'identifiant d'utilisateur Alexa vu dans sa première requête, SI elle n'en a pas encore et n'est pas révoquée :
     * une seule instruction conditionnelle, qui ne change jamais un identifiant déjà connu. Dit si c'est CET appel qui l'a posé (de deux
     * requêtes simultanées, une seule le pose).
     */
    public function identifyAlexaUser(string $linkId, string $alexaUserId): bool;

    /**
     * Les AUTRES liaisons du compte, non révoquées, qui portent déjà cet identifiant d'utilisateur Alexa : un même Echo relié deux fois.
     * Jamais celles d'un autre compte. Plus ancienne d'abord.
     *
     * @return list<VoiceLink>
     */
    public function findOthersOfAlexaUser(string $userId, string $alexaUserId, string $exceptLinkId): array;
}
