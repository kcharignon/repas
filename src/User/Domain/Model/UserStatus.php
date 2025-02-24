<?php

namespace Repas\User\Domain\Model;


enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
