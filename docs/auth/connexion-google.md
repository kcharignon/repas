# Connexion avec Google : analyse de cadrage

Cadrage du 2026-10-19 (agent de cadrage : recherche, architecture, tickets ; **aucun code produit**). Décision d'architecture associée : [ADR 0024](../adr/0024-connexion-avec-google.md) (`Proposed`). Tickets et ordre : [`roadmap.md`](roadmap.md), [`tickets/`](tickets/).

Toutes les sources officielles ont été consultées le **2026-10-19** ; la date entre parenthèses est celle que la page affiche (« Last updated »). Les citations de pages en anglais sont **traduites** ici. Ce qui est **vérifié** (source officielle datée, lecture du code, essai jetable) est séparé de ce qui est **supposé** (§ 10).

## 0. En bref

**Objectif.** Un proche touche « Se connecter avec Google », choisit son compte, et c'est fini : ni mot de passe à créer, ni mot de passe à retenir, ni lien à aller chercher dans sa messagerie.

**Recommandation en dix lignes.**

1. **Web** : Google Identity Services (GIS), le bouton rendu par Google en mode fenêtre (`popup`). La page reçoit un *jeton d'identité* signé par Google et le poste au back.
2. **Application Android** : Credential Manager (`GetSignInWithGoogleOption`), natif. L'application récupère le jeton d'identité et le remet à la page par le pont `repasNative` ; la page le poste au back comme sur le web. **Aucune connexion Google ne passe par la WebView.**
3. **Back** : `POST /api/auth/google` (public, à côté de `/api/auth/refresh`) vérifie le jeton, puis répond comme la connexion par mot de passe (JWT + cookie de rafraîchissement). Un `POST /api/auth/google/nonce` fournit un nonce à usage unique.
4. **Bibliothèque** : `lcobucci/jwt` 5.6.1, **déjà installée** (dépendance de Lexik, présente dans l'image de production) : zéro paquet nouveau. Les clés publiques de Google sont lues et mises en cache par un petit adaptateur maison (~100 lignes). Essai jetable concluant dans l'image de production (§ 3.2).
5. **Identité** : table `user_identity(provider, subject)`, clé unique, `subject` = le `sub` de Google (jamais l'e-mail). Migration additive, purge à la suppression du compte, test de couverture étendu.
6. **Compte** : première connexion = création d'un compte `ACTIVE` (même niveau de preuve que l'inscription par e-mail confirmé). Adresse déjà inscrite : **liaison automatique seulement si l'adresse est en `@gmail.com`** (Google en fait foi, c'est lui qui le dit) ; sinon refus poli et mot de passe. Compte désactivé : refus, sans rien écrire.
7. **Mot de passe** : un compte créé par Google n'a pas de mot de passe (valeur verrouillée `!`). Il pourra en poser un par « mot de passe oublié » (issue `repas-front#31`) ou par le ticket facultatif AUTH-014.
8. **Aucun secret** : pas de *client secret* (on ne fait pas le flux par code). L'ID client est public. L'empreinte SHA-1 de la clé de l'application est publique ; la clé et ses mots de passe, jamais.
9. **Interrupteur** : `GOOGLE_CLIENT_ID` vide = fonction coupée (`404 GOOGLE_DISABLED`, bouton caché). Allumage par l'humain, comme `DRIVE_ENABLED`.
10. **14 tickets** (`AUTH-001` à `AUTH-014`, dont un facultatif). Les tickets 001 à 012 et 014 se font et se testent sans téléphone (leurs tests n'appellent jamais Google) ; seule la recette (AUTH-013) exige un téléphone réel et un compte Google d'essai, et c'est elle qui prouve les parties Android.

**Décisions que le propriétaire doit prendre** (détail § 4.4) : D1 création de compte à la première connexion (et son statut) ; D2 liaison par e-mail (Gmail seulement, ou plus large) ; D3 mot de passe facultatif (AUTH-014 maintenant ou après `#31`) ; D4 vérification de marque Google et page « Conditions d'utilisation » publique ; D5 iPhone (Safari) hors du premier lot.

**Ce qui contredit ou nuance les hypothèses du cadrage** (tous vérifiés dans les sources, § 10) :

- *« Lier par e-mail vérifié par Google »* ne suffit pas. Google écrit lui-même que son `email_verified` ne fait foi que pour une adresse **`@gmail.com`** (ou un domaine Google Workspace, `hd`) ; pour toute autre adresse, il recommande « un mot de passe ou une autre vérification ». Et le cas d'un nom de domaine racheté (Truffle Security, janvier 2025) montre que même `hd` n'est pas une garantie (§ 4.3).
- *« L'empreinte SHA-1 est à ne jamais mettre dans le chat ni le dépôt »* : elle est **publique** par nature (elle se lit dans n'importe quel APK, comme l'empreinte SHA-256 déjà publiée dans `assetlinks.json`). Les secrets sont la clé de signature et ses mots de passe. On garde la prudence du dépôt (l'utilisateur la tape lui-même dans la console), mais ce n'est pas un secret.
- *« Vérification Google éventuellement requise pour `openid email profile` »* : **non obligatoire** pour des portées non sensibles. Seule la *vérification de marque* est nécessaire pour que le nom « Repas » (et un logo) s'affiche dans la fenêtre Google ; sans elle, l'application fonctionne (§ 6).
- *Le `nonce`* n'est pas dans la liste de contrôles de la page de vérification de Google (elle ne le mentionne pas) ; la documentation Android, elle, demande de comparer le nonce de la requête et celui de la réponse. C'est un durcissement que nous ajoutons (nonce serveur à usage unique).
- *La politique sur les WebViews* est plus large que l'erreur `disallowed_useragent` : la page GIS des navigateurs pris en charge dit « les WebViews Android et iOS ne sont pas prises en charge pour la connexion » (§ 2). L'hypothèse du cadrage est confirmée, et même renforcée.

## 1. Ce qui existe aujourd'hui (lu dans le code, 2026-10-19)

| Sujet | Fait | Où |
|---|---|---|
| Seule connexion | e-mail + mot de passe : `POST /api/login_check` (firewall `login`), JWT 1 h | [ADR 0008](../adr/0008-auth-session-et-jwt.md), `config/packages/security.yaml` |
| Session du SPA | refresh token aléatoire de 256 bits, haché, rotation, cookie `repas_refresh` (`HttpOnly`, `Secure`, `SameSite=Strict`, `Path=/api/auth`), 30 jours, jusqu'à 10 « familles » | [ADR 0015](../adr/0015-refresh-token-du-spa.md), `RefreshTokenCookie`, `IssueRefreshTokenOnLogin` |
| Routes `/api/auth/*` | firewall `auth` en `security: false` : ni `access_control`, ni `UserChecker` (le handler contrôle lui-même le statut) | `security.yaml`, `RotateRefreshTokenHandler` |
| Inscription | publique, `PENDING`, lien de confirmation 24 h, puis `ACTIVE` ; adresse déjà prise = `409` | [ADR 0020](../adr/0020-confirmation-d-adresse-email.md), `RegisterNewUserHandler` |
| Statuts | `PENDING` / `ACTIVE` / `DISABLED` ; un compte non actif ne peut plus rien ; compte protégé `User::PROTECTED_EMAIL` intouchable ; suppression = purge de tout | [ADR 0021](../adr/0021-gestion-des-comptes-par-l-administrateur.md) |
| Révocation | JWT lié à `credentials_version` (`cv`) : changer le mot de passe coupe tout | `JwtCredentialsVersionListener`, `User::changePassword` |
| Mot de passe | colonne `user.password` non nulle ; changement exige le mot de passe actuel (`UpdateUserSettingsHandler`) | `src/User/Application/UpdateUserSettings/` |
| JWT | `lcobucci/jwt` **5.6.1** installé (exigé par `lexik/jwt-authentication-bundle` 3.2) ; `symfony/http-client`, `symfony/cache`, `ext-openssl`, `ext-sodium` présents ; **aucune** bibliothèque Google, Guzzle ni `firebase/php-jwt` | `composer.lock`, image de production |
| Aucune clé étrangère | les tables désignent l'utilisateur par une colonne texte ; `UserOwnedDataCoverageTest` échoue si une entité gagne une colonne `owner|author|creator|user` non purgée | ADR 0021 règle 10 |
| Front | `LoginView.vue`, `RegisterView.vue`, `session.ts` (jeton en mémoire, refresh par cookie), routes `/connexion`, `/inscription` ; une seule page de conditions : `/drive/conditions` (« Conditions d'utilisation du drive », derrière la connexion) | `repas-front` |
| CSP de production | `default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` : **aucun site externe** | `repas-front/docker/Caddyfile` (la bordure du back ne pose aucune CSP) |
| Application Android | `MainActivity` (`android.app.Activity`, pas `ComponentActivity`) charge `https://kantin.eu/` dans une WebView ; pont `window.repasNative` (`addWebMessageListener`, origine de Repas seulement, pas de `addJavascriptInterface`) ; messages `app.info`, `app.menu`, `drive.*` ; `minSdk` 26, `targetSdk` 36 ; une seule bibliothèque (`androidx.webkit`), pas de minification, une seule permission (Internet) ; paquet `eu.kantin.repas.drive` ; liens `https://kantin.eu/…` vérifiés (`autoVerify`, `assetlinks.json` publié) | `repas-front/android/` |
| Distribution de l'application | APK signé de la clé de l'utilisateur, **hors Google Play**, installé à la main ; la clé de débogage est publique et ne signe jamais une publication | `android/PUBLICATION.md` |
| Service worker | `navigateFallback` limité aux navigations ; aucun cache sur les requêtes externes | `vite.config.ts` |

**Aucune connexion Google n'existe** : vérifié par `git grep` le 2026-10-19 (aucune référence à Google Sign-In, GIS, OAuth ou OpenID dans `src/`, `config/` (hors `config/reference.php`, fichier généré par Symfony), `deploy/` et `composer.json` du back, ni dans `src/`, `android/`, `drive-agent/`, `docker/` et `public/` du front).

## 2. WebView : politique actuelle et voies possibles (question 1)

### 2.1 Ce que dit Google (vérifié)

- **Politique OAuth « navigateurs sécurisés »** ([page des règles](https://developers.google.com/identity/protocols/oauth2/policies), modifiée le 2026-08-05) : « un développeur ne doit pas diriger une demande d'autorisation OAuth 2.0 de Google vers un agent utilisateur intégré (*embedded user-agent*) » sous son contrôle ; tout environnement doit laisser l'utilisateur vérifier la connexion au serveur OAuth de Google. L'annonce d'origine (2021, application dès le 2021-09-30, paramètre d'essai `disallow_webview=true`) décrit l'erreur `disallowed_useragent` : [billet](https://developers.googleblog.com/en/upcoming-security-changes-to-googles-oauth-20-authorization-endpoint-in-embedded-webviews/) (lu par résultat de recherche, non relu en entier). La page « OAuth 2.0 pour applications natives » ([2026-10-16](https://developers.google.com/identity/protocols/oauth2/native-app)) décrit la même erreur.
- **Google Identity Services, navigateurs pris en charge** ([page](https://developers.google.com/identity/gsi/web/guides/supported-browsers), 2026-09-01) : « Les WebViews Android et iOS ne sont pas prises en charge pour la connexion de l'utilisateur. Nous recommandons Chrome Custom Tabs sur Android et SFSafariViewController sur iOS. » Ajoute : sur iOS, à cause d'ITP, le mode `redirect` est obligatoire.
- **Android** : la voie documentée pour « Sign in with Google » dans une application est **Credential Manager** ([guide](https://developer.android.com/identity/sign-in/credential-manager-siwg-implementation), 2026-10-16). L'ancien « Google Sign-In » est déprécié ([guide de migration](https://developer.android.com/identity/sign-in/legacy-gsi-migration), lu par recherche) ; la date de retrait n'est pas confirmée.
- **Credential Manager dans une WebView** ([page](https://developer.android.com/identity/sign-in/credential-manager-webview), 2026-02-26) : réservé aux **passkeys** (WebAuthn, `androidx.webkit` 1.12.0 et plus), rien sur Google. Le pont « page → natif → Credential Manager » que nous proposons est donc un montage de notre côté, pas un schéma documenté par Google pour les applications hybrides (le natif, lui, est le schéma documenté ; seul le transport du jeton vers la page est de nous).

### 2.2 Les voies

| Voie | Principe | Verdict |
|---|---|---|
| (a) **Credential Manager natif + pont** | L'application obtient un jeton d'identité par `GetSignInWithGoogleOption`, le remet à la page par `repasNative` ; la page le poste au back | **Recommandée pour l'application.** Voie documentée par Google, une feuille de choix de compte native (1 à 2 touches), aucun navigateur, aucune WebView concernée |
| (b) **Chrome Custom Tab + lien d'application** | Ouvrir une page de connexion dans Chrome (où GIS marche), revenir par un lien `https://kantin.eu/...` capturé par l'application (`assetlinks.json` existe) | **Écartée.** Les cookies sont ceux de Chrome, pas ceux de la WebView : il faudrait inventer un *code de passage à usage unique* (nouvelle table, nouvelle route, ou rejouer le jeton Google par l'URL). Plus de code, plus de surface, une expérience moins fluide (changement d'application). Les schémas d'URI personnalisés ne sont plus pris en charge sur Android ([page natives](https://developers.google.com/identity/protocols/oauth2/native-app)) |
| (c) **GIS dans la page, dans la WebView** | Rendre le bouton Google dans `kantin.eu` chargé par la WebView | **Impossible** : « WebViews non prises en charge » (§ 2.1). On l'évite : dans l'application, le script GIS n'est même pas chargé |
| (d) **Flux par code (OAuth « serveur »)** | Redirection vers `accounts.google.com`, retour sur le back, échange du code avec un *client secret* | **Écartée.** Demande un secret côté serveur (variable à poser, à faire tourner), `state`, PKCE, une route de retour, et ne règle pas la WebView |
| (e) **Mode `redirect` de GIS** | Google POSTe le jeton sur une route du back (`login_uri`) | **Hors premier lot.** Utile seulement pour Safari/iOS (§ 2.3) ; demande une route de plus, le jeton `g_csrf_token` à comparer et une redirection vers le SPA |

### 2.3 Une voie pour le web, une voie pour l'application

- **Web** : GIS, bouton rendu par Google, `ux_mode: 'popup'`, rappel JavaScript qui reçoit le jeton (`credential`). Le script ne se charge que sur les écrans de connexion et d'inscription, et seulement si la fonction est allumée. FedCM pour le bouton est **facultatif** ([guide](https://developers.google.com/identity/gsi/web/guides/fedcm-migration), 2026-02-10) : on commence sans, puis on teste. **iPhone/Safari** : Google exige `redirect` (§ 2.1) : décision D5, hors premier lot (Safari garde le mot de passe).
- **Application** : Credential Manager avec `GetSignInWithGoogleOption` (flux « bouton », déclenché par une touche de l'utilisateur) plutôt que `GetGoogleIdOption` (feuille du bas) : la documentation de dépannage ([2024-01-02](https://developer.android.com/identity/sign-in/credential-manager-troubleshooting-guide)) signale que la feuille du bas dépend du réglage « invites de connexion » du compte Google, et qu'elle a eu une `TransactionTooLargeException` avec plusieurs comptes sur Android 14+ (corrigée dans les services Google Play 24.40) ; le flux « bouton » n'est pas touché.

### 2.4 Compromis pour l'application

| Point | Détail |
|---|---|
| Dépendance | Services Google Play sur le téléphone : l'API Jetpack passe par eux avant Android 14. Un téléphone sans services Google (Huawei récent, ROM dégooglisée) n'aura pas le bouton ; il garde le mot de passe. La page [Sign in with Google](https://developer.android.com/identity/sign-in/credential-manager-siwg) indique Android 4.4 (API 19) et plus : l'application est en `minSdk` 26 |
| Bibliothèques | `androidx.credentials:credentials` et `credentials-play-services-auth` **1.6.0** (stable ; la documentation montre `1.7.0-alpha03`, préversion à éviter), `com.google.android.libraries.identity.googleid:googleid` **1.2.1** (vérifié sur le dépôt Maven de Google le 2026-10-19). La coquille n'a aujourd'hui qu'une seule bibliothèque (`androidx.webkit`) : elle en gagne trois |
| Taille | Non mesurée. L'APK n'est pas minifié (`isMinifyEnabled = false`) : le surcoût des services Play et des coroutines est à **mesurer** dans AUTH-011 (critère : taille avant/après notée dans la PR, décision de minifier laissée à l'humain) |
| Maintenance | Trois bibliothèques à suivre (Dependabot du dépôt front) ; l'API Credential Manager évolue vite (préversions 1.7) |
| Expérience d'un proche | La feuille native propose les comptes déjà sur le téléphone : **une touche sur le bouton, une sur le compte** |
| Distribution hors Play | Credential Manager vérifie le paquet et l'empreinte du certificat contre le **client Android** déclaré dans la console. Aucune source Google trouvée ne dit que la distribution hors Play change quelque chose : **supposé** que cela marche avec l'APK signé de l'utilisateur ; à prouver à la recette (AUTH-013) |
| Clé de débogage | Son empreinte est publique : **ne jamais l'enregistrer dans le projet Google de production** (n'importe qui pourrait signer une application au même paquet et obtenir, avec l'accord d'un utilisateur trompé, un jeton valide pour notre ID client). Pour les essais de débogage : un second projet Google « Repas essai » |

## 3. Côté back (question 2)

### 3.1 Ce que le back vérifie

Le jeton d'identité est un JWT signé RS256 par Google. Le back **refuse** (réponse unique `401`, sans dire pourquoi) sauf si **tout** ceci est vrai :

| Contrôle | Règle | Source |
|---|---|---|
| Signature | RS256 uniquement, clé choisie par `kid` dans les clés publiques de Google ; algorithme épinglé (un jeton `HS256` ou `none` est refusé) | [page de vérification](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token) (2025-12-22) ; essai § 3.2 |
| `iss` | `https://accounts.google.com` ou `accounts.google.com` | idem |
| `aud` | égal à **l'ID client Web** configuré (`GOOGLE_CLIENT_ID`), un seul. Avec Credential Manager, `aud` est le `serverClientId` donc l'ID client Web, pas l'ID client Android ; ne pas se fier à `azp` | [guide Android](https://developer.android.com/identity/sign-in/credential-manager-siwg-implementation) ; **supposé** pour `azp`, à relever à la recette |
| `exp` | **présent** et non dépassé (marge de 30 s) ; `iat` présent. `LooseValidAt` accepte un jeton sans `exp` : le contrôle de présence est explicite | essai § 3.2 |
| `sub` | présent, non vide ; c'est **la** clé d'identité (« unique parmi tous les comptes Google et jamais réutilisé ») | page de vérification |
| `email` et `email_verified` | `email` présent, `email_verified === true` (booléen strict ; une chaîne `"true"` est refusée) | idem |
| `nonce` | égal à un nonce que **le serveur a émis**, valable 5 minutes, **consommé** à l'usage (§ 3.5) | durcissement du projet ; Android : « le serveur valide que les nonces sont identiques » |
| `hd` | lu pour la politique de compte (§ 4), jamais requis | page de vérification |

Les clés publiques : `https://www.googleapis.com/oauth2/v1/certs` (certificats PEM) ou `https://www.googleapis.com/oauth2/v3/certs` (JWK, `jwks_uri` du document OpenID). Constaté le 2026-10-19 : mêmes deux `kid` dans les deux, `Cache-Control: public, max-age=18323` (v3) et `max-age=24560` (v1). Règles d'adaptateur : cache selon `max-age` borné entre 10 min et 24 h ; `kid` inconnu = un rechargement au plus toutes les 5 minutes ; Google injoignable avec un cache périmé de moins de 24 h = on s'en sert, sinon refus (échec fermé) ; délai d'attente de 3 s.

### 3.2 Quelle bibliothèque ? (comparaison, essai jetable)

| Option | Nouveaux paquets | Compatibilité (Packagist, 2026-10-19) | Avis |
|---|---|---|---|
| **A. `lcobucci/jwt` 5.6.1 + adaptateur maison des clés Google** | **0** (déjà exigée par Lexik, présente dans l'image) | PHP `~8.2 \|\| ~8.3 \|\| ~8.4 \|\| ~8.5`, 5.6.1 du 2026-10-10 | **Recommandée.** Signature et contrôles de revendications par la bibliothèque ; ~100 lignes à nous pour lire/mettre en cache les clés et fixer la politique. Dependabot et `make audit` la couvrent déjà. À déclarer explicitement dans `composer.json` (aujourd'hui transitive) |
| B. `firebase/php-jwt` 7.2.1 | 1 (aucune dépendance) | PHP `^8.0`, 2026-09-28 | Solide (`JWK::parseKeySet`, `CachedKeySet`) mais une **deuxième** bibliothèque JWT à suivre à côté de celle de Lexik, pour économiser ~30 lignes |
| C. `google/apiclient` 2.20.1 (recommandée par Google) | ~10 (`google/auth`, `google/apiclient-services`, `firebase/php-jwt`, `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `monolog/monolog`, …) | PHP `^8.1`, 2026-09-25 | Officielle mais lourde (services générés pour des centaines d'API, Guzzle et Monolog en plus du client HTTP Symfony) ; d'après la page de Google, `verifyIdToken()` contrôle signature, `aud`, `exp` et `iss`, **pas `nonce` ni `email_verified`** : il faudrait de toute façon notre code autour. ADR 0014 : surface de mises à jour hebdomadaires sans bénéfice |
| D. `google/auth` 1.55.2 (classe `AccessToken`) | ~6 (Guzzle, PSR-7, `firebase/php-jwt`, …, d'après `composer.json` du paquet) | PHP `^8.1`, 2026-10-16 | Plus légère que C, officielle ; API de vérification non relue ici, mêmes arguments que C et B pour Guzzle et la deuxième bibliothèque JWT |
| E. `web-token/jwt-library` 4.2.3 | 1 à 2 (`spomky-labs/pki-framework`) ; `brick/math`, `psr/clock` déjà là | PHP `>=8.2`, 2026-09-12 | Complète (JWKSet) mais API très abstraite pour un besoin étroit, et une troisième bibliothèque JWT |
| F. Tout à la main | 0 | — | **Écartée** : écrire soi-même la vérification de signature est exactement ce qu'il ne faut pas faire |

**Essai jetable (2026-10-19, rien n'est livré).** Dans l'image de production (`repas-smoke-back`, PHP 8.4.23, `lcobucci/jwt` 5.6.1), un script de test a vérifié :

- `SignedWith(new Sha256(), InMemory::plainText($pemDuCertificat))` **accepte** un certificat X.509 PEM comme clé publique (la forme de `/oauth2/v1/certs`), et une clé publique PEM **reconstruite depuis un JWK** (`n`, `e` en DER `SubjectPublicKeyInfo`, une quinzaine de lignes) ;
- une mauvaise clé, une mauvaise audience, un jeton `HS256` forgé : **refusés** ;
- `StrictValidAt` **refuse** un jeton sans `nbf` (on ne sait pas encore si les jetons de Google en portent, ceux de GIS comme ceux de Credential Manager : à relever à la recette) : utiliser `LooseValidAt` **et** exiger `exp` présent ;
- `IssuedBy('accounts.google.com', 'https://accounts.google.com')` accepte les deux formes ;
- `HasClaimWithValue('email_verified', true)` refuse la chaîne `"true"`.

Le ticket AUTH-003 rejoue ces cas en test automatique, avec une paire de clés générée dans le test et un double du client HTTP : aucun appel réseau, aucune clé de Google dans le dépôt.

### 3.3 Les routes

| Route | Rôle | Réponses |
|---|---|---|
| `POST /api/auth/google/nonce` | émet un nonce à usage unique et renvoie `{nonce, clientId}` | `200` ; `404 GOOGLE_DISABLED` si `GOOGLE_CLIENT_ID` est vide ; `429` |
| `POST /api/auth/google` | corps JSON `{credential}` (le jeton) ; vérifie, lie ou crée, connecte | `200 {token}` + `Set-Cookie: repas_refresh` (identique à `/api/auth/refresh`) ; `401` jeton refusé ou compte désactivé (message unique `Invalid Google credential.`) ; `409 ACCOUNT_EXISTS_WITH_PASSWORD` ; `404 GOOGLE_DISABLED` ; `429` + `Retry-After` |

Pourquoi sous `/api/auth/` et non `/api/v1/` : c'est de l'authentification (comme `refresh` et `logout`, [ADR 0015](../adr/0015-refresh-token-du-spa.md) §10), le firewall `auth` est déjà `security: false`, donc **aucune ligne à ajouter dans `access_control`** ; mais ce firewall n'applique pas `UserChecker` : le handler contrôle le statut lui-même (`User::isActive()`, comme `RotateRefreshTokenHandler`). Les deux routes sont documentées en OpenAPI (`make openapi`).

Le corps ne porte pas le nonce : le back le lit dans le jeton vérifié et le cherche dans son magasin. Le contrôle `Content-Type: application/json` est exigé (pas de formulaire entre sites : § 4.3, CSRF de connexion).

### 3.4 Modèle DDD (contexte `User`, Deptrac)

Rien ne sort de `User` : la connexion Google n'a pas besoin de `Repas`, `Drive` ni d'un nouveau port `Shared`. Deptrac : `User → Shared` seulement (inchangé).

| Couche | Élément | Rôle |
|---|---|---|
| Domain/Model | `UserIdentity` (`id`, `userId`, `provider`, `subject`, `createdAt`) ; enum `IdentityProvider` (`GOOGLE`) ; valeur `VerifiedIdentity` (`provider`, `subject`, `email`, `emailVerified`, `nonce`, `hostedDomain`) avec `emailIsAuthoritative()` | l'identité externe et le jeton vérifié |
| Domain/Model | `User::registerFromIdentity()`, `User::hasPassword()`, `User::lockPassword()` (utilise `changePassword()`, donc fait monter la version des identifiants), constante `User::NO_PASSWORD = '!'` | compte sans mot de passe |
| Domain/Interface | `IdentityTokenVerifier` (`verify(string $idToken): VerifiedIdentity`), `UserIdentityRepository`, `LoginNonceStore` (`issue()`, `consume()`) | ports |
| Domain/Service | `ExternalIdentityResolver` (la politique du § 4 : retrouver, lier, créer, refuser) | testable sans base |
| Domain/Exception | `IdentityException` (factories : `invalidCredential()` 401, `accountExistsWithPassword()` 409, …) | |
| Application | `IssueLoginNonce` (commande qui **renvoie** le nonce : écrit dans le cache, donc pas une lecture), `SignInWithGoogle` (commande `idToken` + `refreshTokenHash`, **renvoie l'identifiant de connexion** du compte, comme `RotateRefreshToken`, ADR 0015 §12) | cas d'usage |
| Infrastructure | `Security/GoogleIdTokenVerifier`, `Security/GoogleSigningKeys` (client HTTP + cache), `Security/CacheLoginNonceStore`, `Security/GoogleSignInSettings` (`%env(GOOGLE_CLIENT_ID)%`) ; `Entity/UserIdentity`, `Repository/UserIdentityPostgreSQLRepository` ; `Http/Api/GoogleSignIn/…ApiController` (+ payload, limiteur) et `Http/Api/IssueLoginNonce/…` | adaptateurs |

Le controller dispatche `SignInWithGoogleCommand`, puis fait ce que fait `RefreshTokenApiController` : `UserProvider::loadUserByIdentifier()`, `JWTTokenManagerInterface::create()`, cookie. La **famille de refresh tokens** est ouverte *dans le même handler* (donc dans la même transaction que la liaison ou la création) par un service de domaine `RefreshTokenIssuer`, extrait de `IssueRefreshTokenHandler` (ticket AUTH-006) : le secret du cookie naît dans le controller et seul son hachage traverse le bus ([ADR 0015](../adr/0015-refresh-token-du-spa.md) §1).

### 3.5 Le nonce

Un nonce serveur à usage unique protège contre le rejeu d'un jeton intercepté et lie le jeton à *cette* tentative de connexion (la page Web comme Credential Manager le recopient dans la revendication `nonce`). Stockage : le cache de l'application (`cache.app`, clé = SHA-256 du nonce, TTL 5 minutes), **sans SQL ni migration**. Consommation : lecture puis suppression ; une course de quelques millisecondes entre deux requêtes identiques est acceptée (elle ne profite qu'à qui possède déjà le jeton). Limites assumées : cache vidé au redéploiement (le nonce d'avant est perdu, l'utilisateur recommence) ; une seule machine (comme les plafonds de débit, [ADR 0008](../adr/0008-auth-session-et-jwt.md) §12 et `docs/production.md`). Variante sans état si besoin : nonce signé par HMAC (`kernel.secret`) avec horodatage, valable 5 min mais rejouable pendant ce temps.

### 3.6 Migration, compatibilité avec la version précédente (ADR 0017 règle 6)

Table **nouvelle**, donc additive :

```
user_identity(
  id          varchar(36)  primary key,
  user_id     varchar(36)  not null,          -- pas de clé étrangère (convention du dépôt)
  provider    varchar(32)  not null,          -- 'GOOGLE'
  subject     varchar(255) not null,          -- le `sub` de Google
  created_at  timestamp    not null,
  unique (provider, subject),
  unique (user_id, provider)                  -- un lien Google par compte (premier lot)
)
```

La version précédente du code **ignore** la table ; aucune colonne n'est ajoutée à `user` ; `user.password` reste non nul (valeur verrouillée `!`, jamais un hachage valide : `password_verify` renvoie faux, comme le `!` des mots de passe verrouillés d'Unix). Retour arrière du code : sans danger pour le schéma ; **mais** les comptes créés par Google n'auraient plus aucun moyen de se connecter (pas de mot de passe, pas de route Google) : réversibilité en § 8.

### 3.7 Purge à la suppression du compte

`DeleteUserHandler` supprime les liens (`UserIdentityRepository::deleteAllOfUser()`, avant le compte, comme les refresh tokens et les liens de confirmation). `UserIdentity` gagne une colonne `userId` : `UserOwnedDataCoverageTest` **échoue tant que `UserIdentity::class => ['userId']` n'est pas dans `ERASED_WITH_THE_USER`** (c'est le garde-fou voulu). Budget du scénario `supprimer-compte` : 18 requêtes aujourd'hui, **19** attendu (une suppression de plus) : à mesurer et figer (`make sql-budget-update`). Rien d'autre à purger : le nonce vit 5 minutes dans le cache, le jeton Google n'est jamais gardé.

### 3.8 Budgets SQL, plafonds, tests

- Nouveaux scénarios `WriteBudget` (à **mesurer**, pas à deviner) : `connexion-google` (lien existant), `creation-compte-google`, `liaison-compte-google`. L'émission du nonce (`IssueLoginNonce`) ne touche pas à la base.
- Plafonds de débit par IP (même mécanique que `registration` : `RegisterNewUserRateLimitListener`) : limiteur `google_sign_in` (proposition : 20 par heure) et `google_nonce` (60 par heure), `429` + `Retry-After`, pool de test `test.rate_limiter_pool`.
- Tests : domaine (politique), handlers avec `UserInMemoryRepository`, `UserIdentityInMemoryRepository`, un faux `IdentityTokenVerifier` et un faux `LoginNonceStore` ; adaptateur de vérification avec de **vraies** signatures RSA générées dans le test ; dépôt sur base réelle (`tests/User/OutputAdapter/`) ; controllers avec `MockCommandBus` ; **un parcours complet** avec les vrais bus, un faux vérificateur et un faux Google (cookie posé, JWT valable sur `/api/v1/me`, compte désactivé refusé).

### 3.9 Interrupteur et déploiement

`GOOGLE_CLIENT_ID` (public, pas un secret) : vide = fonction coupée, comme `DRIVE_ENABLED=false`. Il doit être **listé explicitement** dans `deploy/compose.yaml` avec sa valeur par défaut (`${GOOGLE_CLIENT_ID:-}`), sinon la valeur du `.env` embarqué dans l'image s'appliquerait en silence (piège déjà rencontré, [`docs/production.md`](../production.md)). `deploy/.env.example`, `docs/production.md`, le skill `repas-ops` et `make deploy-smoke` suivent (ticket AUTH-008). L'allumage est un geste humain par script, après un déploiement stable.

## 4. Politique de compte (question 3)

### 4.1 Principes

1. **La clé, c'est le `sub`**, pas l'e-mail : Google l'affirme (« unique et jamais réutilisé ; un compte Google peut avoir plusieurs adresses au fil du temps »). L'e-mail ne sert qu'à retrouver un compte existant **la première fois**.
2. **Google ne fait foi sur l'e-mail que pour `@gmail.com`** (et les domaines Workspace, `hd`) ; pour tout autre domaine, Google recommande « un mot de passe ou une autre vérification » ([page de vérification](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token)). La même page prévient que « la propriété d'un compte tiers a pu changer ».
3. **Un compte qui n'est pas actif ne peut rien** ([ADR 0021](../adr/0021-gestion-des-comptes-par-l-administrateur.md) §4) : Google n'est pas une porte dérobée.
4. **Rien n'est écrit tant que tout n'est pas vérifié** : le handler est transactionnel ([ADR 0006](../adr/0006-buses-messenger.md)) ; un refus ne laisse ni compte, ni lien, ni famille de jetons.

### 4.2 Les cas (proposition, à trancher en § 4.4)

| # | Situation | Proposition |
|---|---|---|
| 1 | Jeton invalide, expiré, mauvaise audience, nonce inconnu ou déjà utilisé | `401` unique, rien d'écrit |
| 2 | `email_verified` absent ou faux | `401` unique ; on ne crée ni ne lie rien |
| 3 | Le `sub` est déjà lié à un compte | Connexion à **ce** compte, quelle que soit l'adresse du jeton (Google a pu la changer). Statut `ACTIVE` exigé |
| 4 | Le `sub` est lié à un compte `DISABLED` | `401` unique, comme un mot de passe faux (pas de fuite) |
| 5 | Le `sub` est inconnu et **aucun compte** n'a cette adresse | **Création** : `ACTIVE`, `ROLE_USER`, nombre de personnes par défaut (4, comme l'écran d'inscription), mot de passe verrouillé, lien posé, statistiques initialisées. Aucune confirmation par e-mail : Google l'a faite |
| 6 | `sub` inconnu, adresse d'un compte `ACTIVE`, adresse en **Gmail** | **Liaison automatique** puis connexion. Le mot de passe du compte reste utilisable |
| 7 | `sub` inconnu, adresse d'un compte `ACTIVE`, adresse **non Gmail** (autre domaine, Workspace compris) | **Refus `409 ACCOUNT_EXISTS_WITH_PASSWORD`** (« Cette adresse a déjà un compte Repas… ») : on ne relie pas sur une preuve que Google lui-même juge insuffisante. Variante facultative plus tard : relier après saisie du mot de passe (hors premier lot) |
| 8 | `sub` inconnu, adresse d'un compte `PENDING` (inscrit au mot de passe, lien jamais confirmé), adresse Gmail | Liaison, **activation** (Google prouve l'adresse, le lien en attente est supprimé), et **le mot de passe existant est verrouillé** (la version des identifiants monte : tout jeton est révoqué). Sans cela, la personne qui s'était inscrite avec l'adresse d'autrui et connaît le mot de passe garderait l'accès (même raisonnement que [ADR 0020](../adr/0020-confirmation-d-adresse-email.md) §10) |
| 9 | Idem cas 8, adresse non Gmail | Refus `409` (comme le cas 7) |
| 10 | Adresse d'un compte `DISABLED` | `401` unique, **aucun lien posé** |
| 11 | Le compte protégé (`User::PROTECTED_EMAIL`) | Aucun traitement spécial : son adresse est Gmail, donc cas 6. Il reste intouchable par l'API d'administration (ADR 0021 §3). **Conseil à l'humain, hors dépôt** : activer la validation en deux étapes sur ce compte Google, qui devient une porte vers l'administration |
| 12 | L'utilisateur change d'adresse chez Google | Sans effet : la connexion passe par le `sub`. L'adresse du compte Repas ne bouge pas (pas de synchronisation) |
| 13 | Un deuxième compte Google (autre adresse) pour la même personne | Deuxième compte Repas séparé (limite assumée). Pas de fusion automatique |
| 14 | Les droits d'administrateur | Jamais accordés par Google : la création met `ROLE_USER` |
| 15 | Inscription par mot de passe avec une adresse déjà créée par Google | `409` comme aujourd'hui ; le front peut proposer « Se connecter avec Google » |
| 16 | Suppression du compte | Le lien part avec lui ; l'adresse redevient libre |
| 17 | Fonction coupée (`GOOGLE_CLIENT_ID` vide) | Bouton caché, routes en `404` ; les comptes sans mot de passe n'ont plus d'accès (§ 8) |
| 18 | Compte créé par Google, mot de passe verrouillé (`!`) | La connexion par mot de passe est impossible (`password_verify` renvoie faux) ; « Moi » ne peut pas en poser un (il exige l'ancien) ; issue de secours : « mot de passe oublié » (`repas-front#31`) ou AUTH-014 (facultatif, D3) |

### 4.3 Risques de prise de contrôle et parades

| Risque | Parade |
|---|---|
| Lier sur un e-mail **non vérifié** | `email_verified === true` exigé, strict |
| **Adresse recyclée** (nom de domaine racheté : un tiers recrée le compte Workspace d'un ancien salarié ; [rapport Truffle Security, janvier 2025](https://thehackernews.com/2025/01/google-oauth-vulnerability-exposes.html), état du correctif de Google non confirmé) | Liaison automatique **limitée à Gmail** (cas 6 à 9), que Google ne réattribue pas. Après la première liaison, seul le `sub` compte |
| **Compte en attente piégé** (un tiers s'inscrit avec l'adresse de la victime et connaît le mot de passe) | Cas 8 : mot de passe verrouillé, version des identifiants incrémentée |
| **Rejeu d'un jeton intercepté** | Nonce serveur à usage unique, 5 minutes ; `exp` ; HTTPS ; le jeton n'est jamais journalisé |
| **Jeton émis pour une autre application** | `aud` égal à notre ID client Web ; clients Google limités à nos origines JavaScript et à notre paquet/empreinte |
| **Application Android imitée** signée de la clé de débogage publique | Cette empreinte n'est jamais enregistrée dans le projet de production (§ 2.4) |
| **CSRF de connexion** (forcer le navigateur de la victime à se connecter au compte de l'attaquant) | Corps JSON exigé (pas de formulaire entre sites), CORS limité à l'origine du site, nonce lié à une tentative, cookie `SameSite=Strict` |
| **Énumération** de comptes | Les `401` sont uniques ; le `409` du cas 7 ne s'adresse qu'à quelqu'un qui a prouvé posséder cette adresse chez Google |
| **Vol du jeton par script** (XSS) | CSP stricte conservée (§ 5.3) ; le jeton Google n'est jamais mis en stockage |
| **Variante d'adresse Gmail** (`pre.nom@gmail.com` et `prenom@gmail.com` sont la même boîte, mais deux chaînes) | Non traité au premier lot : comparaison exacte après minuscules. Risque : un doublon de compte, jamais une prise de contrôle ; l'administrateur voit la liste des comptes |

### 4.4 Décisions à faire trancher par le propriétaire

| # | Question | Recommandation | Autres choix |
|---|---|---|---|
| **D1** | Créer un compte à la première connexion Google ? Quel statut ? | **Oui, `ACTIVE`, sans validation par l'administrateur** : l'inscription par e-mail est déjà ouverte à tous avec le même niveau de preuve ; un contrôle plus strict n'apporte rien tant que l'inscription reste ouverte | `PENDING` + activation par l'administrateur (ADR 0021) : demande une notification à l'administrateur qui n'existe pas ; « sur invitation » : demande une liste d'invités |
| **D2** | Lier à un compte existant par l'adresse ? | **Oui pour `@gmail.com` seulement** ; autre domaine : refus et mot de passe | Règle littérale de Google (Gmail **ou** `hd`) : plus large, expose au domaine recyclé ; jamais de liaison automatique : sûr mais l'utilisateur existant ne peut plus passer à Google sans l'administrateur |
| **D3** | Mot de passe facultatif pour un compte Google | **Pas de mot de passe au départ** ; l'issue de secours est « mot de passe oublié » (`#31`) ; AUTH-014 (poser un premier mot de passe depuis « Moi ») est **facultatif** | Faire AUTH-014 avant d'ouvrir à tous : plus sûr si Google disparaît, un écran de plus |
| **D4** | Faire la vérification de marque Google ? Rendre publique la page « Conditions d'utilisation » ? | **Pas en premier lot** (fonction pour la famille). Sans vérification de marque, le nom « Repas » n'apparaît pas dans la fenêtre Google (le site s'affiche). La page publique + section Google (AUTH-010) est utile même sans vérification | Vérification de marque : page d'accueil et de confidentialité publiques sur le même domaine, domaine vérifié dans Search Console, 2 à 3 jours ouvrés en revue manuelle |
| **D5** | Des proches se connectent-ils depuis un **iPhone** (Safari) ? | **Hors premier lot** : Safari garde le mot de passe (Google impose le mode `redirect` sur iOS) | Ajouter le mode `redirect` (route de plus) si besoin |

## 5. Front et application (question 4)

### 5.1 Écrans : une décision par écran

- **Connexion** (`/connexion`) : en haut le bouton Google, puis « ou », puis le formulaire existant. Le bouton est le premier geste proposé (le but est d'éviter le mot de passe) ; le formulaire reste complet en dessous. Bouton rendu par Google sur le web (`text: signin_with`, « Se connecter avec Google », `locale: fr`) ; dans l'application, bouton dessiné selon la [charte Google](https://developers.google.com/identity/branding-guidelines) (2026-07-07 : « G » en couleurs non modifiable, fond blanc `#FFFFFF` + contour `#747775` + texte `#1F1F1F`, ou sombre `#131314`/`#8E918F`/`#E3E3E3`, libellé « Se connecter avec Google », au moins aussi visible que l'autre option).
- **Inscription** (`/inscription`) : même bouton (`signup_with`, « S'inscrire avec Google »), même effet. Pas d'écran de plus : le compte se crée et on arrive sur la liste.
- **Aucun écran intermédiaire**, aucune case à cocher, aucun avertissement (règle 10 de [`principes-produit.md`](../drive/principes-produit.md)).

**Touches du parcours heureux** (estimation de cadrage, à relever à la recette) : nouveau proche, mot de passe : ouvrir « Créer un compte », 3 champs, créer, ouvrir la messagerie, ouvrir le message, toucher le lien, puis se connecter (2 champs + bouton) : **environ 12 gestes** ; avec Google : **2** (bouton, compte). Reconnexion ensuite : 0 (cookie de rafraîchissement, 30 jours).

### 5.2 Textes (tutoiement, DRV-627 fait le 2026-10-19 ; le test de voix du front interdit « vous », « votre », « vos » et les impératifs en « -ez »)

| Où | Texte |
|---|---|
| Bouton web (Google, `fr`) | « Se connecter avec Google » / « S'inscrire avec Google » |
| Séparateur | « ou » |
| Échec (`401`, fenêtre fermée par erreur technique) | « La connexion avec Google n'a pas abouti. Rien n'a été modifié. Réessaie, ou utilise ton mot de passe. » |
| Adresse déjà inscrite avec un mot de passe (`409`) | « Cette adresse a déjà un compte Repas protégé par un mot de passe. Rien n'a été modifié. Connecte-toi avec ton mot de passe. » |
| Google indisponible sur le téléphone | « Google n'est pas disponible sur ce téléphone. Ton compte Repas n'est pas concerné. Utilise ton adresse et ton mot de passe. » |
| Aucun compte Google sur le téléphone | « Aucun compte Google n'est enregistré sur ce téléphone. Ton compte Repas n'est pas concerné. Utilise ton adresse et ton mot de passe. » |
| Trop d'essais (`429`) | comme `login.tooMany` |
| Fenêtre fermée par l'utilisateur | **rien** (pas de message) |

Glossaire : « compte Google » (pas « identité », « jeton », « OAuth », « SSO », « lier »). Un test de glossaire (ticket AUTH-009) échoue si ces mots apparaissent.

### 5.3 CSP à ouvrir (vérifié, [page « get-google-api-clientid »](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid), 2026-04-06)

À `repas-front/docker/Caddyfile` :

```
script-src 'self' https://accounts.google.com/gsi/client;
frame-src  https://accounts.google.com/gsi/;
connect-src 'self' https://accounts.google.com/gsi/;
style-src  'self' https://accounts.google.com/gsi/style;
```

Le reste inchangé (`img-src 'self' data:` suffit : les images du bouton sont dans l'iframe de Google ; Google ne cite aucune `img-src`). Google déconseille de lister des adresses plus fines pour `connect-src` (elles changent). Pas de COOP à poser : avec FedCM désactivé, Google demande `same-origin-allow-popups` **si** un COOP est posé ; ici il n'y en a aucun (valeur par défaut, sans effet). L'image du front est la même pour tout le monde : la CSP est ouverte même fonction coupée ; le script ne se charge que si la fonction est allumée. L'application n'est pas touchée (le script GIS n'y est jamais chargé).

Le service worker (`navigateFallback` limité aux navigations) ne touche pas aux requêtes externes ; à vérifier en test (AUTH-009).

### 5.4 Vie privée (ce que Google voit, ce que Repas retient)

| Qui | Quoi |
|---|---|
| **Google voit** | que ce compte Google se connecte à `kantin.eu` (ou à l'application Repas) à cet instant ; l'adresse IP et le navigateur de l'écran de connexion ; **dès l'affichage** du bouton (sur le web), la page de connexion charge un script et un cadre de Google (c'est pourquoi le script n'est chargé que sur les deux écrans de connexion). Il ne voit ni les recettes, ni les listes, ni le drive |
| **Repas reçoit** | le jeton d'identité : `sub`, `email`, `email_verified`, et aussi `name`, `picture` (inclus d'office par GIS) que **Repas ignore** |
| **Repas retient** | le `sub` (table `user_identity`), l'adresse e-mail (déjà retenue pour tout compte), la date de création du lien. Ni le nom, ni la photo, ni le jeton |
| **Repas ne demande pas** | l'accès à Gmail, Drive, contacts : seules les portées `openid email profile` (non sensibles) |
| **Effacement** | la suppression du compte (ADR 0021) efface le lien ; l'utilisateur peut aussi retirer l'accès de Repas dans les réglages de son compte Google (adresse à vérifier au moment de rédiger la page) |

À ajouter à la page de conditions : AUTH-010 (section « Connexion avec Google »). Aujourd'hui la seule page de conditions est celle du drive, **derrière la connexion** ; pour qu'un visiteur puisse la lire avant de se connecter (et pour la vérification de marque éventuelle, D4), elle devient publique.

### 5.5 Le pont natif : messages proposés

Page vers application : `{"v":1,"type":"google.signIn","clientId":"<ID client Web>","nonce":"<nonce serveur>"}`.
Application vers page : `{"v":1,"type":"google.result","idToken":"<jeton>"}` ou `{"v":1,"type":"google.error","code":"CANCELLED|NO_ACCOUNT|UNAVAILABLE|BUSY|INVALID_REQUEST|FAILED"}`.
`app.info` gagne `"google": true` (drapeau de capacité : une page sur une application plus ancienne ne propose pas le bouton). L'application valide : `clientId` de la forme `<chiffres>-<alphanumérique>.apps.googleusercontent.com`, `nonce` de 16 à 128 caractères `[A-Za-z0-9_-]`, une seule demande à la fois, message de 64 Ko au plus, origine de Repas seulement (inchangé). Le jeton n'est jamais journalisé. L'ID client vient de la page (donc du back) : le même APK sert si l'ID client change. Côté front, `parseInbound` ignore déjà un type inconnu et un champ en plus : un ancien front avec une nouvelle application, ou l'inverse, ne casse rien ; l'ordre habituel (site d'abord, application ensuite) reste la règle.

## 6. Console Google Cloud : ce que l'utilisateur doit faire (question 5)

À faire **par lui**, **une action par étape**, avec son compte Google. Le guidage se fait une étape à la fois dans la conversation (jamais la liste entière), selon `repas-ops` ; l'ID client n'est pas un secret, mais on le garde hors du chat et du dépôt par habitude : il le saisit lui-même dans le script d'AUTH-008. Les libellés de la console changent : ceux-ci viennent de l'aide Google ([démarrage](https://support.google.com/cloud/answer/15544987), [clients](https://support.google.com/cloud/answer/15549257), [audience](https://support.google.com/cloud/answer/15549945)) ; si un nom diffère, l'assistant le lui montre à l'écran.

| # | Action | But | Résultat attendu |
|---|---|---|---|
| 1 | Ouvrir `https://console.cloud.google.com/` connecté au compte Google qui **possédera** le projet | Le projet appartient à ce compte : c'est lui qui pourra le modifier | Le tableau de bord de la console s'affiche (accepter les conditions si on le demande) |
| 2 | Créer un projet nommé « Repas » (menu des projets en haut, « Nouveau projet », sans organisation) | Un conteneur pour la connexion ; aucun paiement n'est demandé pour cela (supposé : à confirmer à l'écran) | « Repas » apparaît en haut de la console |
| 3 | Ouvrir « Google Auth Platform » (barre de recherche de la console) et toucher « Commencer » | C'est l'écran de gestion de la connexion | L'assistant de configuration s'ouvre |
| 4 | Remplir « Informations sur l'application » : nom « Repas » et une adresse d'assistance (la sienne) | L'adresse d'assistance est montrée aux utilisateurs dans la fenêtre Google | Étape suivante accessible |
| 5 | Choisir l'audience **Externe** | Les proches ont des comptes Google ordinaires ; « Interne » est réservé aux organisations | « Externe » sélectionné |
| 6 | Saisir l'adresse de contact des notifications du projet, accepter la politique, toucher « Créer » | Google y écrit en cas de changement de règle | Le projet est configuré, le tableau de bord de Google Auth Platform s'affiche |
| 7 | Ouvrir « Accès aux données » et vérifier qu'il n'y a **aucune** autre portée que `openid`, `email`, `profile` | Ces trois portées ne sont pas sensibles : aucune vérification Google n'est obligatoire ([aide](https://support.google.com/cloud/answer/13463073)) ; en ajouter une imposerait une revue | Seules ces portées (ou aucune ligne ajoutée) sont listées |
| 8 | Ouvrir « Audience » et toucher **« Publier l'application »** (statut « En production ») | En « Test », seuls 100 testeurs listés une par une peuvent se connecter ([aide](https://support.google.com/cloud/answer/15549945)) ; une application qui n'utilise que « Se connecter avec Google » échappe à l'expiration de 7 jours et à l'avertissement des testeurs, mais pas à la liste de testeurs | Le statut indique « En production » |
| 9 | Ouvrir « Clients » > « Créer un client » > type **Application Web**, nom « Repas web » ; dans « Origines JavaScript autorisées » ajouter `https://kantin.eu` (et rien d'autre) ; **ne rien mettre** dans « URI de redirection » ; toucher « Créer » | Google n'acceptera le bouton que depuis ce site (sinon erreur `origin_mismatch`) ; il n'y a pas de redirection car on ne fait pas le flux par code | Un **ID client** (se termine par `.apps.googleusercontent.com`) s'affiche. Un « code secret » peut aussi s'afficher : **ne pas le copier ni le garder**, il ne sert à rien ici |
| 10 | Noter l'ID client Web (gestionnaire de mots de passe) | Il sera saisi dans le script d'AUTH-008 ; il est public mais propre au déploiement | L'ID client est conservé hors du chat |
| 11 | Lire l'empreinte **SHA-1** du certificat de signature de l'application : la commande `keytool -list -v …` de l'étape 2 de `android/PUBLICATION.md` (dépôt front) affiche la ligne `SHA1:` en plus de `SHA256:` | Google vérifie que l'application installée est bien la sienne | Une ligne `SHA1: AA:BB:…` (20 groupes) à l'écran ; **le mot de passe de la clé ne sort jamais du terminal** |
| 12 | Ouvrir « Clients » > « Créer un client » > type **Android**, nom « Repas Android », nom du paquet `eu.kantin.repas.drive`, empreinte SHA-1 de l'étape 11 ; toucher « Créer » | Relie l'application signée à ce projet (pour Credential Manager) | Un second client apparaît dans la liste (son ID n'est pas utilisé par le back) |
| 13 | **Ne pas** enregistrer l'empreinte de la clé de débogage (mot de passe `android`) | Cette clé est publique : l'enregistrer permettrait à n'importe qui de se faire passer pour l'application | La liste ne contient que l'empreinte de publication |
| 14 | (Facultatif, recommandé) ajouter une seconde adresse comme propriétaire du projet (« IAM et administration » > « IAM » > « Accorder l'accès », rôle Propriétaire) | Si le compte propriétaire est perdu, le projet l'est aussi | Deux propriétaires listés |
| 15 | Fixer un rappel annuel : « Google supprime un client OAuth **inactif depuis six mois** » ([aide](https://support.google.com/cloud/answer/15549257) : un client est « actif » s'il sert à une demande de jeton ; prévenu 30 jours avant ; récupérable environ 30 jours) | Éviter que la fonction s'éteigne seule si elle reste coupée longtemps | Rappel créé |
| 16 | Donner l'ID client Web au script de déploiement (AUTH-008), **puis seulement** l'allumer | La fonction ne s'allume qu'après un déploiement stable | `POST /api/auth/google/nonce` répond `200` (AUTH-008 le contrôle) |

Vérification de marque (facultative, D4) : « Image de marque » > domaine autorisé `kantin.eu` (propriété à prouver dans Search Console), page d'accueil et page de confidentialité publiques sur ce domaine, puis « Vérifier la marque » ; automatique en quelques minutes, sinon revue manuelle de 2 à 3 jours ouvrés ([aide](https://developers.google.com/identity/protocols/oauth2/production-readiness/brand-verification), 2026-08-19).

**Où ranger l'ID client** : `GOOGLE_CLIENT_ID` dans `/opt/repas/.env` du serveur (droits 600), transmis au conteneur `php` par `deploy/compose.yaml` ; modèle (valeur vide) dans `deploy/.env.example`. **Jamais** de *client secret*, de clé de signature, de mot de passe de clé ni d'ID client dans le dépôt public ou le chat. Le front lit l'ID client dans la réponse de `POST /api/auth/google/nonce` (pas de variable de construction, l'image du front reste sans configuration, [ADR 0017](../adr/0017-deploiement-et-releases.md)) ; l'application le reçoit de la page.

## 7. Alternatives écartées (question 6)

| Alternative | Raison |
|---|---|
| **Lien magique par e-mail** | Sans Google, et le Mailer (Brevo) existe déjà ; mais chaque connexion exige d'aller dans la messagerie puis de revenir (changement d'application sur téléphone), la délivrabilité devient critique, et le lien s'ouvre dans l'application seulement grâce à `assetlinks.json`. **C'est l'alternative sans Google la plus simple, mais plus longue** que « bouton + compte » pour des proches qui ont déjà un compte Google sur leur téléphone Android |
| **Passkeys / WebAuthn** | Excellente expérience (empreinte digitale) mais la WebView n'accepte WebAuthn que par une intégration `androidx.webkit` ≥ 1.12.0 liée à `assetlinks.json` ([page](https://developer.android.com/identity/sign-in/credential-manager-webview), 2026-02-26) ; il faut quand même un premier compte (donc un mot de passe ou un lien) ; une bibliothèque WebAuthn côté PHP et un écran de gestion des clés. Bonne suite possible, trop de pièces pour le but |
| **Mot de passe unique partagé** | Aucune identité individuelle : plus de listes ni de recettes par personne, plus de désactivation d'un compte, un secret connu de tous |
| **Sign in with Apple** | Pour iPhone ; demande un compte développeur Apple payant (supposé, non revérifié) ; l'application est Android |
| **Gestionnaire de mots de passe / saisie automatique** | Gratuit et sans code : les formulaires ont déjà `autocomplete` ; mais ne règle pas « créer et retenir », surtout dans la WebView |
| **Connexion Facebook, Microsoft, etc.** | Aucun besoin : chaque téléphone Android a un compte Google |
| **Flux OAuth par code côté serveur** | Secret serveur, PKCE, route de retour, rien de gagné (§ 2.2 d) |
| **Bibliothèques lourdes** (`google/apiclient`) | § 3.2 |

## 8. Risques et inconnues (question 7)

**Ne peut se tester qu'avec un téléphone réel et un compte Google d'essai** (AUTH-013) :

- la feuille native de Credential Manager (affichage, compte choisi, annulation, texte montré par Google pour une application non vérifiée) ;
- l'acceptation de l'APK signé de l'utilisateur, **hors Google Play**, par le client Android (SHA-1) : supposé, non prouvé ;
- le contenu réel du jeton (`aud` = ID client Web, présence de `nbf`, `nonce` recopié tel quel) ;
- le bouton GIS dans Chrome sur téléphone et sur ordinateur (avec et sans FedCM), l'origine autorisée, le blocage éventuel par un bloqueur de publicités ;
- un téléphone **sans** services Google (le bouton ne doit pas apparaître ou doit échouer proprement) ;
- Safari sur iPhone (hors premier lot, D5).

**Faisable sans téléphone** : tout le back (vérification avec de vraies signatures RSA de test), le front web (Vitest, Playwright avec un faux Google), la validation des messages du pont (Kotlin pur) et la coquille dans l'émulateur avec un faux fournisseur. Un compte Google d'essai **créé pour l'occasion** (pas ceux des proches) suffit pour le web depuis un PC.

| Risque | Gravité | Parade |
|---|---|---|
| **Coût** | nul | « Se connecter avec Google » et la console n'ont pas de tarif (supposé : aucun paiement demandé, à confirmer à l'étape 2 de la console) |
| **Dépendance à Google** (compte Google perdu ou suspendu, panne de Google, client OAuth supprimé après six mois d'inactivité) | moyenne | Le mot de passe reste possible pour les comptes qui en ont un ; « mot de passe oublié » (`#31`) ou AUTH-014 pour les autres ; rappel annuel (console, étape 15) |
| **Réversibilité** | moyenne | Couper = vider `GOOGLE_CLIENT_ID`. Les comptes créés par Google gardent leurs données mais n'ont **aucune** façon de se connecter tant qu'ils n'ont pas de mot de passe : recommandation, **ouvrir la fonction à tous seulement après `#31` ou AUTH-014** ; d'ici là, l'administrateur peut supprimer un compte (rien d'autre) |
| **Service Google injoignable** (chargement des clés) | faible | Cache jusqu'à 24 h ; au-delà, refus propre |
| **Politique de Google qui change** | faible | Suivre les pages citées (§ 10) ; la fonction est un interrupteur |
| **Taille/complexité de la coquille Android** | moyenne | Mesure dans AUTH-011 |
| **Doublons de comptes** (variantes d'adresse Gmail, deuxième compte Google) | faible | Visible dans la liste d'administration ; pas de fusion automatique |
| **Une adresse non Gmail** déjà inscrite ne pourra pas passer à Google | faible | Refus poli (cas 7) ; variante par mot de passe plus tard |
| **CSP élargie** | faible | Quatre sources, chemins précis de `accounts.google.com`, jamais un joker |
| **Application plus récente que le site** | faible | Aucun message cassant (§ 5.5) ; ordre habituel : site, puis application |

## 9. Schémas de flux

**Web (navigateur, GIS en mode fenêtre)**

```
SPA kantin.eu                            Back (Symfony)                         Google
   | POST /api/auth/google/nonce --------->|                                      |
   |<-- {nonce, clientId} -----------------| (nonce en cache, 5 min)              |
   | charge accounts.google.com/gsi/client ----------------------------------------->|
   | initialize({client_id, nonce, callback}) ; renderButton(...)                   |
   | l'utilisateur touche le bouton Google et choisit son compte <------------------>|
   |<-- callback({credential = jeton d'identité signé par Google}) -------------------|
   | POST /api/auth/google {credential} -->|                                      |
   |                                        | clés publiques de Google (cache) --->| (rare)
   |                                        | signature, iss, aud, exp, nonce      |
   |                                        | retrouve / lie / crée le compte      |
   |                                        | ouvre la famille de refresh tokens   |
   |<-- 200 {token}  +  Set-Cookie: repas_refresh (HttpOnly, Strict) ----------------|
```

**Application Android (WebView + Credential Manager)**

```
Page (WebView, kantin.eu)    Application (Kotlin)    Services Google Play    Back
   | POST /api/auth/google/nonce ------------------------------------------>|
   |<-- {nonce, clientId} --------------------------------------------------|
   | repasNative.postMessage({type:"google.signIn", clientId, nonce})        |
   |-------------------------->| CredentialManager.getCredential(            |
   |                           |   GetSignInWithGoogleOption(clientId, nonce))
   |                           |------------------------>| feuille native : choix du compte
   |                           |<-- GoogleIdTokenCredential(idToken) -------|
   |<-- {type:"google.result", idToken} (même canal)       |                 |
   | POST /api/auth/google {credential: idToken} ---------------------------->|
   |<-- 200 {token} + Set-Cookie: repas_refresh ------------------------------|
```

Le cookie est posé par la réponse reçue **dans la WebView** (même pile réseau que la connexion par mot de passe) : rien à copier d'une application à une autre.

## 10. Sources et niveau de preuve

**Vérifié (page officielle lue le 2026-10-19 ; date de la page entre parenthèses)**

| Fait | Source |
|---|---|
| Interdiction d'envoyer une demande OAuth à un agent utilisateur intégré | [Règles OAuth 2.0 de Google](https://developers.google.com/identity/protocols/oauth2/policies) (2026-08-05) |
| `disallowed_useragent` ; schémas d'URI personnalisés abandonnés sur Android | [OAuth 2.0 pour applications natives](https://developers.google.com/identity/protocols/oauth2/native-app) (2026-10-16) |
| WebViews Android/iOS « non prises en charge » par GIS ; Custom Tabs recommandés ; iOS : mode `redirect` | [GIS, navigateurs pris en charge](https://developers.google.com/identity/gsi/web/guides/supported-browsers) (2026-09-01) |
| Credential Manager, `GetSignInWithGoogleOption`, nonce, client Web comme `serverClientId`, Android 4.4+, dépendances | [Implémenter Sign in with Google](https://developer.android.com/identity/sign-in/credential-manager-siwg-implementation) et [prérequis](https://developer.android.com/identity/sign-in/credential-manager-siwg) (2026-10-16) |
| Deux clients (Android : paquet + SHA-1 ; Web : `serverClientId`) | [Atelier Sign in with Google Android](https://codelabs.developers.google.com/sign-in-with-google-android) (sans date) |
| Erreurs (`NoCredentialException`, annulation, `TransactionTooLargeException`) | [Dépannage Credential Manager](https://developer.android.com/identity/sign-in/credential-manager-troubleshooting-guide) (2024-01-02) |
| Credential Manager en WebView = passkeys seulement | [Credential Manager et WebView](https://developer.android.com/identity/sign-in/credential-manager-webview) (2026-02-26) |
| Contrôles du jeton ; `sub` jamais réutilisé ; Google fait foi pour Gmail et `hd` ; clés PEM et JWK ; `Cache-Control` ; pas de mention du `nonce` | [Vérifier le jeton d'identité](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token) (2025-12-22) |
| `nonce`, `ux_mode`, `use_fedcm_for_button` | [Référence JavaScript GIS](https://developers.google.com/identity/gsi/web/reference/js-reference) (2026-09-01) |
| FedCM facultatif pour le bouton | [Migrer vers FedCM](https://developers.google.com/identity/gsi/web/guides/fedcm-migration) (2026-02-10) |
| CSP, origines JavaScript, COOP | [Obtenir l'ID client GIS](https://developers.google.com/identity/gsi/web/guides/get-google-api-clientid) (2026-04-06) |
| Charte du bouton | [Branding guidelines](https://developers.google.com/identity/branding-guidelines) (2026-07-07) |
| Audience : Test (100 testeurs, 7 jours), exception « Sign in with Google », Production | [Gérer l'audience](https://support.google.com/cloud/answer/15549945) (sans date) |
| Clients : champs, secrets, **suppression après 6 mois d'inactivité** | [Gérer les clients OAuth](https://support.google.com/cloud/answer/15549257) (sans date) |
| Portées non sensibles : vérification non obligatoire ; marque pour afficher nom et logo | [Aide vérification](https://support.google.com/cloud/answer/13463073) (sans date), [vérification de marque](https://developers.google.com/identity/protocols/oauth2/production-readiness/brand-verification) (2026-08-19), [démarrage](https://support.google.com/cloud/answer/15544987) (sans date : le nom n'est affiché que si l'application est vérifiée) |
| Versions Maven : `androidx.credentials` 1.6.0 stable (1.7.0-alpha03 en préversion), `googleid` 1.2.1 | `dl.google.com/android/maven2/…/maven-metadata.xml` |
| Versions Packagist des bibliothèques PHP | `repo.packagist.org/p2/…` |
| Clés publiques de Google : `kid` identiques en v1 et v3, durées de cache | `https://www.googleapis.com/oauth2/v1/certs`, `…/v3/certs` (en-têtes lus) |
| Code existant (§ 1) | lecture des dépôts back et front, `main` du 2026-10-19 |
| `lcobucci/jwt` 5.6.1 + certificat PEM / PEM issu d'un JWK, cas négatifs | **essai jetable** dans l'image `repas-smoke-back` (PHP 8.4.23), 2026-10-19 ; non livré |

**Lu par résultat de recherche seulement (non relu en entier)** : billet de 2021 sur `disallowed_useragent` ; guide de migration du Google Sign-In historique ; [rapport Truffle Security sur les domaines recyclés](https://thehackernews.com/2025/01/google-oauth-vulnerability-exposes.html) et [SecurityWeek](https://www.securityweek.com/google-oauth-flaw-leads-to-account-takeover-when-domain-ownership-changes/) (l'état du correctif de Google n'est pas confirmé) ; [passkeys.dev, Android](https://passkeys.dev/docs/reference/android/) (WebAuthn et WebViews intégrées).

**Supposé, à prouver** : (1) l'APK signé par l'utilisateur hors Google Play est accepté par le client Android ; (2) `azp`/`aud` du jeton de Credential Manager (`aud` = ID client Web) et présence de `nbf` ; (3) ce que Google affiche pour une application dont la marque n'est pas vérifiée (le site, supposé) ; (4) aucun paiement n'est demandé pour créer le projet ; (5) signature exacte de `getCredentialAsync` (version à callback, sans coroutines) ; (6) poids de l'APK ; (7) comportement sans services Google Play ; (8) adresse de la page de révocation chez Google ; (9) prix du compte développeur Apple.

## 8. Décisions du propriétaire (2026-10-19)

Voir le tableau de l'[ADR 0024](../adr/0024-connexion-avec-google.md) (« Décisions du propriétaire ») : D1 oui, D2 Gmail seulement, D3 on attend « mot de passe oublié » avant d'ouvrir à tous, D4 pas de vérification de marque au premier lot, D5 iPhone hors lot mais tronc commun Kotlin préparé (ticket AUTH-015).
