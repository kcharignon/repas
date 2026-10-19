# AUTH-003 — Vérificateur de jeton d'identité Google (port, adaptateur, clés en cache)

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui (vraies signatures RSA générées dans les tests, aucun appel réseau)
- **Statut** : fait (PR #220, 2026-10-20) ; rien n'est branché sur une route, la fonction reste coupée (`GOOGLE_CLIENT_ID` vide)
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 3.1, 3.2, 3.4 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décisions 6 et 7 ; skill `repas-dev` ; ADR 0001, 0014 ; `src/User/Domain/Interface/EmailVerificationNotifier.php` et `src/User/Infrastructure/Mailer/` (même schéma port / adaptateur).

## Contexte

Le back doit établir, sans rien croire de la page ni de l'application, qu'un jeton d'identité vient de Google, a été émis pour notre ID client, et dit qui est la personne. `lcobucci/jwt` 5.6.1 est déjà dans l'image (exigée par Lexik) ; aucune autre bibliothèque n'est ajoutée. Un essai jetable (2026-10-19, image de production) a montré que `SignedWith(new Sha256(), InMemory::plainText($pem))` accepte un certificat X.509 PEM (forme de `https://www.googleapis.com/oauth2/v1/certs`) comme une clé publique PEM reconstruite d'un JWK, et refuse un jeton `HS256`.

## Portée

1. **Domaine** (`src/User/Domain/`) :
   - `Model/IdentityProvider` (enum, `GOOGLE`) ; `Model/VerifiedIdentity` (valeur immuable : `provider`, `subject`, `email` normalisé par `User::normalizeEmail`, `emailVerified`, `nonce`, `hostedDomain` nullable) avec `emailIsAuthoritative(): bool` = `emailVerified` et domaine de l'adresse (après la **dernière** `@`) égal à `gmail.com` ou `googlemail.com` ;
   - `Interface/IdentityTokenVerifier` : `verify(string $idToken): VerifiedIdentity` (`@throws IdentityException`) ;
   - `Exception/IdentityException` (hérite de `DomainException`) : factory `invalidCredential()` (`401`, message `Invalid Google credential.`).
2. **Infrastructure** (`src/User/Infrastructure/Security/`) :
   - `GoogleSignInSettings` (`%env(GOOGLE_CLIENT_ID)%`, `isEnabled()` = non vide, `clientId()`) ;
   - `GoogleSigningKeys` : télécharge `https://www.googleapis.com/oauth2/v1/certs` (client HTTP Symfony, délai de 3 s), met en cache (`cache.app`) pour la durée de `Cache-Control: max-age` bornée entre 10 min et 24 h, recharge au plus une fois toutes les 5 minutes sur `kid` inconnu, sert un cache périmé de moins de 24 h si Google est injoignable, sinon **échoue fermé** ;
   - `GoogleIdTokenVerifier implements IdentityTokenVerifier` : analyse (`Parser`), exige l'en-tête `kid` et `alg` = `RS256`, puis `SignedWith`, `IssuedBy('accounts.google.com', 'https://accounts.google.com')`, `PermittedFor($clientId)`, `LooseValidAt` (marge 30 s) **et** présence explicite de `exp` et de `iat`, `sub` non vide, `email` non vide, `email_verified` strictement égal au booléen `true`. Tout échec : `IdentityException::invalidCredential()`.
3. **Configuration** : `GOOGLE_CLIENT_ID=` dans `.env` (vide), un ID factice dans `.env.test` et `phpunit.dist.xml` ; `lcobucci/jwt` déclarée dans `composer.json` (`require`, contrainte `^5.6`) : **aucun paquet de plus** dans `composer.lock` (vérifier le diff).
4. **Journal** : un échec écrit un seul message de niveau `warning` avec une **catégorie** (`signature`, `audience`, `issuer`, `expired`, `claims`, `keys`), jamais le jeton, jamais l'adresse. À la recette, la liste des **noms** de revendications présentes (pas leurs valeurs) est journalisée au niveau `info` une fois par jour au plus : elle sert à lever les inconnues `nbf` et `azp` (analyse § 10).

## Hors portée

Le nonce (AUTH-004), les routes (AUTH-004, AUTH-007), la politique de compte (AUTH-006), toute lecture de `hd` (D2 « Gmail ou Workspace »).

## Critères d'acceptation

- Étant donné un jeton RS256 signé par une clé de test, avec `iss`, `aud`, `exp`, `sub`, `email`, `email_verified: true`, quand `verify` est appelé avec les clés de test, alors il renvoie la `VerifiedIdentity` correspondante.
- Étant donné un jeton `HS256` forgé, `alg: none`, une mauvaise audience, un mauvais émetteur, un jeton expiré, un jeton sans `exp`, sans `sub`, sans `email`, avec `email_verified: false` ou `"true"` (texte), ou un `kid` inconnu, quand `verify` est appelé, alors `IdentityException::invalidCredential()` est levée.
- Étant donné un `kid` inconnu, quand deux jetons sont vérifiés dans la même minute, alors les clés ne sont téléchargées qu'**une** fois.
- Étant donné Google injoignable et des clés en cache de moins de 24 h, quand un jeton valide est vérifié, alors il est accepté ; sans cache, il est refusé.
- Étant donné `a@gmail.com`, `a@googlemail.com`, `a@gmail.com.evil.com`, `gmail.com@evil.com`, `a@GMAIL.COM` et `email_verified` faux, quand `emailIsAuthoritative()` est évalué, alors seuls les deux premiers (et le cinquième, normalisé en minuscules) sont vrais, et seulement si `emailVerified`.
- Le diff de `composer.lock` n'ajoute aucun paquet ; `make audit` est vert.

## Tests exigés

Domaine : `VerifiedIdentity::emailIsAuthoritative()` (cas ci-dessus). Adaptateur (`tests/User/OutputAdapter/` ou `tests/User/Infrastructure/Security/`) : paire de clés RSA générée **dans le test** (`openssl_pkey_new`), jetons construits avec le `Builder` de `lcobucci/jwt`, certificat auto-signé pour la forme PEM, `MockHttpClient` pour les clés (comptage des appels), horloge fixe ; tous les cas ci-dessus, dont les trois du piège `LooseValidAt`. **Aucune clé, aucun jeton réel de Google dans le dépôt**, aucun accès au réseau. Controller : aucun (pas encore de route).

## Definition of Done

Celle du skill `repas-dev` § 4 : `make test`, `make quality`, `make audit` (`composer.*` change) ; PHPStan sans nouvelle entrée de baseline ; Deptrac vert (le domaine n'importe ni `lcobucci` ni Symfony HTTP).

## Pièges connus

- **`StrictValidAt` refuse un jeton sans `nbf`** (vérifié par l'essai) : utiliser `LooseValidAt` **et** exiger `exp` (et `iat`) à part, car `LooseValidAt` accepte un jeton sans ces revendications.
- `aud` d'un jeton Credential Manager est l'ID client **Web** (celui qu'on passe en `serverClientId`), pas l'ID client Android ; ne pas se fier à `azp` (inconnue à lever à la recette).
- `email_verified` doit être un **booléen** : la chaîne `"true"` (anciens jetons de `tokeninfo`) est refusée.
- Épingler l'algorithme : ne jamais choisir le signataire d'après l'en-tête du jeton.
- Les clés PEM de `v1/certs` sont des **certificats** : `InMemory::plainText($pem)` suffit (vérifié) ; si Google retire ce point d'accès, passer au JWKS de `v3/certs` en reconstruisant la clé PEM depuis `n` et `e` (l'essai l'a fait en une quinzaine de lignes) et le dire dans l'ADR.
- Ne pas mettre la clé de Google dans `config/` ni en fixture ; le cache se vide au redéploiement, c'est voulu.
- `lcobucci/jwt` est aussi utilisée par Lexik : ne rien changer à la configuration de Lexik.
- Ne jamais journaliser le jeton, l'adresse ni le nonce (rappel de la règle du dépôt public : pas de donnée personnelle).

## Réalisation (PR #220, 2026-10-20)

**Fait comme demandé** : domaine (`IdentityProvider`, `VerifiedIdentity`, `IdentityTokenVerifier`, `IdentityException::invalidCredential()`), `Security/GoogleSignInSettings`, `Security/GoogleSigningKeys` (+ `GoogleSigningKeysUnavailable`, l'échec fermé), `Security/GoogleIdTokenVerifier`, configuration (`GOOGLE_CLIENT_ID=` vide dans `.env`, `123456-repastest.apps.googleusercontent.com` factice dans `.env.test` et forcé dans `phpunit.dist.xml`), `lcobucci/jwt ^5.6` dans `require` : le diff de `composer.lock` n'est que l'empreinte (`content-hash`), aucun paquet ajouté ni mis à jour. Le port s'aliase tout seul sur `GoogleIdTokenVerifier` (seule implémentation) ; le service n'est encore utilisé par rien, donc retiré du conteneur compilé jusqu'à AUTH-006.

**Tests** : `tests/User/Domain/VerifiedIdentityTest`, `tests/User/Infrastructure/Security/{GoogleIdTokenVerifier,GoogleSigningKeys,GoogleSignInSettings}Test` (172 tests) ; assistant `tests/Helper/Google/GoogleTestKey` (paire RSA par `openssl_pkey_new`, certificat auto-signé, jetons écrits à la main pour les cas que le `Builder` refuse de construire). Le cas nominal est construit par le `Builder` de `lcobucci/jwt`. Faux client HTTP qui compte ses appels et lève une erreur pour toute autre adresse que `oauth2/v1/certs`, `ArrayAdapter`, `MockClock`. Une trentaine de mutations manuelles du code font chacune échouer au moins un test.

**Plus strict que la portée** (aucune ne refuse un jeton conforme à la documentation de Google) :

- `aud` doit être l'ID client **seul** : `[notreID]` passe (même chose qu'une chaîne), une audience de plus est refusée (`PermittedFor` seul l'accepterait) ;
- en-tête `crit` refusé ; `jwk`, `jku`, `x5u`, `x5c` ignorés (test : un jeton qui désigne la clé de l'attaquant est refusé et seule l'adresse de Google est appelée) ;
- jeton de 8 Kio au plus ; `sub` et `email` : chaînes non vides de 255 caractères au plus (taille des colonnes) ; `nonce` et `hd`, s'ils sont là, chaînes non vides ;
- réponse de Google retenue entière ou pas du tout : statut 200, objet non vide, chaque valeur une clé publique RSA PEM d'au moins 2048 bits ; un chemin `file://` est refusé (`openssl` lirait le disque, y compris la clé de Lexik).

**Choix d'interprétation** :

- `VerifiedIdentity::nonce` est `?string` : un jeton sans nonce passe la vérification (le premier critère d'acceptation n'en a pas) ; **AUTH-006 doit refuser `null`** (« faux ou absent »). `hd` est transporté, aucune règle ne le lit (D2).
- Les **5 minutes** espacent tout téléchargement, quelle qu'en soit la raison (`kid` inconnu, clés échues, nouvel essai après une panne) : sans clé utilisable et Google en panne, le refus dure 5 min au plus avant l'essai suivant ; avec des clés de moins de 24 h, elles servent sans attendre 3 s à chaque requête.
- « Cache de moins de 24 h » = clés **téléchargées** il y a moins de 24 h (et non périmées depuis moins de 24 h).
- Catégories : `signature` pour un jeton illisible (dates non numériques comprises), un en-tête refusé, un `kid` inconnu ou une signature fausse ; `claims` pour `exp` ou `iat` absents ; `expired` pour une date hors de la fenêtre (`exp`, `iat`, `nbf`) ; `keys` (avec une raison technique) quand aucune clé n'est utilisable. Fonction coupée : refus `audience` sans appeler Google.
- Le journal `info` des noms de revendications suit la vérification de la **signature** (un jeton bien signé mais refusé ensuite renseigne aussi la recette) ; l'espacement d'un jour est une entrée de `cache.app`.
- `GoogleSigningKeys` écrit aussi un `warning` (raison technique seulement) quand il sert des clés périmées faute de réponse de Google : ce n'est pas un refus, c'est pour qu'une panne longue se voie avant les 24 h.

**Pour la suite** :

- **AUTH-008** : sans Monolog, le journal par défaut de Symfony n'écrit en production que `error` et au-delà (`SHELL_VERBOSITY` absent) : les `warning` de refus et la ligne `info` des noms de revendications **ne se verront pas à la recette** tant que le niveau n'est pas réglé (par exemple `SHELL_VERBOSITY=2` le temps de la recette, à décider).
- **AUTH-004** : `GoogleSignInSettings::isEnabled()` et `clientId()` sont prêts ; `IdentityException` gagne `disabled()`.
- **AUTH-007** : le parcours complet remplace `IdentityTokenVerifier` ; rien dans l'environnement de test n'empêche encore le vrai client HTTP de partir vers Google si `GoogleSigningKeys` y était appelé.
