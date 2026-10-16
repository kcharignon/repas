<?php

namespace Repas\User\Domain\Model;


enum UserStatus: string
{
    /** Inscrit par lui-même, adresse email pas encore confirmée : il ne peut pas se connecter (ADR 0020). */
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
