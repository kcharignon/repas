# DRV-320 — Lecture du magasin depuis l'application (`drive.setup`) et menu natif

- **Dépôt** : front (`kcharignon/repas-front`) : `drive-agent/protocol`, `android/`, `src/features/drive/`
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-314, DRV-307, DRV-403 (faits)
- **Statut** : fait (PR [repas-front#111](https://github.com/kcharignon/repas-front/pull/111), 2026-10-18 ; agent 0.7.0 ; issue front #103 fermée)
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

## Réalisation (2026-10-18, PR repas-front#111)

- **Protocole** : le `store` est **facultatif** dans `setup` et dans `result`, **obligatoire** dans `plan` et dans `setup-result`. Un `setup` sans `store` fait lire à l'agent le magasin que la page affiche (dans son adresse, sans requête) ; avec un `store`, la page doit être celle de ce magasin, sinon `STORE_MISMATCH`. Un `result` sans `store` : une lecture qui s'arrête avant que le magasin soit connu (`aborted`, `UNSUPPORTED_PAGE`). Schémas, exemples valides et invalides dans `drive-agent/protocol/` du front.
- **Application** : message `drive.setup` (page vers application) `{"v":1,"type":"drive.setup","runId":"<uuid>"}` ; ouvre `DriveActivity` sur `https://www.leclercdrive.fr/` avec le message « Connecte-toi à Leclerc si besoin, puis choisis ton magasin. Repas le lira ensuite. Le bouton retour du téléphone annule. » ; le `setup` n'est envoyé à l'agent que lorsque la page est celle d'un magasin (`/magasin-<id>-<id>[-Ville]/…`) ; l'agent demande « Enregistrer ce magasin dans Repas ? » puis renvoie un `setup-result` (`label` toujours `null`), que l'application livre à Repas par `drive.result`. Même file, même unicité d'un passage, même abandon (`aborted`) et même reprise sous 24 h que `drive.start` ; erreurs `BUSY` (avec le `runId` occupant), `INVALID_SETUP` (`runId` absent ou qui n'est pas un UUID), `UNAVAILABLE`, `NO_PASS`. Contrat complet : `android/README.md` du front (« La lecture du magasin »). Test de fumée de l'émulateur étendu.
- **Front** : `readStoreFromApp()` et `canReadStoreFromApp()` (`storeReader.ts`) branchés sur `drive.setup` par `driveBridge` ; le bouton « Lire mon magasin dans l'application » apparaît dans les réglages quand le pont est disponible ; « Mon compte » gagne « Menu de l'application » (section « Drive », **visible seulement dans l'application et fonction allumée**), qui envoie `app.menu`.
- **Reste à essayer sur téléphone** (recette DRV-505, [`../recette.md`](../recette.md) partie 4) : la page de choix du magasin du vrai site Leclerc. L'application attend une adresse `/magasin-<id>-<id>` ; le test de fumée ne l'a essayée que sur le faux site.
- **Point à relire** : « Menu de l'application » disparaît quand la fonction est coupée ; dans l'application de publication, « Se déconnecter de Leclerc et effacer les données du drive » est alors inatteignable (la version de test garde le bouton ⋮).
