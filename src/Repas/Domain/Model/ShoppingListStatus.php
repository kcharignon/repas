<?php

namespace Repas\Repas\Domain\Model;

enum ShoppingListStatus: string
{
    case PLANNING = 'PLANNING';
    case SHOPPING = 'SHOPPING';
    case COMPLETED = 'COMPLETED';
}
