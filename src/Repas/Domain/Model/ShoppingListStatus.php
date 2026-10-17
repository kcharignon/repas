<?php

namespace Repas\Repas\Domain\Model;

enum ShoppingListStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    /** Envoyée au drive : non modifiable (ADR 0023) */
    case SENT = 'SENT';
    case COMPLETED = 'COMPLETED';
}
