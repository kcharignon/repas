<?php

namespace Repas\Repas\Domain\Model;

enum ShoppingListStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case COMPLETED = 'COMPLETED';
}
