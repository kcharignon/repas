# AUTH-007 — Route `POST /api/auth/google` : JWT, cookie, plafond, documentation, parcours complet

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : M
- **Dépend de** : AUTH-006
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 3.3, 4.3 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décisions 4 et 11 ; ADR 0015 (surtout §§ 1, 8, 10) ; `src/User/Infrastructure/Http/Api/RefreshToken/RefreshTokenApiController.php` (modèle exact : JWT, cookie, `Cache-Control: no-store`) ; skill `repas-dev` § 3.2.

## Contexte

La route qui reçoit le jeton de Google et répond comme la connexion par mot de passe : un JWT d'une heure dans le corps, le cookie `repas_refresh` pour la suite. Elle est publique (le firewall `^/api/auth` est `security: false`, ADR 0015 §10) : sa seule protection est la vérification du jeton et le plafond de débit.

## Portée

1. `Infrastructure/Http/Api/GoogleSignIn/GoogleSignInApiController` : `POST /api/auth/google` (`#[Route]` sur `__invoke`) ; corps JSON `{"credential": "<jeton>"}` par `GoogleSignInPayload` (`#[MapRequestPayload(validationFailedStatusCode: 422)]`, chaîne de 1 à 4096 caractères, `Content-Type: application/json` exigé) ; `#[RequiresGoogleSignIn]` (AUTH-004) ; génère le secret du cookie (`RefreshTokenCookie::generateSecret()`), dispatche `SignInWithGoogleCommand(credential, RefreshToken::hash($secret))`, charge l'utilisateur (`UserProvider::loadUserByIdentifier`), crée le JWT (`JWTTokenManagerInterface::create`), pose le cookie, répond `200 {"token": "..."}` avec `Cache-Control: no-store`. Aucune logique métier dans le controller.
2. Limiteur `google_sign_in` (20 par heure et par adresse IP) : `rate_limiter.yaml` (+ pool de test), `GoogleSignInRateLimitListener` avant la validation du corps (les requêtes invalides comptent), `429` + `Retry-After`.
2 bis. **Dernière connexion** (ADR 0021, amendement du 2026-10-20) : après le succès (compte chargé, JWT créé), le controller appelle `UserLoginTracker::authenticated($user)`, comme `RefreshTokenApiController` et `RecordUserLoginOnLogin` : c'est le point d'entrée unique, sans nouveau cas d'usage. Un refus (`401`, `409`) n'appelle rien ; un échec du suivi n'échoue pas la connexion. Test dans `GoogleSignInFlowTest` : la date apparaît dans `GET /api/v1/users` après une connexion Google, et rien n'est écrit sur un refus.
3. Erreurs : `IdentityException` → `401` (`{"error":{"message":"Invalid Google credential."}}`, aucune autre précision) ou `409` (`ACCOUNT_EXISTS_WITH_PASSWORD`, `ALREADY_LINKED`) via le listener d'API existant ; un jeton refusé n'efface pas de cookie (rien n'a été posé).
4. Documentation OpenAPI complète (`#[OA\Post]`, `#[OA\Tag(name: 'Auth')]`, réponses `200`, `401`, `404`, `409`, `422`, `429`, en-tête `Set-Cookie`) ; `make openapi`, diff relu ; `make api-doc-validate api-doc-coverage`.
5. Mise à jour des décisions écrites : ADR 0008 §4 et ADR 0007 §14 (deux routes publiques de plus, hors pare-feu) ; `CLAUDE.md` (section API : une ligne sur `POST /api/auth/google` et `/nonce`) ; skill `repas-dev`.
6. **Parcours complet** `GoogleSignInFlowTest` avec les vrais bus, les vraies sessions et un faux `IdentityTokenVerifier` (service remplacé en environnement de test) :
   création → `200` + cookie, le JWT ouvre `GET /api/v1/me`, le cookie ouvre `POST /api/auth/refresh` ; reconnexion du même `sub` ; liaison d'un compte Gmail existant dont le mot de passe marche encore ; refus `409` d'un compte non Gmail ; compte désactivé : `401` et `GET /api/v1/me` impossible ; suppression du compte par l'administrateur puis nouvelle connexion : un compte neuf ; nonce rejoué : `401`.

## Hors portée

L'écran, le bouton, le pont Android ; la variable de déploiement (AUTH-008) ; l'élargissement de D2.

## Critères d'acceptation

- Étant donné un jeton valide d'un nouveau proche (faux vérificateur), quand `POST /api/auth/google` est appelé avec `Content-Type: application/json`, alors `200`, un `token` JWT valable et un `Set-Cookie: repas_refresh` avec `HttpOnly`, `SameSite=Strict`, `Path=/api/auth`.
- Étant donné `Content-Type: text/plain` ou un formulaire, alors la requête est refusée sans effet (pas de connexion entre sites).
- Étant donné `GOOGLE_CLIENT_ID` vide, alors `404 GOOGLE_DISABLED` avant toute lecture du corps.
- Étant donné un jeton refusé pour n'importe quelle raison (signature, audience, nonce, compte désactivé), alors la même réponse `401` au caractère près.
- Étant donné 21 appels en une heure depuis la même adresse, alors le 21e répond `429` + `Retry-After`.
- Le JWT émis porte `cv` (révocation par mot de passe) et le cookie ouvre une famille de refresh tokens comme `login_check`.
- `make openapi-check` vert ; le corps de réponse ne contient ni l'adresse, ni le `sub`, ni le jeton.

## Tests exigés

Controller avec `MockCommandBus` (commande construite avec le hachage, pas le secret ; statut, clés JSON, 401, 404, 409, 422, 429) ; test d'énumération des routes `google` (attribut + `429` documenté + limiteur) ; parcours complet décrit au point 6 ; budgets inchangés (déjà couverts par AUTH-006).

## Definition of Done

`make test`, `make quality` (dont `openapi-check`), diff de `docs/api/openapi.json` relu, ADR/CLAUDE.md/skill à jour. Le front (AUTH-009) fige ce contrat par `make api-update ref=<sha>` après la fusion.

## Pièges connus

- Le **secret du cookie ne traverse jamais le bus** : le controller calcule le hachage (ADR 0015 §1). Ne pas le journaliser, ne pas le mettre dans la commande.
- `RefreshTokenCookie` pose `Secure` partout sauf en test ; ne pas dupliquer la logique.
- Le pare-feu `auth` est `security: false` : pas de `#[IsGranted]`, pas d'utilisateur de session ; ne pas ajouter la route dans `access_control` (inutile).
- Ne jamais renvoyer au front l'adresse ou le `sub` de Google : il appelle `GET /api/v1/me` après la connexion.
- CORS : la route est appelée depuis l'origine du site ; rien à autoriser en plus ; un jeton Google ne doit jamais être accepté en `GET` ni dans l'URL.
- Corps limité : une chaîne de 4096 caractères au plus (un jeton de Google en fait environ 1 000 à 1 300).
- Le test d'un controller est obligatoire et se nomme `GoogleSignInApiControllerTest` (règle PHPStan).
