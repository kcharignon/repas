<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Model\AppRelease;

/**
 * Les versions publiées de l'application que le serveur connaît (DRV-637), lues dans ses réglages (`APP_LATEST_VERSION`,
 * `APP_MIN_VERSION`). Ne lève jamais : un réglage vide ou mal formé vaut « inconnu ».
 */
interface AppReleaseProvider
{
    public function current(): AppRelease;
}
