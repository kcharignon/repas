# DRV-311 — Squelette de l'application Android (`android/`)

- **Dépôt** : front (`kcharignon/repas-front`), dossier `android/`
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-005 (décision G1, fait)
- **Statut** : fait (PR [repas-front#75](https://github.com/kcharignon/repas-front/pull/75), 2026-10-17)
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-005-decision-g1.md`](../spikes/DRV-005-decision-g1.md), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/android-lab/` (le spike, `PROCEDURE.md` compris), `CLAUDE.md` du front

## Contexte

Le spike `drive-agent/android-lab/` (outil jetable) a prouvé qu'une WebView en mode ordinateur fait fonctionner la recherche, l'ajout et les quantités sur Leclerc. Ce ticket pose la **vraie** application « Repas Drive » dans `android/` : même technique (Kotlin, une activité, AndroidX minimal), mais construite, testée et versionnée comme un produit.

## Portée

1. Créer `android/` à partir du spike (reprendre le Gradle, le Dockerfile de construction, la règle de navigation et le choix d'agent utilisateur ; ne pas reprendre le bouton « Banc », l'écran de diagnostic détaillé ni le banc d'essai). Identifiant d'application `eu.kantin.repas.drive`, nom « Repas Drive », `minSdk` 26, `targetSdk` à jour.
2. Cibles `make android` (construit l'APK de débogage), `make android-test` (tests Kotlin) et `make android-release` (APK signé avec une clé fournie par variables d'environnement ; refuse de construire sans clé). Tout dans Docker, comme le reste du front.
3. Job de CI dédié (construction + tests), séparé de `make check`.
4. Numéro de version dérivé d'une étiquette Git `android-vX.Y.Z` (nom de version) et d'un compteur (code de version) ; la version est visible dans l'application.
5. `android/README.md` : structure, commandes, règles. `CLAUDE.md` du front : renvoi vers ce dossier.
6. Supprimer du dépôt tout ce qui n'a plus de raison d'être **seulement** à la fin de M3b (DRV-312 à DRV-315 terminés) : le spike reste en place jusque-là.

## Hors portée

Le comportement (DRV-312 et suivants), la publication (DRV-315).

## Critères d'acceptation

- `make android` produit un APK de débogage installable ; `make android-test` est vert ; la CI exécute les deux.
- `make android-release` échoue clairement sans clé de signature et réussit avec une clé de test.
- Aucune clé, mot de passe ni secret dans le dépôt (sauf la clé de débogage publique `android` du spike si elle est reprise).

## Tests exigés

Tests Kotlin existants du spike repris (navigation, agent utilisateur) ; un test de la dérivation de version.

## Definition of Done

Celle du dépôt front ; PR concise sans pied d'attribution ; fusion si CI verte.

## Pièges connus

- Ne jamais committer le fichier de clé de publication (voir DRV-315).
- Le SDK Android est lourd : garder l'image Docker de construction en cache dans la CI.

## Réalisation (2026-10-17, PR repas-front#75)

`android/` : application « Repas Drive » (`eu.kantin.repas.drive`, minSdk 26, `targetSdk` 36), écran de version seulement. Cibles `make android`, `make android-test`, `make android-release` (exige `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`, `ANDROID_VERSION_TAG`, `ANDROID_VERSION_CODE` ; produit `android/dist/repas-drive.apk`), `make android-release-selftest`. Workflow `.github/workflows/android.yml`. Un test garde l'absence de clé dans le dépôt.
