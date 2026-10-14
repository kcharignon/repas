# 0014 — Montée de versions, Dependabot et avis de sécurité

- **Statut** : Accepted
- **Date** : 2026-10-14
- **Liens** : [0008](0008-auth-session-et-jwt.md), `.github/dependabot.yml`, `.github/workflows/ci.yml`, `Dockerfile`, `composer.json`

## Contexte

Avant cette décision, `composer audit` remontait **32 avis de sécurité sur 10 paquets** (dont `twig/twig` : 1 critique et 4 hauts, `symfony/security-http`, `symfony/http-foundation`, `phpunit/phpunit`). Le projet était sur Symfony 7.2 (hors support), les contraintes `7.2.*` interdisaient tout correctif de mineure, et rien n'alertait : pas de Dependabot, pas d'audit en CI. Le `Dockerfile` épinglait `dunglas/frankenphp:1.4.0-php8.4.2` et les versions exactes de paquets `apt` (`acl=2.3.1-3`…) : toute image plus récente faisait échouer la build, donc la CI tournait sur une version de PHP plus ancienne que le poste de développement (8.4.23).

L'API `/api/v1` va être ouverte à un front séparé (Vue.js) : les dépendances doivent être à jour avant.

## Décision

1. **Aucun avis de sécurité connu sur `main`** : le job CI `Security advisories` exécute `composer audit --locked` (cible locale : `make audit`).
2. **Dependabot hebdomadaire** (lundi 06:00, `Europe/Paris`) pour `composer`, `github-actions` et `docker` (`.github/dependabot.yml`). Les mises à jour de **correctifs et de mineures** sont proposées, regroupées en `symfony`, `doctrine` et `quality` (`phpstan`, `phpunit`, `deptrac`, `dama`).
3. **Les montées majeures se font à la main**, une PR par chantier (`ignore` des `semver-major` dans la configuration) : elles cassent des API (DBAL 4, Symfony 8, PHPUnit 13…). `make outdated` les liste.
4. Les paquets `symfony/*` sont contraints à **une seule mineure** (`8.1.*`, avec `extra.symfony.require` assorti) : changer de mineure est une PR volontaire, jamais une mise à jour automatique.
   **Cycle de vie** : les mineures 7.4 et 8.4 sont des LTS (maintenues environ trois ans), les autres mineures de la série 8 ne le sont que **8 mois** : la 8.1 sort de maintenance en 01/2027 (`bin/console about`). Tant que le projet suit la série 8, il passe à la mineure suivante **avant** la fin de maintenance de la courante, jusqu'à la LTS 8.4 (11/2027) ; revenir à la 7.4 LTS (maintenue jusqu'en 2028) est l'alternative si ce rythme n'est pas tenable.
5. **Image Docker** : FrankenPHP et PHP sont épinglés par un tag complet (`1.12.4-php8.4.23`), mis à jour par Dependabot ; les paquets `apt` ne sont **pas** épinglés (une version exacte casse la build à chaque nouvelle image).
6. **Une PR de dépendances ne se merge que verte** : `make test` (sortie sans notice ni dépréciation : on corrige, on ne masque pas) et `make quality`. Pas de merge automatique : l'humain merge.
7. Les montées de PHP (8.4 → 8.5) et de PostgreSQL (16 → 17) sont des décisions à part, avec leur propre PR (PostgreSQL : migration de données).

## Conséquences

- Positives : les correctifs de sécurité arrivent chaque semaine par PR au lieu de s'accumuler ; CI et poste de développement partagent la même version de PHP ; une dépendance vulnérable rend un check rouge.
- Négatives / coûts : une PR Dependabot par groupe et par semaine à relire (5 ouvertes au plus) ; les majeures restent un travail manuel, à planifier (voir la roadmap).
- Garde-fous : job `Security advisories` (`composer audit --locked`), `make test`, `make quality`, hadolint sur le `Dockerfile`.

## Alternatives écartées

- **Renovate** : plus configurable, mais Dependabot est intégré à GitHub sans service externe.
- **Fusion automatique des PR Dependabot** : contraire à la règle du dépôt (le merge est fait par l'humain).
- **Autoriser les montées majeures dans Dependabot** : une PR illisible par semaine (DBAL 4, doctrine-bundle 3, PHPUnit 13, swagger-php 6 sont en attente) qui échoue à la CI.
