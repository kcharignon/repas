<?php

namespace Repas\Repas\Domain\Model;

enum ShoppingListStatus: string
{
    case PLANNING = 'PLANNING';
    case ACTIVE = 'ACTIVE';
    case SHOPPING = 'SHOPPING';
    case COMPLETED = 'COMPLETED';
}
