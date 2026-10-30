# DRV-707 — Écran du drive, injection, profil, menu et `app.info` par enseigne

- **Dépôt** : front (`android/app/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-706
- **Statut** : fait (PR front #252, 2026-10-30). Écarts : `make android-e2e` complet n'est pas vert (scénarios 2 à 14 déjà rouges sur `main` : [DRV-729](DRV-729-realigner-les-scenarios-e2e-android.md)) ; les profils de WebView séparés ne sont pas prouvés dans l'émulateur (WebView 113 sans `MULTI_PROFILE`) : **à vérifier à la main sur un téléphone récent** (deux enseignes, se déconnecter de l'une, l'autre reste connectée) et que la mise à jour garde la connexion Leclerc (le profil `drive` n'est pas renommé). Effort recommandé : `high`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.5, 8, D-M6 ; `android/ARCHITECTURE.md` ; `android/README.md` (« Le passage du plan », pont `repasNative`) ; skill `repas-android-dev` ; skill `repas-po` (textes) ; DRV-312, 313, 317, 625.

## Contexte

`DriveActivity` ouvre « le » drive : navigation limitée à un domaine, mode ordinateur permanent, injection de l'agent dans `*.leclercdrive.fr`, un profil de WebView `drive` que « Se déconnecter de Leclerc » efface, des textes (`menu_open_drive`, `menu_logout_drive`, `clear_message*`, `plan_banner*`, `about_*`) qui disent Leclerc, et `app.info` qui n'annonce pas d'enseigne.

## Portée

1. **L'écran s'ouvre sur une enseigne** : extra d'intention `retailer` (validé contre `RetailerSites` ; absent ou inconnu = `leclerc`, comportement d'aujourd'hui). Navigation, accueil, mode (`siteMode`), injection et origines du pont sont ceux **de cette enseigne** seulement (règles de DRV-706).
2. **Un profil de WebView par enseigne** : `drive` reste le nom du profil de Leclerc (**ne déconnecte personne**), les autres enseignes ont `drive-<id>`. « Se déconnecter de … » n'efface que le profil de l'enseigne nommée ; le message de confirmation nomme l'enseigne (`nameDe`).
3. **Textes** : `strings.xml` prend le nom en paramètre (`%1$s`, forme avec « de » à part) ; menu : « Ouvrir le drive {nom} » et « Se déconnecter de {nom} » pour **chaque enseigne connue de l'application** (une seule aujourd'hui : le menu est identique).
4. **`app.info`** : `NativeBridge.appInfo(version, versionCode, drive, retailers)` ; le champ `retailers` (liste d'identifiants) est **facultatif pour le lecteur** et vaut `["leclerc"]` aujourd'hui. Le web l'ignore s'il ne le connaît pas (DRV-710 le lit).
5. **Le pont** (`drive.start`, `drive.setup`) porte l'enseigne du plan : le plan est livré à l'agent **de l'écran ouvert pour cette enseigne** ; `BUSY`/`NO_PASS`/`UNAVAILABLE` inchangés ; reprise d'un passage interrompu sous 24 h : l'enseigne du passage est mémorisée avec lui.
6. **Test automatique et émulateur** : `android/emulator/` reste Leclerc ; ajouter un scénario avec une **seconde enseigne fictive** servie par le faux site (hôte `*.exemple-b.test`) seulement pour prouver l'isolement (profils séparés, plan refusé sur la mauvaise page).

## Hors portée

Le domaine d'Auchan (DRV-716), la sélection de l'enseigne dans le web (DRV-710), le mode « assisté » sans agent.

## Critères d'acceptation

- Étant donné l'application avec Leclerc seul, alors `make android-e2e` est **identique** (mêmes scénarios, mêmes textes) et le menu montre les mêmes entrées.
- Étant donné une mise à jour de l'application par-dessus une version qui avait des cookies Leclerc, alors la personne **reste connectée** à Leclerc (profil `drive` conservé).
- Étant donné l'écran ouvert pour une enseigne fictive B, alors une adresse de l'enseigne A est confiée au navigateur du téléphone, et un plan de A est refusé (`INVALID_PLAN`).
- `app.info` répond `retailers: ["leclerc"]` ; un site ancien qui ne le connaît pas fonctionne comme avant.

## Tests exigés

`make android-test`, `android/__tests__/native-contract.spec.ts` (nouveau champ facultatif), `menu.spec.ts`, `shell.spec.ts`, `make android-e2e` sous le verrou de l'émulateur, test du libellé élidé, `make check`.

## Definition of Done

Celle du dépôt front et du skill `repas-android-dev` ; `android/README.md` (contrat du pont, champ `retailers`) et `ARCHITECTURE.md` à jour ; **ordre de publication** dit dans le rapport : le site web connaît le champ (DRV-710) ou l'ignore, donc aucun ordre imposé ; aucune étiquette `android-v…` créée (décision humaine).

## Pièges connus

- Renommer le profil `drive` déconnecterait tous les utilisateurs de Leclerc : ne pas y toucher.
- `addWebMessageListener` doit être posé avant le premier chargement, avec les origines de **l'enseigne de l'écran**.
- Sans profils (WebView ancienne), tout est mêlé à Repas : le message de déconnexion le dit déjà ; il doit rester vrai pour toutes les enseignes.
- Ne pas faire dépendre `retailers` d'un numéro de version : c'est une capacité (D-M2, § 8).

## Reste laissé par DRV-706 (2026-10-30)

L'application appelle encore les formes sans enseigne (zéro changement de comportement). À brancher ici : passer `site` partout (`PassRequest`, `OpeningWatch.canDeliver(request, url)`, `SetupRequest.of(runId, site)`, `DriveEntry.startPage(url, site)`) ; un `KeyValueStore` sur la préférence `drive-store` pour `StorePathMemories` / `FinalizeOutcomeMemories` (clés `path.<id>`, `finalize.<id>`, l'ancienne clé unique relue comme celle de `leclerc`) ; `android/emulator/e2e.sh` (lignes ~721 et ~798 : clé `finalize` devient `finalize.leclerc`) ; remplacer le `!NavigationPolicy.isAllowed(url)` d'`AgentHost.onPageFinished` par `site.isBridgePage`.
