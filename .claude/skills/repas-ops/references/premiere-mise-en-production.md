# Première mise en production (ancienne installation → `/opt/repas`)

**Se déroule avec l'utilisateur, une étape à la fois** (SKILL.md §0). Ce fichier est l'ordre des étapes ; chacune est donnée seule, vérifiée, puis on passe à la suivante. Les écritures sur le serveur passent par un script du scratchpad qu'il lance avec `!`.

> Note du 2026-10-20 : depuis, et jusqu'à la 1.0.0 (phase bêta), il n'y a plus d'approbation du déploiement (ADR 0017, amendement) ; les étapes d'approbation ci-dessous sont l'historique de la première mise en production.

État de départ (2026-10-16, déjà fait) : accès SSH `root`, sauvegarde `~/backups-repas/*.dump`, port 5432 fermé, migrations répétées sur une copie (OK), PR « déploiement » du back fusionnée.

## A. Sans coupure du site (l'ancienne installation continue de servir)

1. **DNS** : créer `app.kantin.eu` (A → `64.227.124.100`) chez le gestionnaire DNS du domaine. Vérifier : `dig +short app.kantin.eu`. But : le SPA aura son propre nom pendant la marche parallèle.
2. **Préparer le serveur** (script, root) : utilisateur `deploy` (groupe `docker`, sans mot de passe), dossier `/opt/repas`, fichier d'échange (swap) de 1 Go (le serveur n'a que 1 Go de RAM), règles `ufw` : 80 et 443 autorisés, 2375 et 2376 supprimés.
3. **Clé de déploiement** : sur la machine de l'utilisateur, `ssh-keygen -t ed25519 -N "" -C repas-ci -f ~/.ssh/repas_deploy` ; installer la partie publique dans `~deploy/.ssh/authorized_keys` (script). But : la CI se connecte avec cette clé et nulle autre.
4. **Secrets GitHub, posés par l'utilisateur** sur `kcharignon/repas` et `kcharignon/repas-front` : `PROD_SSH_HOST` (`64.227.124.100`), `PROD_SSH_USER` (`deploy`), `PROD_SSH_KEY` (`gh secret set PROD_SSH_KEY < ~/.ssh/repas_deploy`), `PROD_SSH_KNOWN_HOSTS` (`ssh-keyscan -t ed25519 64.227.124.100`). Environnement `production` avec l'utilisateur comme relecteur obligatoire (`gh api -X PUT repos/<repo>/environments/production …`) : la CI s'arrêtera avant chaque déploiement et attendra son accord.
5. **`/opt/repas/.env`** (script exécuté sur le serveur, secrets tirés par `openssl rand -hex`, **jamais affichés**) à partir de `deploy/.env.example` : `LEGACY_HOST=kantin.eu`, `APP_HOST=app.kantin.eu`, `CADDY_DATA_VOLUME=repas_caddy_data` (reprend les certificats existants). Copier à la main une première fois `deploy/{compose.yaml,Caddyfile,deploy.sh,backup.sh}` dans `/opt/repas` (`scp`). Vérifier : `docker compose config -q` sans erreur.
6. **Cron de sauvegarde** nocturne pour `deploy` : `0 3 * * * /opt/repas/backup.sh nuit >> /opt/repas/backups/cron.log 2>&1`.

## B. Première release (le site est coupé quelques minutes, annoncer)

7. **Publier `v0.1.0-rc1`** en pré-release sur le back (`references/release.md`). La CI teste, construit et publie l'image sur GHCR, puis **s'arrête** au job « Déploiement en production » en attente d'approbation. Contrôler que l'image GHCR est publique (`https://github.com/users/kcharignon/packages/container/repas/settings` : visibilité) sinon la rendre publique, ou ouvrir une session `docker login ghcr.io` sur le serveur.
8. **Bascule des données** (script, root, pendant que la CI attend) :
   1. sauvegarde fraîche de l'ancienne base (`pg_dump -Fc`, copie sur la machine de l'utilisateur) ;
   2. `cd /root/repas && docker compose down` (garde ses données ; l'ancien site est coupé) ;
   3. `cd /opt/repas && docker compose up -d --wait database`, puis `pg_restore` de la sauvegarde dans la nouvelle base (`--no-owner --no-privileges`) ;
   4. comptes avant/après : utilisateurs, recettes, ingrédients, listes.
9. **Approuver le déploiement** sur la page du run (« Review deployments »). `deploy.sh` démarre la bordure et le back : les migrations s'appliquent (répétées avec succès sur une copie), les certificats viennent du volume repris.
10. **Vérifier** : `https://kantin.eu/` (le site, alors encore Twig), `https://app.kantin.eu/api/v1/health` (200), connexion Twig avec un vrai compte, une recette et une liste lues.

Échec avant l'étape 9 : l'ancien site se relance avec `cd /root/repas && docker compose -f compose.yaml -f compose.prod.yaml up -d` (la variable `CADDY_MERCURE_JWT_SECRET` manquante ne gêne pas). Échec à l'étape 9 : `deploy.sh` n'a pas de version précédente à remettre ; même remède, puis diagnostic (`references/incident.md`).

## C. Le front

11. Publier `v0.1.0` en pré-release sur `kcharignon/repas-front` : image GHCR, approbation, `deploy.sh front`. Vérifier `https://app.kantin.eu/` (le SPA) et une connexion complète.
12. Quand tout est stable : release finale du back (`v0.1.0`) pour sortir du `-rc1`. L'ancienne installation (`/root/repas`) reste en place pour le retour arrière jusqu'à la bascule de la racine (`references/bascule-spa.md`) et au délai de grâce ; ensuite seulement, la supprimer (avec accord).
