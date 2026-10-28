# 0024 — Connexion avec Google : jeton d'identité, GIS sur le web, Credential Manager dans l'application

- **Statut** : Accepted
- **Date** : 2026-10-19
- **Liens** : analyse [`docs/auth/connexion-google.md`](../auth/connexion-google.md), tickets [`docs/auth/roadmap.md`](../auth/roadmap.md) ; [0008](0008-auth-session-et-jwt.md) §4, §7, §12, §13 ; [0015](0015-refresh-token-du-spa.md) §1, §10, §12 ; [0020](0020-confirmation-d-adresse-email.md) §10 ; [0021](0021-gestion-des-comptes-par-l-administrateur.md) §3, §4, §10 ; [0013](0013-agregats-references-par-identifiant.md) ; [0014](0014-montee-de-versions-et-dependabot.md) ; [0017](0017-deploiement-et-releases.md) règle 6, 7 ; [0007](0007-api-rest-v1.md) §14

## Contexte

Les proches de l'auteur (non techniques) doivent aujourd'hui créer un mot de passe, confirmer leur adresse par un lien reçu par e-mail, puis se souvenir du mot de passe ([0020](0020-confirmation-d-adresse-email.md)). L'objectif : leur éviter cela avec un bouton « Se connecter avec Google ».

Faits vérifiés (détail et sources datées dans l'analyse) :

- La seule connexion est `POST /api/login_check` (e-mail + mot de passe), suivie d'un cookie de rafraîchissement `HttpOnly` ([0015](0015-refresh-token-du-spa.md)). Les routes `/api/auth/*` sont hors pare-feu (`security: false`) : ni `access_control`, ni `UserChecker`.
- L'application Android « Repas » est une WebView sur `https://kantin.eu/` avec un pont natif `window.repasNative`, installée à la main (APK signé de la clé de l'utilisateur, **hors Google Play**).
- Google refuse la connexion dans une WebView : politique OAuth sur les agents utilisateurs intégrés (page modifiée le 2026-08-05) et, pour GIS, « les WebViews Android et iOS ne sont pas prises en charge pour la connexion » (page du 2026-09-01). La voie documentée pour une application Android est Credential Manager (guide du 2026-10-16).
- Pour un jeton d'identité, Google dit que `email` ne fait foi que pour une adresse `@gmail.com` (ou un domaine Workspace) et que `sub` est la clé (« jamais réutilisé »).
- `lcobucci/jwt` 5.6.1 est déjà installée (Lexik) ; aucune bibliothèque Google, Guzzle ni `firebase/php-jwt`.
- Aucune clé étrangère entre tables ; `UserOwnedDataCoverageTest` impose la purge de toute colonne qui désigne un utilisateur ([0021](0021-gestion-des-comptes-par-l-administrateur.md) §10).
- La CSP du front n'autorise aucun site externe (`repas-front/docker/Caddyfile`).

## Décision

1. **Une seule méthode de preuve** : un *jeton d'identité* (JWT RS256) signé par Google, posté à `POST /api/auth/google` (corps JSON `{credential}`). Pas de flux par code, donc **aucun *client secret***.
2. **Web** : Google Identity Services (bouton rendu par Google, `ux_mode: popup`). Le script `accounts.google.com/gsi/client` n'est chargé que sur les écrans de connexion et d'inscription, et seulement si la fonction est allumée. La CSP du front gagne exactement les quatre sources de la documentation de Google (`script-src`, `frame-src`, `connect-src`, `style-src` vers `accounts.google.com/gsi/…`).
3. **Application** : Credential Manager (`GetSignInWithGoogleOption`) en natif ; le jeton est remis à la page par le pont `repasNative` (`google.signIn` puis `google.result` ou `google.error`) et c'est **la page** qui le poste au back. Aucune connexion Google n'est jamais tentée dans une WebView ; le script GIS n'y est pas chargé. Le pont est annoncé par `app.info.google`.
4. **Routes** : `POST /api/auth/google/nonce` (renvoie `{nonce, clientId}`) et `POST /api/auth/google`, sous `/api/auth/` (firewall `auth` existant). Publiques, plafonnées par adresse IP (`google_nonce`, `google_sign_in`, `429` + `Retry-After`). Réponse de la seconde : `200 {token}` + cookie `repas_refresh`, identique à `/api/auth/refresh`. Ces routes complètent la liste des routes publiques de [0008](0008-auth-session-et-jwt.md) §4 et [0007](0007-api-rest-v1.md) §14 (hors pare-feu, [0015](0015-refresh-token-du-spa.md) §10). Le handler contrôle lui-même le statut (le pare-feu `auth` n'applique pas `UserChecker`).
5. **Interrupteur** : la variable `GOOGLE_CLIENT_ID` (ID client Web, public) active la fonction ; vide ou absente, les deux routes répondent `404 GOOGLE_DISABLED` et le front cache le bouton. Déclarée explicitement dans `deploy/compose.yaml` avec une valeur par défaut vide ; l'allumer est un geste humain, après un déploiement stable.
6. **Vérification du jeton** derrière un port de domaine `IdentityTokenVerifier`. Le back **refuse** (réponse unique `401 Invalid Google credential.`) sauf si : signature RS256 valide par la clé du `kid` (algorithme épinglé) ; `iss` = `https://accounts.google.com` ou `accounts.google.com` ; `aud` = `GOOGLE_CLIENT_ID` (un seul) ; `exp` présent et non dépassé (marge de 30 s) ; `sub` non vide ; `email` présent et `email_verified` **égal au booléen `true`** ; `nonce` émis par le serveur, non expiré (5 min) et **consommé** (usage unique, magasin dans le cache de l'application, sans SQL).
7. **Bibliothèque** : `lcobucci/jwt` (déjà installée ; la déclarer explicitement dans `composer.json`) pour la signature et les contrôles de revendications ; un adaptateur `GoogleSigningKeys` de l'infrastructure lit les clés publiques de Google (client HTTP Symfony, cache selon `Cache-Control` borné entre 10 min et 24 h, un rechargement au plus toutes les 5 min sur `kid` inconnu, échec fermé, délai de 3 s). **Aucun nouveau paquet** `composer`. `google/apiclient` et `google/auth` sont écartés (§ Alternatives).
8. **Identité** : modèle `UserIdentity` (`id`, `userId`, `provider`, `subject`, `createdAt`) et table `user_identity`, clés uniques `(provider, subject)` et `(user_id, provider)`, **sans clé étrangère**. La clé est le `sub` ; l'e-mail ne sert qu'à retrouver un compte existant **à la première connexion**. Migration additive (compatible avec la version précédente du code, [0017](0017-deploiement-et-releases.md) règle 6). Le contexte `User` reste seul concerné : aucun nouveau port `Shared`, Deptrac inchangé ([0013](0013-agregats-references-par-identifiant.md)).
9. **Politique de compte** (la logique vit dans le domaine, `ExternalIdentityResolver`) :
   - `sub` déjà lié : connexion à ce compte, s'il est `ACTIVE` ; `DISABLED` ou `PENDING` : `401` unique, rien d'écrit.
   - `sub` inconnu, aucun compte à cette adresse : **création** `ACTIVE`, `ROLE_USER`, sans confirmation par e-mail (Google l'a faite), mot de passe verrouillé.
   - `sub` inconnu, compte existant à cette adresse : **liaison automatique seulement si l'adresse est en `@gmail.com` ou `@googlemail.com`** (cas où Google fait foi) ; sinon `409 ACCOUNT_EXISTS_WITH_PASSWORD`, rien d'écrit.
   - Liaison à un compte `PENDING` : le compte est activé, son lien de confirmation supprimé, et **son mot de passe est verrouillé** (la version des identifiants monte) : il a pu être posé par un tiers ([0020](0020-confirmation-d-adresse-email.md) §10). Un compte `DISABLED` n'est jamais lié.
   - Google n'accorde jamais de rôle d'administrateur. Le compte protégé n'a aucun traitement spécial ([0021](0021-gestion-des-comptes-par-l-administrateur.md) §3 reste vrai).
   Les décisions D1 (création et statut), D2 (étendue de la liaison), D3 (mot de passe facultatif), D4 (vérification de marque) et D5 (iPhone) de l'analyse sont à confirmer par le propriétaire avant le passage à `Accepted`.
10. **Mot de passe verrouillé** : la valeur `User::NO_PASSWORD = '!'` n'est jamais un hachage valide (`password_verify` renvoie faux). `User::hasPassword()` la reconnaît. Aucune colonne de plus dans `user`. Poser un premier mot de passe sans l'ancien est le ticket facultatif AUTH-014 ; sinon « mot de passe oublié » (`repas-front#31`).
11. **Session** : la connexion Google ouvre une famille de refresh tokens **dans la transaction du handler** (service de domaine `RefreshTokenIssuer` extrait de `IssueRefreshTokenHandler`) ; le secret du cookie naît dans le controller et seul son hachage traverse le bus ([0015](0015-refresh-token-du-spa.md) §1). `SignInWithGoogle` renvoie l'identifiant de connexion (exception de [0015](0015-refresh-token-du-spa.md) §12, même raison). Le JWT porte `cv` comme d'habitude.
12. **Suppression du compte** : `DeleteUserHandler` supprime les liens ; `UserIdentity::class => ['userId']` entre dans `UserOwnedDataCoverageTest` ; budget `supprimer-compte` mesuré et figé (18 aujourd'hui).
13. **Vie privée** : Repas ne retient que le `sub` (et l'adresse déjà retenue) ; il ignore le nom, la photo et le jeton, qu'il ne journalise ni ne garde. Portées demandées : `openid email profile`, non sensibles (aucune vérification Google obligatoire ; la vérification de marque n'est nécessaire que pour afficher le nom et un logo). Une section « Connexion avec Google » est ajoutée à la page des conditions d'utilisation, rendue publique.
14. **Aucun secret dans le dépôt public, ni dans le chat** : pas de *client secret*, pas de clé de signature, pas d'ID client dans le dépôt ; l'empreinte SHA-1 du certificat de publication est publique mais saisie par l'utilisateur lui-même. **L'empreinte de la clé de débogage publique n'est jamais enregistrée dans le projet Google de production** (un second projet « essai » sert aux essais de débogage).

## Conséquences

- Positives : un proche se connecte en deux touches (bouton, compte) au lieu d'une douzaine de gestes ; aucun mot de passe à retenir ; l'adresse est prouvée par Google ; aucune dépendance de plus à `composer` ; la fonction se coupe d'une variable ; le web et l'application partagent la même route et la même politique.
- Négatives / coûts : dépendance à Google (compte, disponibilité, client OAuth supprimé après six mois d'inactivité) ; un compte créé par Google n'a pas de mot de passe, donc **aucune connexion si la fonction est coupée** tant que « mot de passe oublié » (ou AUTH-014) n'existe pas ; services Google Play requis sur le téléphone (Android hors Google : le mot de passe reste) ; trois bibliothèques de plus dans la coquille Android et un APK plus lourd (à mesurer) ; la CSP du front est élargie à `accounts.google.com/gsi/…` ; iPhone/Safari (mode `redirect`) hors du premier lot ; les variantes d'une même adresse Gmail (`pre.nom` et `prenom`) peuvent créer un doublon ; on ne relie pas les adresses hors Gmail déjà inscrites ; un adaptateur de clés (~100 lignes) à maintenir.
- Garde-fou : tests du domaine, des handlers (doubles en mémoire), de l'adaptateur de vérification avec de vraies signatures RSA générées dans le test (jeton `HS256` forgé, mauvaise audience, expiré, sans `exp`, `email_verified` faux ou textuel, nonce rejoué), du dépôt (base réelle), des controllers (clés JSON, 401, 404, 409, 429) et du parcours complet avec les vrais bus ; `UserOwnedDataCoverageTest` ; budgets SQL ; `make openapi-check` ; `make deploy-smoke` (fonction coupée par défaut) ; test de glossaire du front ; recette sur téléphone (AUTH-013).

## Alternatives écartées

- **Chrome Custom Tab + lien d'application** pour l'application : les cookies seraient ceux de Chrome ; il faudrait un code de passage à usage unique (table, route) et un changement d'application à chaque connexion.
- **Flux OAuth par code côté serveur** : secret à poser et à renouveler, `state`, PKCE, route de retour, sans régler le problème de la WebView.
- **Connexion Google dans la WebView** (GIS ou OAuth) : refusée par Google (`disallowed_useragent`, « WebViews non prises en charge »).
- **`google/apiclient` / `google/auth` / `firebase/php-jwt` / `web-token/jwt-library`** : de 1 à environ 10 paquets de plus à suivre ([0014](0014-montee-de-versions-et-dependabot.md)), une deuxième ou troisième bibliothèque JWT à côté de celle de Lexik, et aucune ne contrôle le `nonce` ni `email_verified` à notre place.
- **Vérification de signature écrite à la main** : jamais.
- **Lier toute adresse « vérifiée par Google »** : Google ne fait foi que pour Gmail ; les domaines recyclés permettent la prise de contrôle (Truffle Security, 2025).
- **Un compte `PENDING` à la création par Google avec activation par l'administrateur** : demande une notification à l'administrateur qui n'existe pas, alors que l'inscription par e-mail est déjà ouverte à tous.
- **Lien magique par e-mail, passkeys, mot de passe partagé, Sign in with Apple** : voir l'analyse (§ 7). Le lien magique est l'alternative sans Google la plus simple, mais elle impose d'aller à la messagerie à chaque connexion.
- **Nonce sans état (HMAC)** ou **table `login_nonce`** : le premier est rejouable 5 minutes, la seconde demande une migration et une purge pour un secret de 5 minutes ; le cache de l'application suffit (même machine unique que les plafonds de débit).
- **Colonne `user.google_sub`** : un seul fournisseur possible, une contrainte de plus sur la table la plus sensible ; la table d'identités garde `user` intacte.

## Décisions du propriétaire (2026-10-19, ticket AUTH-001)

| # | Décision | Réponse |
|---|---|---|
| D1 | Première connexion Google : compte `ACTIVE` tout de suite, sans activation par l'administrateur | **oui** |
| D2 | Liaison automatique d'un compte existant | **seulement pour les adresses Gmail** (les autres gardent le mot de passe) |
| D3 | Comptes créés par Google sans mot de passe | **accepté, mais la fonction ne s'ouvre à tous qu'après « mot de passe oublié »** (issue #31, à lancer après le redémarrage de session du 2026-10-19) ; AUTH-014 reste facultatif |
| D4 | Vérification de marque Google (nom « Repas » affiché) | **non** au premier lot (« je te fais confiance ») ; la page publique de conditions est faite quand même (AUTH-010) |
| D5 | iPhone | **hors du premier lot** ; en revanche l'application Android doit garder un **tronc commun Kotlin** réutilisable (AUTH-015), pour sortir sur iPhone un jour sans tout réécrire |

Conséquence de D5 : les services propres à la plateforme (connexion Google, stockage, ouverture d'un lien) sont derrière des interfaces ; la logique sans dépendance Android (validation des messages du pont, règles de navigation, formes d'adresses) est isolée dans un module Kotlin pur. Aucun code iOS n'est écrit.

## Note du 2026-10-28 : le mot de passe verrouillé existe déjà dans le code

L'inscription par la seule adresse ([0020](0020-confirmation-d-adresse-email.md), amendement du 2026-10-28) a posé ce que la décision 10 décrit : `User::NO_PASSWORD = '!'`, `User::hasPassword()` et `User::lockPassword()` (qui retire le mot de passe d'un compte et fait monter la version des identifiants), ainsi que `User::DEFAULT_SERVING` (4, le nombre de personnes d'un compte créé sans l'avoir dit). Un compte `PENDING` n'a plus de mot de passe choisi avant la preuve de sa boîte : la liaison à un compte `PENDING` (décision 8 du tableau d'analyse) n'a plus à verrouiller un mot de passe qui n'existe pas, mais le fait sans erreur (`lockPassword()` est sans effet sur un compte qui n'en a pas). AUTH-006 réutilise ces trois éléments au lieu de les créer.
