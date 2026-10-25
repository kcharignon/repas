# VOX-202 — Liaison de compte : serveur d'autorisation OAuth 2.0 (code + PKCE)

- **Dépôt** : back
- **Jalon** : M2 Back `Voice`
- **Taille** : L
- **Dépend de** : VOX-201
- **Faisable sans téléphone** : oui
- **Statut** : fait (PR #265, 2026-10-25)
- **Lire d'abord** : [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 6, 8 ; [`../alexa.md`](../alexa.md) §§ 1, 3 ; page Amazon « Configure authorization code grant » (relire au moment du ticket : requête de jeton, authentification du client) ; ADR 0015 (refresh token, rotation) ; ADR 0008 ; `RotateRefreshToken` ; **skill `repas-dev`**. Effort conseillé : **xhigh** (sécurité).

## Contexte

Alexa doit pouvoir écrire dans la liste d'un utilisateur précis, sans connaître son mot de passe, avec un droit que l'utilisateur retire à tout moment.

## Portée

1. **Émission du code** : `POST /api/v1/voice/alexa/authorize` (JWT), corps `{clientId, redirectUri, state, codeChallenge, codeChallengeMethod}` ; contrôle `clientId` = `ALEXA_OAUTH_CLIENT_ID`, `redirectUri` ∈ `ALEXA_REDIRECT_URIS` (comparaison exacte), `S256` obligatoire ; réponse `200 {redirectUrl}` = `redirectUri?code=…&state=…` ; code de 32 octets, valable 10 minutes, usage unique, **stocké haché**.
2. **Échange** : `POST /api/voice/oauth/token` (public, `application/x-www-form-urlencoded`) : `grant_type=authorization_code` (`code`, `redirect_uri`, `code_verifier`) ou `refresh_token` ; client authentifié par `client_id`/`client_secret` (corps ou `Authorization: Basic`), comparé en temps constant au haché de `ALEXA_OAUTH_CLIENT_SECRET_HASH`. Réponse `{access_token, token_type: "Bearer", expires_in: 3600, refresh_token}`, `Cache-Control: no-store`. Rotation du jeton de rafraîchissement ; un jeton de rafraîchissement rejoué révoque la liaison. Les refus d'OAuth suivent la RFC 6749 5.2 (`{"error":"invalid_grant"}`, 400/401, `Cache-Control: no-store`) ; l'interrupteur coupé (`404 VOICE_DISABLED`), le plafond (`429 VOICE_RATE_LIMITED`, avec `Retry-After` et `Cache-Control: no-store` sur cette route) et une panne inattendue (`500`) gardent le format du dépôt `{error:{message}}` (ADR 0007).
3. **Liaison** : `VoiceLink` créé à la première émission (un utilisateur peut avoir plusieurs liaisons, une par `alexa_user_id` si connu ; sinon une seule `alexa`), `last_used_at` mis à jour par le webhook.
4. **Gestion** : `GET /api/v1/voice/links` (`{member,totalItems}` : `id`, `provider`, `createdAt`, `lastUsedAt`) et `DELETE /api/v1/voice/links/{id}` (`204`, propriétaire seul : `403` sinon via `OwnerVoter`), qui révoque aussi les jetons.
5. **Requête interne** `ResolveAccessGrant` : jeton d'accès → `{userId, linkId}` ou rien (haché, non expiré, liaison non révoquée, compte **actif**).
6. Plafonds : `voice_token` (30 par heure et par IP), `voice_authorize` (20 par heure et par utilisateur). `404` quand l'interrupteur est coupé.
7. OpenAPI, `make openapi`, scénarios `WriteBudget`.

## Critères d'acceptation

- Parcours complet en test : autoriser → échanger → rafraîchir → résoudre → délier → le jeton ne résout plus.
- `redirectUri` inconnue, `S256` absent, `code_verifier` faux, code expiré ou rejoué, client faux : refus sans indication utile.
- Un jeton de rafraîchissement rejoué révoque la liaison. Un compte désactivé ou supprimé ne résout plus.
- Aucun jeton ni code en clair en base ni dans les journaux (test qui lit la base).

## Tests exigés

Handlers en unitaire ; dépôts sur base réelle ; contrôleurs avec `MockCommandBus`/`MockQueryBus` (statuts, clés JSON, en-têtes) ; test d'énumération des routes `/api/voice/*` (interrupteur, plafond, OpenAPI).

## Pièges connus

- Le firewall `^/api/voice` est en `security: false` comme `^/api/auth` : la route est publique par construction, donc plafond et authentification du client sont la seule protection.
- `redirectUri` : comparer **exactement**, jamais par préfixe ; ne rediriger que vers les valeurs configurées.
- Ne pas confondre avec les JWT : un jeton Alexa ne doit jamais être accepté par le firewall `api`.
