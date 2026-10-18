# DRV-315 — Publication signée et mises à jour de l'application

- **Dépôt** : front (`kcharignon/repas-front`) ; une étape **humaine** (clé de signature)
- **Jalon** : M3b Application Android
- **Taille** : M
- **Dépend de** : DRV-311
- **Statut** : workflow, contrôle de l'APK et procédure livrés (PR repas-front#122, 2026-10-18) ; reste la vérification « nouvelle version disponible » dans l'application et les étapes humaines (clé, secrets, `assetlinks.json`, étiquette)
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

## Réalisation (2026-10-18, PR repas-front#122)

- Workflow `.github/workflows/android-release.yml`, déclenché **uniquement** par le push d'une étiquette `android-vX.Y.Z` (ni PR, ni `main`, ni à la main, aucune variante qui publie sans clé). Le travail `build` (lecture seule) est le seul à voir les 4 secrets ; il refuse d'emblée si l'un manque (message qui renvoie vers `android/PUBLICATION.md`, jamais de valeur). Il vérifie l'étiquette (forme, commit dans `main`, plus récente que les précédentes, pas de release existante), l'empreinte de l'agent, les tests Kotlin, puis construit et contrôle l'APK ; le travail `publish` (écriture, sans secret) crée une release ordinaire (ni brouillon ni pré-version) et relit l'APK publié.
- La release contient `repas-drive.apk`, `repas-drive.apk.sha256` et `version.json` ; les notes donnent le SHA-256 de l'APK, la version et le SHA-384 de l'agent, l'empreinte du certificat et la procédure d'installation en une ligne.
- Contrôle de l'APK (`make android-release-verify`) : certificat dans la liste attendue et jamais la clé de débogage, version et code conformes à l'étiquette, minSdk 26 / targetSdk 36, une seule permission (Internet), non débogable, `BuildConfig.DEBUG` faux, pas de plan d'essai, agent embarqué égal à `agent.js.sha384` ; l'empreinte attendue est lue dans `public/.well-known/assetlinks.json` (le workflow refuse de publier tant que ce fichier manque).
- Code de version tiré de l'étiquette (`X*1 000 000 + Y*1 000 + Z` : `android-v0.1.0` donne 1000).
- Essai local avec une clé jetable (hors dépôt, supprimée) : build, contrôle, installation dans l'émulateur, démarrage sans plantage, `make android-smoke` vert. `assetlinks.json` essayé sur l'image de production : 200 `application/json` sans redirection ; aucun changement de `deploy/`.
- Avertissement « ne pas installer sur le téléphone des courses » ajouté aux notes des pré-versions `android-dev-` et `android-lab-` (clé publique de débogage) ; `release.yml` ignore toute release `android-…`.
- Le job rouge sur `main` depuis #120 (essai de publication sans clé) est corrigé : l'image embarque `platform-tools`, l'essai prépare Gradle (3 essais) et affiche la fin de la sortie de Gradle s'il échoue.

### Étapes humaines, dans l'ordre (détail : `android/PUBLICATION.md` du front)

1. Créer la clé de publication (keytool dans Docker, un seul mot de passe, deux copies hors du PC, mot de passe dans un gestionnaire).
2. Relever l'empreinte SHA-256 publique du certificat et la donner à l'assistant.
3. Poser les 4 secrets GitHub du dépôt front : `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`.
4. L'assistant ouvre la PR front qui crée `public/.well-known/assetlinks.json` (empreinte de l'étape 2) ; l'humain publie une version du site (approbation du déploiement) ; vérifier `curl -sSI https://kantin.eu/.well-known/assetlinks.json` (200, `application/json`, sans `location:`).
5. Pousser l'étiquette `android-v0.1.0` (jamais de numéro qui baisse), suivre `gh run watch`, vérifier `sha256sum -c repas-drive.apk.sha256`.
6. Désinstaller toute pré-version (autre clé), installer l'APK signé, puis essayer une mise à jour par-dessus.

Reste du ticket : la vérification « nouvelle version disponible » dans l'application (`version.json` est déjà publié pour elle).
