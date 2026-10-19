# DRV-641 — Ne jamais verrouiller la liste si l'envoi ne peut pas se faire

- **Dépôt** : front (web seulement)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : DRV-640
- **Statut** : fait (PR front #161, site v0.5.8, 2026-10-19). Question de l'utilisateur : « est-il normal d'avoir le bouton « Envoyer au drive » depuis le site si cela ne marche que depuis l'application mobile ? »

## Contexte

« Envoyer au drive » posait le verrou de la liste (statut `SENT`) avant d'arriver à l'écran d'envoi. Dans un navigateur, cet écran répondait « pas disponible ici » ; avec une application trop ancienne (DRV-640), « Mets à jour l'application ». Dans les deux cas la liste restait verrouillée pour rien.

## Réalisation

- Navigateur (pas de pont natif) : plus de bouton « Envoyer au drive » sur une liste en cours ; les listes déjà envoyées gardent leurs actions.
- Application trop ancienne ou version muette : le bouton reste ; au toucher, la phrase de DRV-640 et le bouton « Mettre à jour », **sans** `POST …/send`.
- Application à jour : inchangé.
- Reste connu : si le pont annonce `drive: false`, le verrou précède encore l'explication (à traiter avec DRV-631).
