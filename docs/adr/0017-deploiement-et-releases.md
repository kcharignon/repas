# 0017 — Déploiement : release GitHub, images GHCR, retour arrière automatique

- **Statut** : Accepted
- **Date** : 2026-10-16
- **Liens** : [0008](0008-auth-session-et-jwt.md) (JWT, cookie), [0015](0015-refresh-token-du-spa.md) (cookie de rafraîchissement), [0014](0014-montee-de-versions-et-dependabot.md), `deploy/`, `.github/workflows/release.yml`, skill `repas-ops`, `docs/front-vuejs-plan.md` §10 (bascule), dépôt `kcharignon/repas-front`

## Contexte

La production est un seul droplet DigitalOcean (1 Go de RAM, pas de swap) qui fait tourner Docker Compose. Avant cette décision :

- aucune release, aucun tag, aucun secret GitHub ; la CI teste mais ne publie ni ne déploie ;
- le serveur exécutait le code du 6 mars 2025, déployé à la main (`git clone` puis `docker compose -f compose.yaml -f compose.prod.yaml up`), avec `restart: no` sur la base, tout sous `root`, et le port de la base ouvert sur Internet (fermé le 2026-10-16) ;
- `compose.prod.yaml` (modèle symfony-docker) construisait l'image sur place et montait `./:/app` par-dessus le code de l'image ;
- le front (`repas-front`) est un dépôt séparé, qui doit être déployé indépendamment du back sur le même domaine (ADR front 0004, `docs/front-vuejs-plan.md` §10).

## Décision

1. **Une release GitHub déploie.** Publier une release (tag `vX.Y.Z`, éventuellement `-rc1`) sur un dépôt construit l'image de ce dépôt, la publie, et déploie ce seul service. Le workflow refuse un tag qui n'est pas une version, un commit absent de `main`, et ne tourne qu'après la CI complète (`ci.yml` appelé par `release.yml`).
2. **Les images sont construites par la CI, jamais sur le serveur.** Registre : GHCR (`ghcr.io/kcharignon/repas`, `ghcr.io/kcharignon/repas-front`), étiquette = version exacte, jamais `latest`. Le serveur ne fait que `pull`.
3. **L'infrastructure vit dans `deploy/` du dépôt back** : `compose.yaml`, `Caddyfile`, `deploy.sh`, `backup.sh`, `.env.example`. Chaque release du back les copie dans `/opt/repas` ; la release du front n'appelle que `./deploy.sh front <version>`.
4. **Une bordure unique** (Caddy, service `edge`) est le seul service publié sur Internet. Elle sert un site par nom de domaine : `APP_HOST` envoie `/api` et `/images` au back et le reste au conteneur du front, `LEGACY_HOST` (ancien nom) redirige en `301` vers `APP_HOST` (amendé le 2026-10-16 par [0018](0018-extinction-de-twig.md) : il relayait auparavant tout vers le back Twig pendant la marche parallèle). Le back n'a plus de HTTPS propre (`SERVER_NAME=:80`) et lit l'adresse du visiteur dans `X-Forwarded-For` (`SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS`).
5. **`deploy.sh back|front vX.Y.Z` est la seule procédure de déploiement** : sauvegarde `pg_dump` lisible vérifiée (back), nouvelle version écrite dans `.env`, `pull`, `up -d --wait`, contrôle de santé réel (`/api/v1/health` pour le back, page d'accueil du SPA pour le front), puis **retour automatique à la version précédente** si le contrôle échoue. Un déploiement sans sauvegarde réussie n'a pas lieu.
6. **Le retour arrière remet le code, pas le schéma.** Les migrations Doctrine s'appliquent au démarrage du conteneur (`docker-entrypoint.sh`, `--all-or-nothing`, transactionnelle sous PostgreSQL : une migration qui échoue n'écrit rien). Toute migration doit donc rester compatible avec la version précédente du code (« élargir puis contracter » : ajouter une colonne avant de l'utiliser, ne supprimer qu'une version plus tard). Après une migration réussie suivie d'un code défaillant, la restauration de la sauvegarde d'avant déploiement est une décision humaine.
7. **Secrets** : jamais dans git ni dans une image. Ceux de la CI (`PROD_SSH_HOST`, `PROD_SSH_USER`, `PROD_SSH_KEY`, `PROD_SSH_KNOWN_HOSTS`) sont des secrets de l'environnement GitHub `production` (approbation manuelle possible) ; ceux de l'application sont dans `/opt/repas/.env` (droits 600). Les clés JWT sont créées au premier démarrage dans un volume et conservées.
8. **Le répertoire `deploy/` est testé comme du code** : `deploy/smoke-test.sh` (`make deploy-smoke`, job `deploy-config` de la CI) démarre la vraie image de production sur une base vide et vérifie migrations, clés JWT, bordure, API, Twig, cookie du SPA, sauvegarde, déploiement et retour arrière.

## Conséquences

- Positives : un déploiement est reproductible, annulable et sauvegardé ; le front et le back sortent indépendamment ; une configuration de production cassée est vue par la CI avant la release ; aucun build sur un serveur de 1 Go.
- Négatives / coûts : un seul serveur sans haute disponibilité (coupure de quelques secondes à chaque déploiement) ; le retour arrière ne défait pas les migrations (règle 6) ; la sauvegarde est locale au droplet tant qu'une copie externe n'est pas mise en place ; un tag publié par erreur sur `main` part en production (d'où l'approbation possible de l'environnement `production`).
- Garde-fou : `deploy-config` (CI), `release.yml` (version, branche), `hadolint` sur le `Dockerfile`, revue de toute modification de `deploy/`.

## Alternatives écartées

- **Construire sur le serveur** (`git pull` + `docker compose build`) : 1 Go de RAM, lent, non reproductible, le code source et les outils de build sur la machine de production.
- **Docker Hub** : un compte et un jeton de plus ; GHCR utilise déjà le `GITHUB_TOKEN` de la CI.
- **Le front en bordure, au lieu d'une bordure dédiée** : obligerait le front à porter le TLS et le routage du back (Twig), et l'image du front à connaître le nom de domaine ; la bordure séparée garde chaque image sans configuration de domaine.
- **PaaS (App Platform)** : moins de contrôle sur la base et plus cher pour un site de cette taille.
- **Déploiement par `git pull` sur le serveur déclenché par webhook** : le serveur doit alors construire et garder un clone, sans image versionnée à remettre en place.

## Amendement du 2026-10-20 : phase bêta, déploiement sans approbation manuelle jusqu'à la 1.0.0

- **Décision du propriétaire** : jusqu'à la version 1.0.0 (phase bêta), il n'y a plus d'approbation manuelle du déploiement en production, ni pour le back ni pour le front. L'environnement GitHub `production` n'a plus de relecteur obligatoire (la règle 7 et la dernière conséquence négative ci-dessus ne valent plus pour l'approbation). L'assistant principal (pas les sous-agents) crée les releases (`vX.Y.Z` du back et du site, `android-vX.Y.Z`) et le déploiement part tout seul quand la CI est verte.
- **Pourquoi** : le projet est en bêta avec quelques proches ; l'approbation à la main ralentissait chaque livraison sans rien ajouter aux contrôles automatiques, et le propriétaire veut un assistant autonome sur le déploiement.
- **Garde-fous qui restent** : tests et contrôles verts avant la construction de l'image ; sauvegarde automatique avant chaque déploiement (règle 5) ; contrôle de santé et retour arrière automatique du code par `deploy/deploy.sh` ; migrations toujours compatibles avec la version précédente (règle 6) ; une seule release du site à la fois (on attend que la précédente soit déployée) ; ordre application / site / back selon le protocole.
- **Ce qui ne change pas** : les sous-agents de code ne déploient pas, ne créent ni tag ni release et ne touchent ni aux secrets GitHub ni à l'environnement `production` ; l'allumage de `DRIVE_ENABLED` (et de `GOOGLE_CLIENT_ID`) reste fait par l'humain via ses scripts ; aucune suppression de données de production ni commande destructive sur le serveur sans demande explicite ; la restauration de la base reste une décision humaine.
- **Condition de retour** : le passage à la 1.0.0 remettra une approbation du déploiement (à décider alors). **Réalisée le 2026-10-24, voir l'amendement suivant.**

## Amendement du 2026-10-24 : fin de la phase bêta, l'approbation du déploiement revient

- **Décision du propriétaire** : la prochaine version déployée est la **1.0.0**, la première version « officielle » du projet (aucune communication publique : la première version est terminée). Le propriétaire a **remis le relecteur obligatoire** sur l'environnement GitHub `production` avant cette release : la règle 7 d'origine vaut de nouveau, pour le back et pour le site. L'amendement du 2026-10-20 est caduc pour l'approbation ; il reste ci-dessus pour l'historique.
- **Ce que fait l'assistant principal** : il crée la release (jamais un sous-agent) et suit le run, mais le job « Déploiement en production » attend l'approbation du propriétaire (bouton « Review deployments » de la page du run). L'assistant **n'approuve jamais**, et prévient le propriétaire dès que le run est en attente. Une release du site attend toujours que la précédente soit déployée.
- **L'application Android** : son workflow n'a pas d'environnement protégé (aucune approbation GitHub) ; l'étiquette `android-vX.Y.Z` reste poussée par l'assistant principal, seulement à la demande du propriétaire.
- **Ce qui ne change pas** : tests et contrôles verts avant l'image, sauvegarde avant chaque déploiement, contrôle de santé et retour arrière automatique, migrations compatibles avec la version précédente, sous-agents sans accès au déploiement, aux secrets ni à l'environnement `production`, allumage de `DRIVE_ENABLED` et de `INGREDIENT_SEEDING_ENABLED` par le propriétaire.
