# 0018 — Extinction de Twig : le back ne sert plus que l'API

- **Statut** : Accepted
- **Date** : 2026-10-16
- **Liens** : [0007](0007-api-rest-v1.md), [0008](0008-auth-session-et-jwt.md), [0009](0009-strategie-de-migration.md), [0015](0015-refresh-token-du-spa.md), [0017](0017-deploiement-et-releases.md), `docs/front-vuejs-plan.md` §10, dépôt `kcharignon/repas-front`

## Contexte

Le back servait deux interfaces : 48 controllers Twig (session, formulaires, CSRF, ~38 templates, jQuery) et l'API JSON `/api/v1` (JWT). La migration « strangler » ([0009](0009-strategie-de-migration.md)) a fait passer toute lecture par le QueryBus et donné à chaque action de l'application un endpoint API ; le front Vue.js (`repas-front`) couvre chaque écran Twig (`PARITY.md` du front) et les anciennes adresses Twig y sont redirigées.

Le 2026-10-16 : le front a été déployé (`app.kantin.eu`), testé par l'utilisateur sur ses données réelles, puis il a pris la racine du domaine (`kantin.eu`), Twig restant sur `app.kantin.eu` comme filet de sécurité. L'utilisateur a décidé, ce jour-là et explicitement, de débrancher Twig et d'en effacer toute trace du back.

## Décision

1. **Le back ne sert que `/api`.** Plus de page, de template, de formulaire, de session, de CSRF, de « se souvenir de moi » ni de cookie d'authentification : l'authentification est le JWT et le refresh token en cookie `HttpOnly` du SPA ([0008](0008-auth-session-et-jwt.md), [0015](0015-refresh-token-du-spa.md)). Toute autre route répond `404`.
2. **Retirés** : tous les `*ViewController`, `Http/Form`, `Http/View` des trois contextes, `public/js`, `public/css`, le firewall `main` (`AuthenticatorEmail`, `remember_me`, `logout`), `WebDomainExceptionListener`, la configuration `framework.session` / `csrf_protection` / `profiler`, et les paquets `symfony/twig-bundle`, `twig/intl-extra`, `symfony/form`, `symfony/asset`, `symfony/web-profiler-bundle`, `symfony/debug-bundle` (qui exigeait `twig/twig`), `symfony/css-selector`. Plus aucun paquet Twig dans `composer.lock` du projet.
3. **La documentation de l'API reste en JSON** : `GET /api/doc.json` et le contrat versionné `docs/api/openapi.json`. L'interface Swagger HTML (`/api/doc`) dépendait de Twig : elle est retirée.
4. **L'ancien nom de domaine est redirigé** : `LEGACY_HOST` (`app.kantin.eu`) répond `301` vers `APP_HOST` (`kantin.eu`) en conservant le chemin, pour les signets et les liens déjà partagés ([0017](0017-deploiement-et-releases.md)).
5. **Les ADR 0001 à 0017 ne sont pas réécrits** : leurs mentions de Twig décrivent l'état du projet à leur date. Seuls les statuts de 0008 et 0009 changent.

## Conséquences

- Positives : une seule interface à maintenir ; moins de surface d'attaque (plus de session ni de CSRF, plus de profileur ni de moteur de templates en production) ; 174 fichiers supprimés (près de 9 700 lignes) et 11 paquets en moins ; couverture mesurée sur tout le code restant, sans exclusion.
- Négatives / coûts : il n'est plus possible de revenir à Twig par un simple changement de configuration. Le retour d'urgence est de redéployer la dernière version qui l'avait, `v0.1.0-rc1` (le schéma de la base n'a pas changé), ce que décrit `.claude/skills/repas-ops/references/retour-twig.md`. L'ancienne installation arrêtée sous `/root/repas` n'est plus un filet : sa base date de la bascule des données.
- Garde-fou : `JwtAuthenticationTest::testTheBackServesNothingButTheApi` (404 sans cookie sur `/`, `/login`, `/shopping-list`, `/api/doc`), `deploy/smoke-test.sh` (redirection de l'ancien nom, 404 sur `/login`), `composer.json` sans paquet Twig.

## Alternatives écartées

- **Garder Twig quelques semaines derrière un nom de domaine** (délai de grâce de deux semaines proposé par le plan du front) : l'utilisateur a préféré couper, et la bascule des données comme la redirection se font en une commande.
- **Garder `symfony/twig-bundle` seulement pour Swagger UI** : une dépendance de plus pour une page de lecture que le contrat JSON et les types générés du front remplacent.
- **Une page HTML statique pour l'ancienne adresse** : la redirection permanente suffit.
