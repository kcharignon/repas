<?php

namespace Repas\Drive\Domain\Model;


/**
 * D'où vient l'identifiant du magasin chez une enseigne (DRV-720, `docs/drive/spikes/DRV-715-decision-auchan.md` § 2).
 */
enum StoreIdSource: string
{
    /** Le magasin est une adresse : son identifiant s'en déduit (premier groupe de la règle d'adresse). Le cas historique. */
    case URL = 'URL';
    /** Le magasin n'est pas dans l'adresse (constante d'enseigne) : l'agent lit son identifiant sur le site, le back le contrôle par `storeIdPattern`. */
    case EXPLICIT = 'EXPLICIT';
}
