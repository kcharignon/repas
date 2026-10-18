<?php

namespace Repas\Drive\Domain\Model;


enum PackStatus: string
{
    /** Le nombre de produits est calculé de façon fiable. */
    case COMPUTED = 'COMPUTED';
    /** Le nombre est une valeur par défaut ou n'existe pas : l'agent demande la quantité à l'utilisateur. */
    case TO_CHECK = 'TO_CHECK';
}
