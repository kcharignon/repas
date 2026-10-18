# DRV-407 — ADR front 0008 et `CLAUDE.md` du front

- **Dépôt** : front, `docs/adr/`
- **Jalon** : M4 Front
- **Taille** : S
- **Dépend de** : DRV-404
- **Statut** : fait (PR front #115, 2026-10-18)
- **Lire d'abord** : `docs/adr/README.md` du front ; ADR 0003 et 0006 du front (usage du téléphone) ; [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md) du back.

## Portée

1. ADR front `0008-envoi-de-la-liste-au-drive.md` (gabarit du front) : le pont `driveBridge`, le fragment d'URL, l'agent dans `drive-agent/`, le gabarit servi sans domaine et substitué à l'installation, le brouillon de passage, l'ouverture dans Chrome complet, l'absence d'image externe en v1, la version minimale de l'agent.
2. Amender les ADR 0003 et 0006 du front : le téléphone sert aussi à préparer et envoyer la liste au drive (et plus seulement à consulter et cocher en magasin).
3. `CLAUDE.md` du front : section « Drive » (où est l'agent, `make agent-build agent-test agent-e2e`, règle « jamais de code distant », « jamais de HTML brut Leclerc dans le dépôt », renvoi vers `docs/drive/README.md` du dépôt back) ; `PARITY.md` si besoin.
4. Index des ADR du front mis à jour.

## Critères d'acceptation

ADR et `CLAUDE.md` relus, cohérents avec l'ADR 0022 du back (qui gagne en cas d'écart).

## Réalisation (2026-10-18, PR repas-front#115)

- ADR front `0008-envoi-de-la-liste-au-drive.md` : application Android qui charge le front, pont natif `repasNative`/`driveBridge` (seul à le connaître), agent embarqué dans l'APK et jamais servi par le front, protocole partagé (`@drive-protocol`), plan relu à chaque ouverture, brouillon de passage, retour recoupé avec le plan, aucune image externe, interrupteur du back, dépôt public. Index des ADR à jour.
- ADR front 0003 et 0006 amendés (le téléphone envoie aussi la liste ; rien du drive hors ligne ; le brouillon survit au rechargement du service worker). `CLAUDE.md` du front : renvoi à l'ADR, routes et modules du drive, règles du pont.
- Écart au ticket : fragment d'URL, gabarit sans domaine, ouverture dans Chrome complet et version minimale de l'agent n'existent plus (remplacés par le pont natif, G1 puis D28) : l'ADR décrit ce qui existe.
