<?php

namespace Repas\User\Domain\Interface;

use Repas\User\Domain\Model\UserAppVersion;

/**
 * Les versions de l'application vues pour chaque compte (table `user_app_version`, DRV-637), une ligne par compte et par
 * plateforme. Aucune clé étrangère ne lie une ligne à son compte : la suppression du compte l'efface (`deleteAllOfUser()`,
 * ADR 0021).
 */
interface UserAppVersionRepository
{
    /**
     * Retient la version vue pour ce compte et cette plateforme : elle remplace la précédente, avec son heure (même version :
     * seule l'heure change). Idempotent.
     *
     * N'écrit rien si le compte n'existe pas, ou plus : une requête qui croise la suppression du compte ne fait pas renaître sa
     * ligne (DRV-637, « Pièges connus »). L'écriture et la suppression du compte se sérialisent sur la ligne du compte.
     */
    public function save(UserAppVersion $appVersion): void;

    /**
     * Supprime les versions du compte (suppression du compte, ADR 0021) ; celles des autres comptes restent.
     */
    public function deleteAllOfUser(string $userId): void;
}
