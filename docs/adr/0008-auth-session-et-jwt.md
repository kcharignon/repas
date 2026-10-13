# 0008 — Authentification : session pour le web, JWT stateless pour `/api`

- **Statut** : Accepted
- **Date** : 2026-10-12
- **Liens** : [0007](0007-api-rest-v1.md), branche `feat/api-rest` (commit `1b7d649`)

## Contexte

`config/packages/security.yaml` : provider `user_model_provider` (`UserProvider`), firewall `main` lazy avec `AuthenticatorEmail` (form login + CSRF), `remember_me` (7 jours), `UserChecker`, logout `/logout`. Pas de `access_control` : l'autorisation est faite par `#[IsGranted]` et par `OwnerVoter` (`HIMSELF`, `INGREDIENT_OWNER`, `SHOPPING_LIST_OWNER`, `RECIPE_OWNER`, `MEAL_OWNER`).

La branche `feat/api-rest` (un commit WIP, base ancienne) ajoute `lexik/jwt-authentication-bundle`, un firewall `login` (`^/api/login`, `json_login` vers `/api/login_check`) et un firewall `api` (`^/api`, stateless, `jwt: ~`). `config/jwt/*.pem` est git-ignoré.

## Décision

1. Les endpoints web existants (Twig, AJAX en fragments HTML) continuent d'utiliser la session et le firewall `main`, **sans changement de comportement**.
2. Les endpoints sous `/api` sont **stateless** et authentifiés par **JWT** (`lexik/jwt-authentication-bundle`).
3. Deux firewalls dédiés, déclarés **avant** `main` : `login` (`^/api/login`, stateless, `json_login`, `check_path: /api/login_check`) et `api` (`^/api`, stateless, `jwt: ~`).
4. `access_control` : `^/api/v1/health`, `^/api/login` et `^/api/doc` (Swagger UI et `doc.json`, qu'un navigateur ne peut pas appeler avec un en-tête `Authorization`) en `PUBLIC_ACCESS`, le reste de `^/api` en `ROLE_USER`. Les rôles plus fins (admin, propriétaire) restent portés par `#[IsGranted]` et les voters, communs au web et à l'API.
5. Durée de vie du token : 3600 s (valeur de la branche WIP). Pas de refresh token dans cette itération ; à traiter par un ADR dédié si le front le demande.
6. Les clés `config/jwt/private.pem` et `public.pem` ne sont jamais commitées. Génération : `make jwt-keys` (`lexik:jwt:generate-keypair --skip-if-exists`, appelé par `make install` et par la CI). La passphrase de dev est dans `.env` ; en production, la surcharger (`JWT_PASSPHRASE` dans `.env.local` ou une vraie variable d'environnement) **avant** de générer les clés de production.
7. Les échecs d'authentification (token absent, invalide, expiré, identifiants faux) répondent `401` + `{"error":{"message":"..."}}` + `WWW-Authenticate: Bearer`, via `ApiAuthenticationFailureListener`. Les messages sont génériques : un compte désactivé, un mot de passe faux et un compte inconnu donnent la même réponse `Invalid credentials.`. Le refus d'accès d'un utilisateur authentifié (`403`) est mis en forme par `ApiExceptionListener` ([0007](0007-api-rest-v1.md)).
8. Le firewall `login` et le firewall `api` utilisent le même provider (`user_model_provider`) et le même `UserChecker` que `main` : un compte désactivé ne peut obtenir ni utiliser un token.
9. Environnement de test : `JwtAuthenticationTest` s'appuie sur de vrais endpoints, sans route factice : `GET /api/v1/me` (`ROLE_USER`) et `GET /api/v1/conversions` (`ROLE_ADMIN`). Les routes d'essai `/api/v1/_probe/*` qui tenaient ce rôle tant que l'API n'avait pas ses propres endpoints sont supprimées.
10. CORS : `nelmio/cors-bundle`, limité à `^/api/` (aucune origine autorisée ailleurs), origines autorisées via `CORS_ALLOW_ORIGIN`, en-têtes `Content-Type` et `Authorization`.
11. `ROLE_USER` est le plancher d'un endpoint API. Un endpoint qui modifie une ressource appartenant à un utilisateur doit vérifier la propriété (voter) avant d'être exposé : les trous existants sont listés dans `docs/migration-roadmap.md` et corrigés avant la phase des endpoints d'écriture.

## Conséquences

- Positives : le web ne change pas ; le front mobile/SPA n'a pas besoin de cookies ni de CSRF.
- Coûts : deux mécanismes d'auth à comprendre ; gestion des clés par environnement ; pas de révocation de token avant expiration.
- Garde-fou : tests fonctionnels (sans token → 401, avec token valide → accès, `/login` web toujours fonctionnel).

## Alternatives écartées

- Session cookie pour l'API : couple le front au domaine/sous-domaine, nécessite CSRF et cookies cross-origin.
- Remplacer le form login par JWT partout : casse l'existant sans bénéfice immédiat.
