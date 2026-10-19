# AUTH-004 — Nonce de connexion à usage unique et route `POST /api/auth/google/nonce`

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : S
- **Dépend de** : AUTH-003 (`GoogleSignInSettings`)
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 3.3, 3.5 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décisions 4 à 6 ; ADR 0015 §12 (une commande qui renvoie une valeur) ; `src/User/Infrastructure/Http/Api/RegisterNewUser/RegisterNewUserRateLimitListener.php` ; `src/Shared/Infrastructure/Http/RequiresDrive.php` et `DriveEnabledListener.php` (modèle de l'interrupteur).

## Contexte

Un jeton d'identité intercepté ne doit pas pouvoir être rejoué, et un jeton doit être lié à *cette* tentative de connexion. Le serveur émet un nonce aléatoire valable 5 minutes ; la page (GIS) ou l'application (Credential Manager) le recopie dans la revendication `nonce` ; le serveur le consomme à la connexion (AUTH-006). Le nonce vit dans le cache de l'application : pas de SQL, pas de migration.

## Portée

1. **Domaine** : port `Interface/LoginNonceStore` (`issue(): string`, `consume(string $nonce): bool`).
2. **Application** : `IssueLoginNonce/IssueLoginNonceCommand` + `IssueLoginNonceHandler` (`#[AsMessageHandler]`), qui **renvoie** le nonce (écrire dans le cache n'est pas une lecture ; exception à « une commande ne renvoie rien », comme `RotateRefreshToken`, [ADR 0015](../../adr/0015-refresh-token-du-spa.md) §12).
3. **Infrastructure** :
   - `Security/CacheLoginNonceStore` : 32 octets aléatoires en base64url (43 caractères) ; clé de cache = SHA-256 du nonce ; valeur = l'instant d'émission ; `consume` lit, **supprime**, puis compare à l'horloge injectée (`ClockInterface`, valable 300 s) ;
   - `Http/RequiresGoogleSignIn` (attribut) + son listener `kernel.controller` : `404` `GOOGLE_DISABLED` (`{error:{message:"GOOGLE_DISABLED"}}`) si `GoogleSignInSettings::isEnabled()` est faux, après la sécurité et avant la validation du corps (comme `#[RequiresDrive]`) ; exception de domaine `IdentityException::disabled()` (`404`) ;
   - `Http/Api/IssueLoginNonce/IssueLoginNonceApiController` : `POST /api/auth/google/nonce`, répond `200 {"nonce": "...", "clientId": "..."}` avec `Cache-Control: no-store` ; documentation `#[OA\…]` ;
   - limiteur `google_nonce` (60 par heure et par adresse IP, `sliding_window`) : `config/packages/rate_limiter.yaml` (+ pool de test), `IssueLoginNonceRateLimitListener`, `429` + `Retry-After`.
4. `make openapi` (diff de `docs/api/openapi.json` relu).

## Hors portée

La connexion elle-même (AUTH-006, AUTH-007). Un nonce stocké en base (écarté par l'ADR 0024).

## Critères d'acceptation

- Étant donné la fonction allumée, quand `POST /api/auth/google/nonce` est appelé sans jeton, alors la réponse est `200`, `nonce` fait 43 caractères `[A-Za-z0-9_-]`, `clientId` est l'ID client configuré, `Cache-Control` contient `no-store`.
- Étant donné deux appels successifs, alors les deux nonces sont différents.
- Étant donné un nonce émis, quand `consume` est appelé deux fois, alors `true` puis `false`.
- Étant donné un nonce émis il y a 301 secondes, quand `consume` est appelé, alors `false`.
- Étant donné un nonce jamais émis, quand `consume` est appelé, alors `false`.
- Étant donné `GOOGLE_CLIENT_ID` vide, quand la route est appelée, alors `404` `GOOGLE_DISABLED` et **aucun** nonce n'est émis.
- Étant donné 61 appels depuis la même adresse en une heure, alors le 61e répond `429` avec `Retry-After`.

## Tests exigés

Handler en unitaire (`LoginNonceStore` en mémoire) ; `CacheLoginNonceStore` avec un `ArrayAdapter` et une `MockClock` (délai de 300 s, usage unique) ; listener d'interrupteur ; controller avec `MockCommandBus` (statut, clés JSON `nonce` et `clientId`, 404, 429) ; `DriveRoutesAreRateLimitedTest`-style : un test énumère les routes `google` et exige l'attribut et la documentation `429`. Pas de test de base : aucune écriture SQL (le scénario `WriteBudget` n'est pas nécessaire ; le dire dans « À relire »).

## Definition of Done

Celle de `repas-dev` § 4 : `make test`, `make quality`, `make openapi` et diff relu. Le test `EnsureControllerAndHandlerHaveTestRule` impose `IssueLoginNonceApiControllerTest`.

## Pièges connus

- Le firewall `^/api/auth` est en `security: false` : ni `access_control`, ni `#[CurrentUser]` ; la route est publique **par construction**, donc le plafond de débit est la seule protection : le listener passe avant tout traitement.
- Lire puis supprimer dans le cache n'est pas atomique : une course de quelques millisecondes entre deux requêtes identiques est acceptée (elle ne profite qu'à qui possède déjà le jeton). Ne pas ajouter de verrou.
- Le cache est vidé à chaque redéploiement (le conteneur reconstruit son cache au démarrage) : un nonce émis juste avant est perdu, l'utilisateur recommence. Le dire dans `docs/production.md` (AUTH-008).
- Ne pas utiliser `cache.rate_limiter` : un pool à part (`cache.app`) évite qu'un vidage des compteurs efface les nonces.
- Le nonce n'est ni un secret durable ni une donnée personnelle, mais il ne doit pas apparaître dans les journaux.
- Ne pas confondre avec le plafond d'inscription : le limiteur est distinct (`google_nonce`).
