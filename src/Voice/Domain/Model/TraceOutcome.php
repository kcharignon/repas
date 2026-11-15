<?php

namespace Repas\Voice\Domain\Model;


/**
 * Comment un échange avec Alexa s'est terminé, tel que la trace le retient (ADR 0029 décision 14). La valeur est celle écrite dans
 * `voice_trace.outcome` : jamais changée sans migration des lignes existantes.
 */
enum TraceOutcome: string
{
    /** Une réponse vocale normale (ajout, retrait, question, dialogue, aide, au revoir…) : l'issue ordinaire. */
    case ANSWERED = 'ANSWERED';
    /** `SessionEndedRequest` : Alexa ferme la séance, la réponse est vide. */
    case SESSION_ENDED = 'SESSION_ENDED';
    /** Une langue autre que le français : le repli poli. */
    case UNSUPPORTED_LANGUAGE = 'UNSUPPORTED_LANGUAGE';
    /** Jeton absent, inconnu, expiré, liaison retirée ou compte inactif (indiscernables) : la carte `LinkAccount`. */
    case ACCOUNT_NOT_LINKED = 'ACCOUNT_NOT_LINKED';
    /** Plafond de 60 requêtes par minute et par liaison : « Un instant, réessaie dans une minute. » */
    case LINK_RATE_LIMITED = 'LINK_RATE_LIMITED';
    /** Un échec CONNU (une recette sans conversion, une recette disparue) : une phrase claire, rien n'a été écrit. */
    case KNOWN_FAILURE = 'KNOWN_FAILURE';
    /** Une panne inattendue : la phrase d'échec générique. */
    case FAILED = 'FAILED';

    /** Le mot que la commande de lecture écrit à l'écran. */
    public function label(): string
    {
        return match ($this) {
            self::ANSWERED => 'répondu',
            self::SESSION_ENDED => 'séance terminée',
            self::UNSUPPORTED_LANGUAGE => 'langue non servie',
            self::ACCOUNT_NOT_LINKED => 'compte non lié',
            self::LINK_RATE_LIMITED => 'plafond de liaison',
            self::KNOWN_FAILURE => 'échec connu',
            self::FAILED => 'PANNE',
        };
    }
}
