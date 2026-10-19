# AUTH-003 — Vérificateur de jeton d'identité Google (port, adaptateur, clés en cache)

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui (vraies signatures RSA générées dans les tests, aucun appel réseau)
- **Statut** : à faire
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
