# DRV-320 — Lecture du magasin depuis l'application (`drive.setup`) et menu natif

- **Dépôt** : front (`kcharignon/repas-front`) : `drive-agent/protocol`, `android/`, `src/features/drive/`
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-314, DRV-307, DRV-403 (faits)
- **Statut** : à faire
- **Lire d'abord** : contrat du pont dans [`DRV-314`](DRV-314-passage-du-plan-et-retour.md) (« Réalisation et contrat final »), [`DRV-307`](DRV-307-mode-configuration.md), [`DRV-403`](DRV-403-reglages-drive-ui.md) (« Réalisation et reste à brancher »), skills `repas-android-dev`, `repas-agent-dev`, `repas-front-dev`, `docs/drive/consignes-agents.md`.

## Contexte

Les réglages (DRV-403) ont un bouton « Lire mon magasin dans l'application » dont l'accroche (`storeReader.ts`) n'est pas branchée : le pont n'a pas de message `drive.setup`. De plus, l'application n'ouvre son menu natif que sur `app.menu`, que le front n'appelle nulle part : « Se déconnecter de Leclerc » est inatteignable en publication.

## Portée

1. **Protocole** : le message `setup` doit pouvoir être envoyé **sans `store`** (le front ne connaît pas encore le magasin) ; l'agent lit alors le magasin que la page affiche. Avec un `store`, le comportement actuel est conservé (`STORE_MISMATCH`). Schéma JSON, validateur, exemples valides et invalides, tests ; `make agent-verify` et `make android-agent-hash` (cliquet).
2. **Application** : message `drive.setup {runId}` (page → application) : ouvre `DriveActivity` sur l'accueil du drive (`https://www.leclercdrive.fr/`), l'utilisateur se connecte et choisit son magasin (message à l'écran en français simple), l'agent exécute `setup` quand la page est une page de magasin, renvoie `setup-result` ; l'application le poste à Repas dans `drive.result {result}` (mêmes règles de file, d'unicité d'un passage et d'abandon que `drive.start`, erreurs `BUSY`, `INVALID_PLAN`→`INVALID_SETUP`, `NO_PASS`). Tests Kotlin, test de fumée de l'émulateur étendu (sans Leclerc), contrat documenté dans `android/README.md`.
3. **Front** : brancher `readStoreFromApp()` / `canReadStoreFromApp()` (`storeReader.ts`) sur `drive.setup` via `driveBridge` (ajouter `startSetup()` et le traitement de l'événement `setup-result` déjà validé) ; le bouton apparaît dans les réglages quand le pont est disponible ; l'adresse et l'identifiant lus remplissent le formulaire ; erreurs lisibles ; « Mon compte » gagne une entrée « Menu de l'application » (visible seulement dans l'application) qui envoie `app.menu` ; tests Vitest et e2e (pont simulé).

## Hors portée

La publication signée (DRV-315), l'émulateur complet (DRV-319 partie 2).

## Critères d'acceptation

- Dans les réglages, dans l'application, « Lire mon magasin » ouvre le drive, et au retour le formulaire est rempli avec l'identifiant et l'adresse du magasin choisi.
- Un `setup` sans `store` est accepté ; avec un `store` différent de la page, `STORE_MISMATCH`.
- Dans « Mon compte », « Menu de l'application » ouvre le menu natif.

## Definition of Done

Celle du dépôt front ; `make agent-verify`, `make android-agent-hash`, `make android-test`, `make check` verts ; PR concise ; fusion si tous les contrôles de la CI sont verts.
