<?php

namespace Repas\Drive\Domain\Model;


/**
 * Ce que Repas fait de l'enseigne à utiliser (docs/drive/multi-enseignes.md § 5.1, DRV-703) : `NONE` aucune enseigne n'est configurée (mener
 * la personne aux réglages), `AUTO` une seule enseigne est configurée (l'utiliser sans rien demander, même avec un drive habituel), `ASK` plusieurs
 * le sont (proposer le choix, jamais `AUTO` avec deux candidates ou plus ; l'enseigne de la sélection est la présélection).
 */
enum SelectionMode: string
{
    case NONE = 'NONE';
    case AUTO = 'AUTO';
    case ASK = 'ASK';

    /**
     * Les valeurs, pour les attributs OpenAPI (qui n'acceptent qu'une expression constante) ; `SelectionModeTest` la garde contre `cases()`.
     *
     * @var list<string>
     */
    public const array VALUES = ['NONE', 'AUTO', 'ASK'];
}
