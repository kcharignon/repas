# Production de Repas

Décision et justification : [ADR 0017](adr/0017-deploiement-et-releases.md). Procédures pas à pas : skill `repas-ops` (`.claude/skills/repas-ops/`).

## Vue d'ensemble

```
Internet ─▶ edge (Caddy, HTTPS)  ─▶ php  (back FrankenPHP : /api, /images) ─▶ database (PostgreSQL)
                    └────────────▶ front (SPA statique, Caddy)
```

- Un seul droplet DigitalOcean, Docker Compose, dossier `/opt/repas` (contenu de `deploy/` + `.env` + `backups/`).
- Les images sont construites par la CI et publiées sur GHCR (`ghcr.io/kcharignon/repas`, `ghcr.io/kcharignon/repas-front`), étiquetées par version. Le serveur ne construit rien.
- `APP_HOST` sert le site : le SPA, avec `/api` et `/images` relayés vers le back. `LEGACY_HOST` (ancien nom) est redirigé en permanence vers `APP_HOST`. Le back ne sert que l'API (ADR 0018).

## Publier une version

Créer une release GitHub (tag `vX.Y.Z`) sur le dépôt concerné : `gh release create vX.Y.Z --target main --generate-notes`. La CI teste, construit l'image, la publie, puis lance `deploy.sh` sur le serveur, qui sauvegarde la base, déploie, vérifie et revient seul à la version précédente si la vérification échoue. Le détail est dans `.claude/skills/repas-ops/references/release.md`.

## Fichiers

| Fichier | Rôle |
|---|---|
| `deploy/compose.yaml` | services `edge`, `php`, `front`, `database`, volumes, redémarrage automatique |
| `deploy/Caddyfile` | un site par nom de domaine, TLS automatique |
| `deploy/deploy.sh` | `./deploy.sh back\|front vX.Y.Z` : sauvegarde, déploiement, contrôle de santé, retour arrière |
| `deploy/backup.sh` | `pg_dump` vérifié, 14 dernières sauvegardes conservées |
| `deploy/.env.example` | modèle du `.env` du serveur (secrets : jamais dans git) |
| `deploy/smoke-test.sh` | essai de bout en bout, `make deploy-smoke` (aussi dans la CI) |
| `.github/workflows/release.yml` | release → tests → image GHCR → déploiement (environnement `production`) |

## Essayer la production en local

```
make deploy-smoke                                  # back seul
make deploy-smoke FRONT_IMAGE=repas-smoke-front    # avec le SPA (image construite depuis le dépôt repas-front)
```

Le test démarre la vraie image sur une base vide avec de faux secrets et des certificats internes (ports 9442/9443), puis déploie, sauvegarde et force un retour arrière. Il ne touche ni à la pile de développement ni aux images existantes de la machine.

## Ce que la production exige de l'application

- `APP_ENV=prod`, profileur coupé (`config/packages/framework.yaml`), clés JWT créées au premier démarrage dans le volume `jwt`, cache reconstruit à chaque démarrage (`frankenphp/docker-entrypoint.sh`).
- Les migrations s'appliquent au démarrage du conteneur : **chaque migration reste compatible avec la version précédente du code**, car le retour arrière ne défait pas le schéma.
- `SYMFONY_TRUSTED_PROXIES=PRIVATE_SUBNETS` : le back lit l'adresse du visiteur et le HTTPS dans les en-têtes de la bordure (plafonds de débit par IP, cookie `Secure`).
- Emails (confirmation d'adresse à l'inscription, [ADR 0020](adr/0020-confirmation-d-adresse-email.md)) : `MAILER_DSN` (`brevo+api://<clé d'API>@default` : DigitalOcean bloque le SMTP sortant, l'API HTTPS de Brevo passe par le port 443 ; recette `.claude/skills/repas-ops/references/envoi-emails.md`) et `MAILER_FROM` (expéditeur, sur le domaine authentifié chez Brevo : DKIM, DMARC) sont **obligatoires** dans `/opt/repas/.env` ; sans eux `docker compose` refuse de démarrer le back et le déploiement revient en arrière. `FRONT_URL` vaut `https://${APP_HOST}`. Les emails partent dans la requête (pas de worker) ; un échec d'envoi est journalisé (`docker compose logs php`), le compte est créé et l'utilisateur peut redemander le lien.
- Envoi de la liste au drive ([ADR 0022](adr/0022-remplissage-du-panier-drive.md), [ADR 0023](adr/0023-liste-envoyee-au-drive.md)) : l'interrupteur `DRIVE_ENABLED` est **coupé par défaut**. `deploy/compose.yaml` le liste avec la valeur `false` : sans cette ligne, la valeur du `.env` embarqué dans l'image (`composer dump-env prod`) s'appliquerait en silence. Coupé (ou absent), les routes du drive répondent `404` `DRIVE_DISABLED` et aucune liste n'est envoyée. L'allumer est une décision humaine, après un déploiement stable : `DRIVE_ENABLED=true` dans `/opt/repas/.env`, puis redéployer ou recréer le conteneur `php` (`docker compose up -d php`) ; pour couper, remettre `false` (ou retirer la ligne).
