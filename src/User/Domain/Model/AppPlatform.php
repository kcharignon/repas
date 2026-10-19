<?php

namespace Repas\User\Domain\Model;


/**
 * Plateforme de l'application installée qui envoie sa version (DRV-637). Seule Android existe : l'iPhone viendra plus tard (D5),
 * avec sa propre ligne (la plateforme est dans la clé de `user_app_version`).
 */
enum AppPlatform: string
{
    case ANDROID = 'android';
}
