# DRV-315 — Publication signée et mises à jour de l'application

- **Dépôt** : front (`kcharignon/repas-front`) ; une étape **humaine** (clé de signature)
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-311
- **Statut** : à faire
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), [`../spikes/DRV-005-decision-g1.md`](../spikes/DRV-005-decision-g1.md), [`../spikes/DRV-011-rapport.md`](../spikes/DRV-011-rapport.md), `drive-agent/android-lab/` (le spike, `PROCEDURE.md` compris), `CLAUDE.md` du front, [`../analyse.md`](../analyse.md) (D27)

## Contexte

L'application est distribuée **hors Google Play** : un fichier APK signé, publié en release GitHub, installé à la main par l'utilisateur et ses proches (D27). Les mises à jour se font en installant le nouvel APK par-dessus ; l'application signale qu'une nouvelle version existe.

## Portée

1. **Étape humaine** (documentée dans `android/README.md`, en français, pas à pas) : l'utilisateur génère lui-même une clé de signature (`keytool`, dans Docker), la garde hors du dépôt (gestionnaire de mots de passe et sauvegarde), et la dépose en secrets du dépôt GitHub (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`). L'assistant et les agents ne voient jamais ces valeurs.
2. Workflow GitHub Actions déclenché par une étiquette `android-vX.Y.Z` : construit l'APK signé (`make android-release`), calcule son SHA-256, crée la release avec `repas-drive.apk` et `repas-drive.apk.sha256`, et un fichier `version.json` (`{versionName, versionCode, apkUrl, sha256, minAndroid}`).
3. Dans l'application : une requête, sur demande de l'utilisateur et au plus une fois par jour au lancement, vers l'API publique des releases du dépôt (sans identifiant, sans donnée personnelle) ; si une version plus récente existe, l'application l'indique (« Une nouvelle version est disponible ») et ouvre le lien de téléchargement dans le navigateur ; elle n'installe rien elle-même.
4. Vérification manuelle documentée : comparer le SHA-256 du fichier téléchargé avec celui de la release.
5. Piste ultérieure (backlog) : mise à jour de `agent.js` signée sans réinstaller l'application.

## Hors portée

Google Play. Mise à jour silencieuse.

## Critères d'acceptation

- Étant donné une étiquette `android-v0.1.0`, quand le workflow tourne avec une clé de test, alors la release contient l'APK, son empreinte et `version.json`.
- Étant donné une release plus récente, quand l'application la détecte, alors elle l'indique sans rien installer.
- Aucun secret dans le dépôt ni dans les journaux de la CI.

## Tests exigés

Tests Kotlin de la comparaison de versions et de l'analyse de `version.json` ; le workflow est essayé avec une clé de test jetable.

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si CI verte. L'étape humaine est cochée par l'utilisateur dans l'issue.

## Pièges connus

- Perdre la clé de signature empêche les mises à jour par-dessus : sauvegarde obligatoire (documenter).
- Android peut exiger l'enregistrement du développeur pour les applications installées hors Play (à vérifier au moment de la livraison, [`../pivot-application-android.md`](../pivot-application-android.md) inconnue 6).

## Déjà fait par DRV-311 (2026-10-17)

`make android-release` attend déjà les quatre variables de clé (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`) plus `ANDROID_VERSION_TAG` et `ANDROID_VERSION_CODE`, et produit `android/dist/repas-drive.apk`. Reste : le workflow par étiquette `android-vX.Y.Z`, la release (APK, empreinte, `version.json`), la vérification de version dans l'application, et la partie humaine (création de la clé, secrets GitHub).
