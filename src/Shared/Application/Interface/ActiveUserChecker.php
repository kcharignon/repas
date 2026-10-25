<?php

namespace Repas\Shared\Application\Interface;


/**
 * Dit si un compte est actif (ADR 0021 : un compte qui n'est pas actif ne peut plus rien). Port de `Shared` : un contexte qui agit pour un
 * utilisateur sans être authentifié par son JWT (la commande vocale, ADR 0029 : un jeton d'accès Alexa ne dit rien du compte) doit pouvoir
 * l'ignorer s'il est désactivé, en attente ou supprimé, sans importer `User`. Il est implémenté par `User`.
 *
 * Ce n'est pas un `…Loader` : un `CommandHandler` l'appelle (règle Deptrac `no-loader-in-commandhandler`).
 */
interface ActiveUserChecker
{
    /**
     * `true` seulement pour un compte qui existe et dont le statut est `ACTIVE`. Un compte inconnu (supprimé) n'est pas actif.
     */
    public function isActive(string $userId): bool;
}
