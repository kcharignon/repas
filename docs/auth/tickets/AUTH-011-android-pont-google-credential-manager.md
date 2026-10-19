# AUTH-011 — Application Android : pont `google.signIn` et Credential Manager

- **Dépôt** : front (`android/`)
- **Jalon** : M3 Application Android
- **Taille** : M
- **Dépend de** : AUTH-015 (tronc commun Kotlin : le pont est écrit derrière l'interface `IdentityProvider`) ; le contrat des messages est écrit ici ; AUTH-002 pour l'essai réel (AUTH-013)
- **Faisable sans téléphone** : en grande partie (validation des messages en Kotlin pur, fournisseur factice dans l'émulateur de test) ; le vrai Credential Manager n'est prouvé qu'à la recette
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 2, 5.5 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décision 3 ; skill `repas-android-dev` ; `android/README.md` (« Le pont natif `repasNative` ») ; `NativeBridge.kt`, `MainActivity.kt` ; [guide Google](https://developer.android.com/identity/sign-in/credential-manager-siwg-implementation) (2026-10-16).

## Contexte

Google refuse la connexion dans une WebView. L'application obtient donc elle-même le jeton d'identité par Credential Manager (feuille native de choix du compte) et le remet à la page de Repas par le pont `repasNative`. La page le poste au back (AUTH-012). L'application n'ouvre aucune page de Google, ne garde pas le jeton, n'a aucun secret.

## Portée

1. **Dépendances** (`app/build.gradle.kts`) : `androidx.credentials:credentials:1.6.0`, `androidx.credentials:credentials-play-services-auth:1.6.0` (stables ; la documentation cite `1.7.0-alpha03`, préversion à éviter), `com.google.android.libraries.identity.googleid:googleid:1.2.1` (versions vérifiées sur le dépôt Maven de Google le 2026-10-19). Aucune permission nouvelle dans le manifeste.
2. **Contrat** (à copier dans `android/README.md`) :
   - page vers application : `{"v":1,"type":"google.signIn","clientId":"<ID client Web>","nonce":"<nonce serveur>"}` ;
   - application vers page : `{"v":1,"type":"google.result","idToken":"<jeton>"}` ou `{"v":1,"type":"google.error","code":"CANCELLED|NO_ACCOUNT|UNAVAILABLE|BUSY|INVALID_REQUEST|FAILED"}` ;
   - `app.info` gagne `"google": true` (drapeau de capacité).
3. `NativeBridge.kt` : nouveau `NativeRequest.GoogleSignIn(clientId, nonce)` ; **validation** : `clientId` de la forme `^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$`, `nonce` de 16 à 128 caractères `[A-Za-z0-9_-]`, rien d'autre lu ; messages `googleResult`, `googleError`, `appInfo(..., google)` ; limites, origine de Repas et page principale inchangées.
4. `GoogleSignIn.kt` : interface `IdentityProvider` du tronc commun (`signIn(clientId, nonce, callback): Cancellation`, AUTH-015), implémentation réelle `CredentialManagerGoogleIdentity` (Jetpack, `GetSignInWithGoogleOption.Builder(clientId).setNonce(nonce)`, version à rappel pour ne pas introduire de coroutines côté application) ; conversion des exceptions : annulation → `CANCELLED`, `NoCredentialException` → `NO_ACCOUNT`, fournisseur absent ou non pris en charge (`GetCredentialProviderConfigurationException`, `GetCredentialUnsupportedException`) → `UNAVAILABLE`, `GoogleIdTokenParsingException` et le reste → `FAILED`. Annulation de la demande à la destruction de l'activité.
5. `MainActivity.kt` : traite la demande, **une seule à la fois** (`BUSY`), répond sur le canal de la page (`JavaScriptReplyProxy`), aucune entrée de journal contenant le jeton, le nonce ou l'ID client.
6. **Variante de débogage seulement** : un `IdentityProvider` factice (jeton fixe d'essai, jamais accepté par un vrai back) choisi par `BuildConfig.DEBUG`, pour que `make android-smoke` et `make android-e2e` jouent le scénario avec le faux Repas (`android/emulator/`). Il n'existe pas dans l'APK de publication (le contrôle `android-release-verify` échoue s'il y est).
7. `android/PUBLICATION.md` : une phrase à l'étape 2 : la ligne `SHA1:` (publique, comme la SHA-256) sert aussi à la console Google (AUTH-002).
8. Mesure : **taille de l'APK avant et après** dans la description de la PR (l'APK n'est pas minifié ; ne pas activer R8 dans ce ticket).

## Hors portée

Le bouton et le texte côté page (AUTH-012) ; la publication de l'application (étiquette `android-vX.Y.Z`, décision humaine) ; `AuthorizationClient` ; le flux « bouton du bas » (`GetGoogleIdOption`) ; toute connexion Google dans `DriveActivity`.

## Critères d'acceptation

- Étant donné un message `google.signIn` valide de la page de Repas, quand le fournisseur factice réussit, alors la page reçoit `google.result` avec l'`idToken`.
- Étant donné un `clientId` ou un `nonce` mal formé, alors `google.error` `INVALID_REQUEST`, aucun appel au fournisseur.
- Étant donné une deuxième demande pendant la première, alors `BUSY`.
- Étant donné l'annulation par l'utilisateur, alors `CANCELLED` ; aucune relance automatique.
- Étant donné un message venant d'une autre origine ou d'un cadre secondaire, alors ignoré comme tout message du pont.
- Étant donné `app.info`, alors la réponse contient `"google": true` et les trois champs existants, sans autre changement (un ancien front ignore le champ en plus).
- L'APK de publication n'embarque pas le fournisseur factice ; `android-release-verify` reste vert (une seule permission, non débogable, agent intact, empreinte de l'agent inchangée).

## Tests exigés

Kotlin pur : `NativeBridgeTest` (validation du `clientId` et du `nonce`, tailles, types, réponses JSON, `app.info`), traduction des exceptions en codes (en testant la fonction pure de conversion), `BUSY` ; émulateur (`make android-smoke`, `make android-e2e`) avec le faux fournisseur : le faux Repas envoie `google.signIn` et relève la réponse ; `make android-test`, `make android-release-selftest`. **Pas d'essai avec un vrai compte Google ici.**

## Definition of Done

Celle du dépôt front (`make check`, `make android-test`) ; `android/README.md` à jour ; la taille avant/après et la liste des nouvelles dépendances dans « À relire » ; l'agent n'a pas changé (pas de nouvelle empreinte) ; **aucune étiquette** `android-v…` créée par l'agent.

## Pièges connus

- `MainActivity` est une `android.app.Activity`, pas une `ComponentActivity` : la version à rappel de `getCredentialAsync` prend un `Context` d'activité, un exécuteur et un signal d'annulation ; confirmer la signature exacte dans la version 1.6.0.
- La feuille de Credential Manager s'affiche **par-dessus** la coquille ; `configChanges` empêche la recréation de l'activité pendant la demande (à conserver).
- Utiliser `GetSignInWithGoogleOption` (geste de l'utilisateur) et non `GetGoogleIdOption` : la feuille du bas dépend d'un réglage du compte Google et a eu une `TransactionTooLargeException` avec plusieurs comptes (corrigée dans les services Play 24.40).
- L'ID client à passer est celui du client **Web** (`serverClientId`), pas celui du client Android.
- **L'empreinte de la clé de débogage ne doit jamais être enregistrée dans le projet Google de production** : les APK de débogage n'ont donc pas de connexion Google réelle (fournisseur factice ; un projet « Repas essai » séparé si on veut un jour l'essayer).
- Ne jamais journaliser le jeton ; ne pas le laisser dans `DrivePass` ni dans les préférences de l'application.
- Un téléphone sans services Google Play répondra `UNAVAILABLE` : la page doit alors laisser le formulaire (AUTH-012).
- Android 14+ passe par le Credential Manager du système ; avant, par les services Play : tester sur les deux si possible à la recette.
- La WebView de Repas n'a pas de `addJavascriptInterface` et n'en aura pas : rester sur `addWebMessageListener`.
- Ordre de publication (rappel) : site (qui connaît `google.*`) d'abord, application ensuite. Un ancien front ignore les types inconnus ; un front neuf devant une ancienne application ne voit pas `google` dans `app.info` et masque le bouton.
