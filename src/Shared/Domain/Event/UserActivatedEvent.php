<?php

namespace Repas\Shared\Domain\Event;


/**
 * Un compte vient de devenir ACTIF (ADR 0028, amendement du 2026-10-20) : l'adresse a été confirmée par le lien reçu, le lien de mot de
 * passe oublié a activé un compte en attente, un administrateur l'a activé (ou réactivé), ou la connexion avec Google l'a créé ou l'a
 * lié à un compte en attente. Un compte actif qui le reste n'émet rien ; l'inscription publique non plus (le compte naît `PENDING`).
 *
 * L'événement est annoncé APRÈS l'enregistrement du compte, dans la transaction du bus de commandes : ses écouteurs agissent donc dans la
 * même transaction que l'activation, et si l'un échoue, l'activation est annulée avec lui. Le contexte `Repas` y répond en copiant au
 * compte les ingrédients « de base » du propriétaire du catalogue (`SeedIngredientsOnUserActivatedListener`). Il ne porte que
 * l'identifiant : le contexte `Repas` n'a ni à lire le compte ni à importer son modèle.
 *
 * Tout gestionnaire qui active un compte DOIT l'annoncer : `UserActivationDispatchesEventTest` échoue sinon.
 */
readonly class UserActivatedEvent
{
    public function __construct(
        public string $userId,
    ) {
    }
}
