# 0008 — Authentification : session pour le web, JWT stateless pour `/api`

- **Statut** : Accepted ; la partie « session pour le web » est caduque depuis [0018](0018-extinction-de-twig.md) (plus de firewall `main`, de form login ni de `remember_me`), le JWT stateless est inchangé
- **Date** : 2026-10-12
- **Liens** : [0007](0007-api-rest-v1.md), branche `feat/api-rest` (commit `1b7d649`)

## Contexte

`config/packages/security.yaml` : provider `user_model_provider` (`UserProvider`), firewall `main` lazy avec `AuthenticatorEmail` (form login + CSRF), `remember_me` (7 jours), `UserChecker`, logout `/logout`. Pas de `access_control` : l'autorisation est faite par `#[IsGranted]` et par `OwnerVoter` (`HIMSELF`, `INGREDIENT_OWNER`, `SHOPPING_LIST_OWNER`, `RECIPE_OWNER`, `MEAL_OWNER`).

La branche `feat/api-rest` (un commit WIP, base ancienne) ajoute `lexik/jwt-authentication-bundle`, un firewall `login` (`^/api/login`, `json_login` vers `/api/login_check`) et un firewall `api` (`^/api`, stateless, `jwt: ~`). `config/jwt/*.pem` est git-ignoré.

## Décision

1. Les endpoints web existants (Twig, AJAX en fragments HTML) continuent d'utiliser la session et le firewall `main`, **sans changement de comportement**.
2. Les endpoints sous `/api` sont **stateless** et authentifiés par **JWT** (`lexik/jwt-authentication-bundle`).
3. Deux firewalls dédiés, déclarés **avant** `main` : `login` (`^/api/login`, stateless, `json_login`, `check_path: /api/login_check`) et `api` (`^/api`, stateless, `jwt: ~`).
4. `access_control` : `^/api/v1/health`, `^/api/login` et `^/api/doc` (Swagger UI et `doc.json`, qu'un navigateur ne peut pas appeler avec un en-tête `Authorization`) en `PUBLIC_ACCESS`, ainsi que `POST ^/api/v1/users` (inscription, [0007](0007-api-rest-v1.md) §14) , `POST ^/api/v1/users/email-verification/(send|confirm)` (confirmation d'adresse, [0020](0020-confirmation-d-adresse-email.md)) et `POST ^/api/v1/users/password-reset/(send|confirm)` (mot de passe oublié, [0026](0026-mot-de-passe-oublie.md)), le reste de `^/api` en `ROLE_USER`. Les rôles plus fins (admin, propriétaire) restent portés par `#[IsGranted]` et les voters, communs au web et à l'API.
5. Durée de vie du token : 3600 s. Le renouvellement sans ressaisie du mot de passe est un refresh token en cookie `HttpOnly` à rotation : voir [0015](0015-refresh-token-du-spa.md).
6. Les clés `config/jwt/private.pem` et `public.pem` ne sont jamais commitées. Génération : `make jwt-keys` (`lexik:jwt:generate-keypair --skip-if-exists`, appelé par `make install` et par la CI). La passphrase de dev est dans `.env` ; en production, la surcharger (`JWT_PASSPHRASE` dans `.env.local` ou une vraie variable d'environnement) **avant** de générer les clés de production.
7. Les échecs d'authentification (token absent, invalide, expiré, identifiants faux) répondent `401` + `{"error":{"message":"..."}}` + `WWW-Authenticate: Bearer`, via `ApiAuthenticationFailureListener`. Les messages sont génériques : un compte désactivé, un mot de passe faux et un compte inconnu donnent la même réponse `Invalid credentials.`. Le refus d'accès d'un utilisateur authentifié (`403`) est mis en forme par `ApiExceptionListener` ([0007](0007-api-rest-v1.md)). Exception : un compte dont l'adresse email n'est pas confirmée, avec le bon mot de passe, reçoit `403 EMAIL_NOT_VERIFIED` ([0020](0020-confirmation-d-adresse-email.md) §6).

8. Le firewall `login` et le firewall `api` utilisent le même provider (`user_model_provider`) et le même `UserChecker` que `main` : un compte désactivé ne peut obtenir ni utiliser un token (un administrateur désactive ou supprime un compte : [0021](0021-gestion-des-comptes-par-l-administrateur.md)).
9. Environnement de test : `JwtAuthenticationTest` s'appuie sur de vrais endpoints, sans route factice : `GET /api/v1/me` (`ROLE_USER`) et `GET /api/v1/conversions` (`ROLE_ADMIN`). Les routes d'essai `/api/v1/_probe/*` qui tenaient ce rôle tant que l'API n'avait pas ses propres endpoints sont supprimées.
10. CORS : `nelmio/cors-bundle`, limité à `^/api/` (aucune origine autorisée ailleurs), origines autorisées via `CORS_ALLOW_ORIGIN`, en-têtes `Content-Type` et `Authorization`.
11. `ROLE_USER` est le plancher d'un endpoint API. Un endpoint qui modifie une ressource appartenant à un utilisateur doit vérifier la propriété (voter) avant d'être exposé : les trous existants sont listés dans `docs/migration-roadmap.md` et corrigés avant la phase des endpoints d'écriture.

12. **Plafonds de débit** (`symfony/rate-limiter`, `config/packages/rate_limiter.yaml`). `login_throttling` sur les firewalls `login` (JSON) et `main` (formulaire) : 5 échecs par 15 minutes pour un couple IP + email (25 pour une même IP), le compteur ne distingue pas un compte inconnu d'un compte existant, une connexion réussie le remet à zéro. Au-delà : `429` + `Retry-After` en secondes (`ApiAuthenticationFailureListener`), même avec le bon mot de passe ; sur le formulaire Twig, le message « Trop de tentatives… ». L'inscription publique est plafonnée à 10 par heure et par IP (`RegisterNewUserRateLimitListener`, limiteur `registration`) : `429` + `Retry-After`. Le renvoi et la confirmation du lien d'adresse email partagent un plafond de 20 par heure et par IP (`EmailVerificationRateLimitListener`, limiteur `email_verification`). Le mot de passe oublié a son propre plafond de 20 par heure et par IP, partagé par ses deux routes (`PasswordResetRateLimitListener`, limiteur `password_reset`, [0026](0026-mot-de-passe-oublie.md)). Les écritures du drive (60 par minute) et la lecture des plans et estimations (120 par minute) sont plafonnées **par utilisateur du jeton** (`DriveRateLimitListener`, limiteurs `drive_write` et `drive_read`, DRV-615) : `429` `DRIVE_RATE_LIMITED` + `Retry-After`. L'IP vient de `Request::getClientIp()` : derrière un reverse proxy, déclarer `SYMFONY_TRUSTED_PROXIES`, sinon tous les clients partagent le compteur du proxy.
13. **Révocation à la modification du mot de passe** (`JwtCredentialsVersionListener`) : chaque JWT porte `cv`, un HMAC tronqué (clé `kernel.secret`) de l'identifiant et de la **version des identifiants** de l'utilisateur (colonne `user.credentials_version`, augmentée par `User::changePassword`). À chaque requête l'utilisateur est relu en base et sa version doit correspondre, sinon `401 Invalid token.` : changer de mot de passe, par l'API comme par Twig, révoque tous les jetons déjà émis. La version n'est volontairement pas le hash : Symfony émet le jeton **avant** de recalculer le hash à la connexion (nouvel algorithme ou coût, `UserProvider::upgradePassword`, désormais enregistré), une empreinte du hash révoquerait aussitôt le jeton qu'on vient de donner. Un jeton sans `cv` (émis avant cette règle) est refusé. Les autres modifications (nombre de personnes…) ne révoquent rien.
14. **Emails** : comparés sans tenir compte de la casse ni des espaces autour (`User::normalizeEmail()`, appliqué à la création, à la recherche et à `IsEmailAlreadyUsed`), stockés en minuscules (migration `Version20261014140000`, qui s'arrête s'il existe deux emails qui ne diffèrent que par la casse). Deux inscriptions simultanées du même email : la seconde échoue sur la contrainte d'unicité, rattrapée en `409` (`UserException::emailAlreadyUsed`).

## Conséquences

- Positives : le web ne change pas ; le front mobile/SPA n'a pas besoin de cookies ni de CSRF.
- Coûts : deux mécanismes d'auth à comprendre ; gestion des clés par environnement ; un jeton n'est révoqué que par un changement de mot de passe (§13) ou son expiration (1 h), le refresh token ([0015](0015-refresh-token-du-spa.md)) est révoqué par le même changement de mot de passe.
- Garde-fou : tests fonctionnels (sans token → 401, avec token valide → accès, `/login` web toujours fonctionnel).

## Alternatives écartées

- Session cookie pour l'API : couple le front au domaine/sous-domaine, nécessite CSRF et cookies cross-origin.
- Remplacer le form login par JWT partout : casse l'existant sans bénéfice immédiat.
