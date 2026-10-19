# STORE-006 — Android App Bundle signé de la clé d'envoi, contrôlé, dans le workflow

- **Dépôt** : front (`android/build.sh`, `android/app/build.gradle.kts`, `android/Dockerfile`, `Makefile`, `.github/workflows/android-release.yml`, `android/__tests__/publication.spec.ts`, `android/README.md`, `android/PUBLICATION.md`, `android/upload-cert.sha256`)
- **Jalon** : M1 Clé de signature et construction
- **Taille** : M
- **Dépend de** : — (le code se fait avec des clés jetables ; les vrais secrets viennent de STORE-004 et doivent être posés **avant la fusion**)
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non (mais STORE-016 en dépend)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 4.4 et 5 ; skill `repas-android-dev` ; `android/README.md` (« La publication signée ») ; `android/PUBLICATION.md` ; `.github/workflows/android-release.yml` ; `android/build.sh` (`verify_release`).

## Contexte

Google Play exige un AAB pour une nouvelle application. Le workflow produit aujourd'hui l'APK de la release GitHub. La même étiquette `android-vX.Y.Z` doit produire **aussi** un AAB, signé d'une **clé d'envoi** distincte de la clé de publication, et contrôlé aussi sévèrement que l'APK. L'APK, la release et ses règles (déclencheur unique, commit dans `main`, étiquette la plus récente, secrets seulement dans des blocs `env`) ne changent pas.

## Portée

1. `app/build.gradle.kts` : un profil de signature `upload` choisi par `-PsigningProfile=upload` (variables `ANDROID_UPLOAD_KEYSTORE_FILE`, `…_PASSWORD`, `ANDROID_UPLOAD_KEY_ALIAS`, `…_KEY_PASSWORD`, lues dans l'environnement comme les autres), utilisé par `:app:bundleRelease` ; `verifyReleaseInputs` refuse sans ces variables. Désactiver le découpage par langue (`bundle { language { enableSplit = false } }`).
2. `build.sh` : modes `bundle` (décode la clé d'envoi dans un fichier temporaire effacé en sortie, produit `dist/repas-drive.aab` avec taille et SHA-256), `verify-bundle` (voir Spécification), et `release-selftest` étendu (clé d'envoi jetable).
3. `Makefile` : `android-bundle`, `android-bundle-verify` (variables `ANDROID_UPLOAD_*`, `ANDROID_VERSION_TAG`, `ANDROID_EXPECTED_UPLOAD_CERT_SHA256`).
4. `Dockerfile` : `bundletool` en version et empreinte SHA-256 **épinglées** (comme Gradle).
5. `android/upload-cert.sha256` : l'empreinte SHA-256 **publique** du certificat d'envoi (valeur donnée par le propriétaire, STORE-004) ; le workflow la lit pour `ANDROID_EXPECTED_UPLOAD_CERT_SHA256`.
6. Workflow `android-release.yml`, job `build`, après « Contrôle de l'APK » : étape « AAB signé » (secrets d'envoi dans son seul bloc `env`), étape « Contrôle de l'AAB », ajout de `AAB_SHA256` et `UPLOAD_CERT_SHA256` à `release-facts.env` et au résumé ; artefact `android-bundle` (`repas-drive.aab` + `.sha256`, 30 jours) ; le job `publish` et le contenu de la release **ne changent pas**. Message d'erreur dédié si un secret d'envoi manque (« STORE-004 »).
7. Documentation : `android/README.md` (commandes, secrets, artefact) et `PUBLICATION.md` (section « Google Play » : comment télécharger l'AAB avec `gh run download`, quand l'envoyer).

## Spécification du contrôle de l'AAB (`verify-bundle`)

L'AAB doit satisfaire, dans cet ordre, avec un message précis à chaque refus : `bundletool validate` ; manifeste (`bundletool dump manifest`) : paquet `eu.kantin.repas.drive`, `versionCode` et `versionName` conformes à l'étiquette (recalculés indépendamment du Kotlin), `minSdkVersion` 26, `targetSdkVersion` 36, **une seule permission** (`INTERNET`), non débogable ; signature (`keytool -printcert -jarfile` ou `jarsigner -verify -certs`) : un seul signataire dont le SHA-256 est celui de `android/upload-cert.sha256` et **jamais** la clé de débogage ni la clé de publication ; contenu : `base/assets/agent.js` d'empreinte SHA-384 égale à `android/agent.js.sha384`, `base/assets/injection.js` présent, aucun `debug-plan.json`, **aucun dossier `lib/`** (pas de code natif : exigences 16 Ko et 64 bits sans objet), `BuildConfig.DEBUG` faux, origine de production.

## Hors portée

L'envoi dans la console (manuel, STORE-016) ; l'envoi automatique (STORE-021) ; l'ajout de l'AAB à la release publique ; R8 / réduction du code ; toute étiquette `android-v…` créée par l'agent.

## Critères d'acceptation

- Étant donné une étiquette valide et les quatre secrets d'envoi, quand le workflow tourne, alors il publie la release **comme avant** (APK, `.sha256`, `version.json`) et dépose l'artefact `android-bundle` contenant un `.aab` dont la signature est celle de la clé d'envoi.
- Étant donné un secret d'envoi manquant, alors le job échoue **avant** de construire, avec un message qui cite STORE-004 et aucune valeur.
- Étant donné un AAB signé d'une autre clé (jetable, publication, débogage), alors `verify-bundle` échoue ; de même pour un `versionCode` qui n'est pas celui de l'étiquette, une seconde permission, un dossier `lib/`, un agent altéré.
- Étant donné `make android-release-selftest`, alors il prouve le refus sans clé d'envoi, la réussite avec une clé jetable et chacun des refus ci-dessus.
- Étant donné l'APK, alors son contenu, sa signature et ses contrôles sont inchangés.

## Tests exigés

`make android-release-selftest` étendu ; `android/__tests__/publication.spec.ts` : déclencheur unique inchangé, droits inchangés, les nouveaux secrets n'apparaissent que dans des blocs `env`, aucun `echo` d'une valeur, l'artefact `android-bundle` est déclaré, `release` n'embarque pas l'AAB ; `make android-test` ; `make check`.

## Definition of Done

Celle du dépôt front ; description de PR : ce qui est ajouté (aucune étiquette créée), taille de l'AAB, comparaison de taille avec l'APK ; **les quatre secrets `ANDROID_UPLOAD_*` sont posés** (STORE-004) avant la fusion ; l'assistant coche la roadmap du back après la fusion.

## Pièges connus

- **Fusionner avant d'avoir posé les secrets** : la prochaine étiquette échouerait (refus voulu, mais surprenant).
- `apksigner` ne vérifie pas un AAB : utiliser `jarsigner`/`keytool` ; un AAB n'a pas de signature v2/v3.
- AGP signe l'AAB avec le `signingConfig` de la variante : d'où le profil `upload` ; ne pas dupliquer la variante `release`.
- Ne pas mettre la clé d'envoi dans `signingConfigs` du dépôt ; elle vient de l'environnement, comme l'autre.
- `bundletool` a besoin d'un JDK : il est dans l'image.
- Le **découpage par langue** livrerait des ressources différentes selon la langue du téléphone alors que l'application n'a que le français : le désactiver.
- Ne pas attacher l'AAB à la release GitHub : la page d'aide dit aux proches de prendre le `.apk`, un second fichier les troublerait.
- Un code de version déjà envoyé à Google Play ne peut plus l'être : jamais d'étiquette réutilisée.
