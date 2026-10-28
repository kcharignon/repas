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
| `deploy/Caddyfile` | un site par nom de domaine, TLS automatique, en-têtes communs, taille et durée de lecture des corps |
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
- La bordure pose sur toutes les réponses, y compris `/api` et `/images`, `Strict-Transport-Security` (un an, sans sous-domaines ni préchargement), `X-Content-Type-Options`, `Referrer-Policy` et `Permissions-Policy`, chacun seulement quand la réponse ne le porte pas déjà (ceux du conteneur du front ne sont jamais doublés). Elle refuse en `413` un corps de requête de plus de 1 Mo (1 Mio + 1 octet pour le webhook d'Alexa, `EdgeAlexaBodyLimitTest`) et coupe une requête dont l'en-tête met plus de 10 s ou le corps plus de 30 s à arriver ; `make deploy-smoke` le vérifie.
- Emails (inscription par la seule adresse et lien de bienvenue, confirmation d'adresse des anciens comptes, [ADR 0020](adr/0020-confirmation-d-adresse-email.md) ; mot de passe oublié, [ADR 0026](adr/0026-mot-de-passe-oublie.md)) : `MAILER_DSN` (`brevo+api://<clé d'API>@default` : DigitalOcean bloque le SMTP sortant, l'API HTTPS de Brevo passe par le port 443 ; recette `.claude/skills/repas-ops/references/envoi-emails.md`) et `MAILER_FROM` (expéditeur, sur le domaine authentifié chez Brevo : DKIM, DMARC) sont **obligatoires** dans `/opt/repas/.env` ; sans eux `docker compose` refuse de démarrer le back et le déploiement revient en arrière. `FRONT_URL` vaut `https://${APP_HOST}`. Les emails partent dans la requête (pas de worker) ; un échec d'envoi est journalisé (`docker compose logs php`), le compte est créé et l'utilisateur peut redemander le lien en s'inscrivant de nouveau avec la même adresse. La migration `Version20261028120000` (déploiement de l'inscription par la seule adresse) remplace par « aucun mot de passe » celui des comptes encore en attente de l'ancien formulaire : leurs propriétaires choisissent un mot de passe par le lien de bienvenue ou « mot de passe oublié »
- Version de l'application Android ([DRV-637](drive/tickets/DRV-637-version-de-l-application-connue-du-serveur.md)) : `APP_LATEST_VERSION` (dernière version publiée) et `APP_MIN_VERSION` (plus ancienne encore attendue), trois nombres (`0.1.10`), **vides par défaut**. Vides, absentes ou mal formées, personne n'est invité à mettre à jour ; jamais d'erreur. `deploy/compose.yaml` les liste avec une valeur vide (`${APP_LATEST_VERSION:-}`), pour la même raison que `DRIVE_ENABLED` ; modèle dans `deploy/.env.example`. Elles ne servent qu'à informer (`appUpdate` de `GET /api/v1/me`, « à mettre à jour » dans la liste des comptes), jamais à bloquer ni à accorder un droit. Après chaque publication de l'application (`android-vX.Y.Z`), **jamais avant**, l'utilisateur écrit la nouvelle version (même déroulé que `DRIVE_ENABLED` : copie `.env.avant-version`, conteneur `php` recréé, contrôle de la valeur et de `/api/v1/health`) :

  ```
  ! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- latest 0.1.10' < .claude/skills/repas-ops/references/ecrire-app-version.sh    # dernière version publiée
  ! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- minimum 0.1.9' < .claude/skills/repas-ops/references/ecrire-app-version.sh    # plus ancienne encore attendue
  ! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 "bash -s -- latest ''"     < .claude/skills/repas-ops/references/ecrire-app-version.sh    # vider (plus d'invitation)
  ```

  Sortie attendue : `OK : APP_LATEST_VERSION=0.1.10, le conteneur php le voit et /api/v1/health répond.` La table `user_app_version` (migration additive `Version20261020120000`) garde la dernière version vue pour chaque compte, rien d'autre (ni appareil, ni modèle de téléphone), et part avec le compte.
- Historique, ING-014 en a retiré le code : adoption des ingrédients communs ([ADR 0028](adr/0028-ingredients-propres-a-chaque-utilisateur.md), ING-003) : une **commande de console** lancée par l'humain (rapport à blanc, sauvegarde, écriture), sans variable ni secret ni migration de schéma. Procédure, contrôles et retour arrière : section [Adoption des ingrédients](#adoption-des-ingredients-ing-003) ci-dessous.
- Amorçage des ingrédients à l'activation d'un compte ([ADR 0028](adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20, ING-008) : l'interrupteur `INGREDIENT_SEEDING_ENABLED` est **coupé par défaut**, comme `DRIVE_ENABLED`. `deploy/compose.yaml` le liste avec la valeur `false` (`${INGREDIENT_SEEDING_ENABLED:-false}`) : sans cette ligne, la valeur du `.env` embarqué dans l'image s'appliquerait en silence ; modèle dans `deploy/.env.example`. Coupé (ou absent), un compte qui devient actif ne reçoit aucun ingrédient et l'activation réussit. L'allumer est une décision humaine, après le classement et l'amorçage du compte vide. Procédure, contrôles et retour arrière : section [Amorçage des ingrédients](#amorcage-des-ingredients-ing-008) ci-dessous. Aucune migration de schéma.
- Envoi de la liste au drive ([ADR 0022](adr/0022-remplissage-du-panier-drive.md), [ADR 0023](adr/0023-liste-envoyee-au-drive.md)) : l'interrupteur `DRIVE_ENABLED` est **coupé par défaut**. `deploy/compose.yaml` le liste avec la valeur `false` : sans cette ligne, la valeur du `.env` embarqué dans l'image (`composer dump-env prod`) s'appliquerait en silence. Coupé (ou absent), les routes du drive répondent `404` `DRIVE_DISABLED` et aucune liste n'est envoyée. L'allumer est une décision humaine, après un déploiement stable. Procédure, contrôles et retour arrière : section [Envoi de la liste au drive](#envoi-de-la-liste-au-drive-drv-502) ci-dessous.
- Commande vocale Alexa ([ADR 0029](adr/0029-commande-vocale-alexa.md)) : l'interrupteur `ALEXA_ENABLED` est **coupé par défaut** (listé dans `deploy/compose.yaml` avec `false`, comme `DRIVE_ENABLED`). Coupé (ou absent), `/api/voice/*` et les routes d'autorisation répondent `404` `VOICE_DISABLED`. Quatre autres variables (`ALEXA_SKILL_ID`, `ALEXA_REDIRECT_URIS`, `ALEXA_OAUTH_CLIENT_ID`, `ALEXA_OAUTH_CLIENT_SECRET_HASH`) viennent de la console Amazon et s'écrivent par un script que l'utilisateur lance. Section [Commande vocale Alexa](#commande-vocale-alexa-vox-601) ci-dessous.

## Envoi de la liste au drive (DRV-502)

Décisions : [ADR 0022](adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](adr/0023-liste-envoyee-au-drive.md). Si Leclerc change son site ou bloque les requêtes : [`drive/runbook.md`](drive/runbook.md), à connaître avant d'allumer la fonction. Pas à pas guidé, une étape par message : skill `repas-ops`, `references/drive.md`.

> **Fin de la phase bêta : l'approbation du déploiement est revenue (2026-10-24, version 1.0.0).** L'environnement `production` a de nouveau un relecteur : l'assistant principal (pas les sous-agents de code) crée les versions `vX.Y.Z` et suit le run, mais le job « Déploiement en production » attend l'approbation du propriétaire (« Review deployments ») ; l'assistant ne l'approuve jamais. L'application Android (`android-vX.Y.Z`) n'a pas d'environnement protégé. Garde-fous : tests avant l'image, sauvegarde automatique, contrôle de santé et retour arrière automatique du code, migrations compatibles avec la version précédente, une seule release du site à la fois ([ADR 0017](adr/0017-deploiement-et-releases.md), amendement du 2026-10-24). Entre le 2026-10-20 et le 2026-10-24 (bêta), le déploiement partait sans approbation.
>
> **L'humain décide encore** l'allumage de `DRIVE_ENABLED` (et de `GOOGLE_CLIENT_ID`, et de `INGREDIENT_SEEDING_ENABLED`), la restauration de la base et toute commande destructive ; aucun agent de code ne déploie ni ne crée de version. Les écritures sur le serveur passent par des scripts que l'utilisateur lance lui-même (`!`). Aucun secret dans le chat. Dans les commandes ci-dessous, `ssh …` veut dire `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100` (lecture seule sauf mention contraire).

### Ce que la fonction ajoute à la production

**Variables.** Une seule variable côté serveur ; les secrets restent ceux d'avant et aucun secret d'enseigne n'existe (ADR 0022 règle 2 : ni identifiant, ni cookie, ni jeton de Leclerc chez nous). Le back ne contacte jamais Leclerc : aucune règle de pare-feu ni de DNS à ajouter.

| Variable | Où | Valeur | Rôle |
|---|---|---|---|
| `DRIVE_ENABLED` | `/opt/repas/.env` du serveur ; `deploy/compose.yaml` la transmet au conteneur `php` (`${DRIVE_ENABLED:-false}`) ; modèle dans `deploy/.env.example` | `false` (défaut, aussi quand la ligne est absente) ou `true` | Interrupteur de la fonction. Coupé : `send` et les routes `/api/v1/drive/*` répondent `404 DRIVE_DISABLED` (sauf `GET /drive/settings`, qui répond `enabled: false`) ; aucune liste n'est envoyée. `reopen` n'est **pas** concerné : il reste disponible (ADR 0023 §9). |
| `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` | secrets GitHub du dépôt **front**, posés par l'humain (jamais vus par un agent ni par le chat) | clé de signature de l'application, créée et gardée par l'utilisateur hors du dépôt | Signature de l'APK publié (ticket DRV-315, dépôt front). Rien à poser sur le serveur. |

Le front n'a pas de variable : il lit `enabled` dans `GET /api/v1/drive/settings`. `FRONT_URL`, `MAILER_*` et les autres variables ne changent pas.

**Plafonds de débit (DRV-615).** Aucune variable ni réglage serveur : les écritures du drive d'un utilisateur (`PUT /drive/settings`, `PUT /drive/choices`, `PUT` et `DELETE /drive/choices/{slug}`) sont limitées à **60 par minute**, ses lectures de plan et d'estimation à **120 par minute** (limiteurs `drive_write` et `drive_read`, `config/packages/rate_limiter.yaml`, un compteur par utilisateur du jeton, pas par adresse IP). Au-delà : `429` `DRIVE_RATE_LIMITED` avec `Retry-After`, sans rien enregistrer ; un usage normal (un lot de l'agent par passage, un clic par écriture) n'en approche jamais. Les compteurs sont dans le **cache de l'application** (fichiers sous `var/cache` du conteneur `php`), pas dans la base : aucune migration, aucune requête SQL en plus, remis à zéro par un nouveau déploiement ou un vidage du cache, sans conséquence. Une machine unique suffit ; avec plusieurs conteneurs `php` il faudrait un cache partagé (Redis). Un `429` isolé dans les journaux n'est pas une panne : il montre qu'un compte ou un script dépasse le plafond (voir `drive/runbook.md`).

**Migrations.** Cinq migrations s'appliquent au démarrage du back (`docker-entrypoint.sh`). Elles sont **additives** et donc compatibles avec la version précédente du code, qui les ignore (ADR 0017 règle 6) : colonnes nullables, nouvelles tables, aucune clé étrangère.

| Migration | Ajoute |
|---|---|
| `Version20261017100000` | `unit.dimension` et `unit.base_factor` (nullables), amorcées pour les unités de référence |
| `Version20261018090000` | `shopping_list.sent_at` (nullable) |
| `Version20261018100000` | table `drive_settings` (magasin de chaque utilisateur) |
| `Version20261018110000` | tables `drive_product`, `drive_choice`, `drive_choice_product` (produits mémorisés, préférences) |
| `Version20261018150000` | `drive_settings.product_writes_day` et `product_writes_count` (compteur du jour des écritures de produits, plafond par utilisateur, DRV-613) |

La seule chose que l'ancien code ne sait pas lire est le statut `SENT` d'une liste de courses (`ShoppingListStatus::from('SENT')` lèverait une erreur). Tant que `DRIVE_ENABLED` est coupé, aucune liste n'est `SENT` : c'est la raison de l'interrupteur. Retour arrière quand des listes `SENT` existent : voir plus bas.

### Ordre de mise en production

Chaque étape attend que la précédente soit contrôlée. Le détail des commandes de contrôle suit.

| # | Qui | Étape | On voit que c'est bon quand |
|---|---|---|---|
| 1 | assistant (depuis le 2026-10-20, sans approbation) | **Back avec `DRIVE_ENABLED=false`** : release `vX.Y.Z` du dépôt `kcharignon/repas` (`references/release.md`) ; le déploiement part quand la CI est verte (avant le 2026-10-20 : approbation humaine). Le `.env` du serveur ne contient pas `DRIVE_ENABLED=true`. | le run `Release` est vert, `printenv DRIVE_ENABLED` dans `php` vaut `false`, les migrations sont à jour (`New` = 0), `/api/v1/health` répond, l'application se comporte comme avant |
| 2 | assistant (depuis le 2026-10-20) | **Front** : release du dépôt `kcharignon/repas-front` (`gh release create … -R kcharignon/repas-front`). Il lit `enabled: false` et n'offre pas l'envoi. | le site se charge, la connexion et les listes fonctionnent, rien du drive n'est actif |
| 3 | assistant pour l'étiquette (depuis le 2026-10-20), humain pour l'installation | **Application Android** : APK signé publié par une étiquette `android-vX.Y.Z` (créée par l'assistant principal), installé à la main sur les téléphones ; étapes humaines propres à l'application (clé de signature, `assetlinks.json`) : `android/README.md` du front. Tant que l'APK signé n'existe pas (DRV-315), la recette se fait avec une pré-version de test `android-dev-…` ([`drive/recette.md`](drive/recette.md) partie 2), publique et débogable : un essai, à désinstaller après. | l'application s'ouvre sur Repas, sa fenêtre « Version et mode d'emploi » donne la version et une ligne « Agent : version …, empreinte … » (et non « absent » ni « empreinte inattendue ») |
| 4 | humain | **Stabilité** : laisser tourner. Critères conseillés : aucune erreur `5xx` dans `docker compose logs php`, au moins une sauvegarde de nuit réussie depuis l'étape 1, l'application installée sur le téléphone de l'utilisateur, la famille prévenue de ne pas se servir de l'envoi avant la fin de la recette | les critères sont remplis |
| 5 | humain | **Allumer** `DRIVE_ENABLED=true` (script ci-dessous). Jamais avant l'étape 4. **La recette sur téléphone (DRV-505, [`drive/recette.md`](drive/recette.md) parties 3 à 12) se déroule allumé** : l'envoi n'existe pas coupé. Recette signée : on laisse allumé et on prévient la famille ; sinon on coupe. | `printenv DRIVE_ENABLED` vaut `true`, `GET /drive/settings` répond `enabled: true`, la recette est signée |

Pourquoi cet ordre : le front appelle des routes que seul le nouveau back connaît ; l'interrupteur coupé garantit qu'aucune liste `SENT` n'existe avant que tout soit en place ; l'application dépend du front (elle l'affiche dans une WebView : une mise à jour du site est visible aussitôt) et ne sert à rien tant que la fonction est coupée.

**Plus tard, à chaque changement du protocole de l'agent** (par exemple un champ ajouté au résultat, comme `stats` avec l'agent 0.15.0) : le site web rejette un message qu'il ne connaît pas, donc **publier d'abord le site (release front `vX.Y.Z`, étape 2), puis l'application (`android-vX.Y.Z`, étape 3)**. Une application plus récente que le site rend l'envoi inutilisable (le résultat est rejeté). Détail : [`drive/architecture.md`](drive/architecture.md) § 12.

### Contrôles (lecture seule)

**Avant l'étape 1**

```
gh run list --branch main --limit 3                         # CI verte sur main (dont le job deploy-config = make deploy-smoke)
gh release list --limit 3                                   # dernière version publiée
ls migrations/Version2026101[78]*.php                       # les migrations du chantier (liste plus haut), relues, additives
ssh … 'grep -E "^DRIVE_ENABLED=" /opt/repas/.env || echo "DRIVE_ENABLED absente : coupée par défaut"'   # ne doit pas valoir true
ssh … 'ls -1t /opt/repas/backups | head -3'                 # une sauvegarde récente existe
```

`make deploy-smoke` joue la vraie image de production sur une base vide et vérifie, entre autres, que l'envoi au drive est **coupé par défaut** quand le `.env` ne dit rien de `DRIVE_ENABLED` (ligne « l'envoi de la liste au drive est coupé par défaut »).

**Après l'étape 1** (et après toute release du back)

```
ssh … 'cd /opt/repas && cat state/ok-back && docker compose ps'                                   # la version attendue, tous les services « healthy »
ssh … 'cd /opt/repas && docker compose exec -T php printenv DRIVE_ENABLED < /dev/null'            # false
ssh … 'cd /opt/repas && docker compose exec -T php php bin/console doctrine:migrations:status --no-interaction < /dev/null | grep -E "Current|New"'   # Current = Version20261018150000 (ou plus récente), New = 0
curl -sk -o /dev/null -w "%{http_code}\n" https://<domaine>/api/v1/health                          # 200
```

**Après l'étape 5** (allumage)

```
ssh … 'cd /opt/repas && docker compose exec -T php printenv DRIVE_ENABLED < /dev/null'            # true
ssh … 'cd /opt/repas && docker compose logs --since 10m php | grep -ci error'                      # pas de rafale d'erreurs
```

puis, dans l'application, une liste réelle envoyée par l'utilisateur lui-même (sans valider de commande Leclerc) : la liste passe « Envoyée au drive », puis « Rouvrir » la remet active. Ces essais se font avec les vrais écrans, pas à pas dans [`drive/recette.md`](drive/recette.md) ; aucune requête n'est envoyée à Leclerc depuis le serveur.

### Allumer, couper

Les deux gestes sont le même script, lancé par l'utilisateur. Il écrit `DRIVE_ENABLED` dans `/opt/repas/.env` (copie de l'ancien fichier dans `.env.avant-drive`, droits `600` conservés), recrée le conteneur `php` (coupure de quelques secondes, comme un déploiement ; les connexions des utilisateurs restent valables, les clés JWT sont dans un volume), puis contrôle que le conteneur voit la bonne valeur et que `/api/v1/health` répond.

```
! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true'  < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh    # allumer
! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh    # couper
```

Sortie attendue : `OK : DRIVE_ENABLED=true` (ou `false`), `le conteneur php le voit et /api/v1/health répond`. Si une ligne `ECHEC` apparaît : `ssh … 'cd /opt/repas && docker compose logs --tail 60 php'`, puis `references/incident.md`.

**Couper est toujours sûr et immédiat.** Rien n'est effacé : les produits mémorisés, les réglages et les listes restent en base. Effets visibles tant que c'est coupé :
- le front (qui lit `enabled`) n'offre plus l'envoi ;
- les listes déjà `SENT` restent **lisibles**, on peut les **terminer** (`complete`), les **supprimer** ou les **rouvrir** une à une depuis l'interface (`reopen` reste disponible interrupteur coupé, ADR 0023 §9 : une coupure d'urgence ne fige pas les listes) ; elles restent verrouillées tant qu'elles sont envoyées. Le script en bloc (« Listes `SENT` » plus bas) ne sert qu'avant de remettre une version du code qui ne connaît pas `SENT` ;
- l'application et l'agent ne sont pas touchés (ils n'appellent rien tant que le front ne leur envoie pas de plan).

Recommandé : couper dès que Leclerc change son site, bloque les requêtes de façon répétée, ou qu'un compte Leclerc est suspendu (procédure complète : [`drive/runbook.md`](drive/runbook.md)).

### Retour arrière

Du plus léger au plus lourd ; s'arrêter dès que le problème est réglé.

1. **Couper** (`false`, ci-dessus). C'est la réponse à tout problème de la fonction : le code reste en place, le schéma aussi.
2. **Remettre une version du code** (`./deploy.sh back vX.Y.Z`, `references/incident.md` § 2) : remet le **code**, **jamais le schéma** (ADR 0017 règle 6). Les cinq migrations restent appliquées, ce qui est sans danger : la version précédente ignore les colonnes et tables ajoutées. **Mais** si des listes sont au statut `SENT`, la version précédente ne sait pas les lire. Donc, **avant de remettre une version qui ne connaît pas `SENT`** :
   1. couper la fonction (`false`), pour qu'aucune liste ne soit envoyée pendant l'opération ;
   2. voir combien de listes sont `SENT` (lecture seule) puis les remettre en état (script ci-dessous) ;
   3. seulement ensuite, remettre la version précédente.
   Si `DRIVE_ENABLED` n'a jamais été allumé, il n'y a aucune liste `SENT` et l'étape 2 est inutile ; le script de lecture seule le confirme.
3. **Restaurer la base** : décision humaine, jamais automatique (`references/incident.md` § 3). Inutile pour le drive : tout ce que la fonction écrit se retire sans restauration.

Le retour arrière du **front** est indépendant : `./deploy.sh front vX.Y.Z` (version précédente). Le back suit sans changement : les routes du drive restent, le front ne les appelle plus. Celui de l'**application** : Android refuse d'installer par-dessus une version plus ancienne (`versionCode` plus petit) ; revenir en arrière demande de désinstaller l'application, ce qui efface sa connexion à Leclerc. Préférer publier une version corrigée avec un `versionCode` plus grand.

#### Listes `SENT`

Le script s'exécute en **deux temps**. Sans argument, il ne fait que lire (nombre de listes par statut, propriétaires concernés). Avec `--appliquer`, il fait d'abord une sauvegarde de la base (`avant-reouverture-listes`), puis modifie en une transaction :

```
! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s'                < .claude/skills/repas-ops/references/rouvrir-listes-envoyees.sh   # lecture seule
! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- --appliquer' < .claude/skills/repas-ops/references/rouvrir-listes-envoyees.sh   # modifie
```

La requête de l'[ADR 0023](adr/0023-liste-envoyee-au-drive.md) §9 est `UPDATE shopping_list SET status = 'ACTIVE', sent_at = NULL WHERE status = 'SENT';`. Elle suffit si personne n'a de liste active à côté. Comme l'envoi libère la place de liste active, un propriétaire peut avoir une liste `ACTIVE` **et** une liste `SENT` (ou deux `SENT`) : la requête simple lui laisserait alors deux listes actives. Le script applique donc une règle plus prudente, par propriétaire : la liste envoyée la plus récente redevient `ACTIVE` s'il n'a aucune liste active, toutes les autres repassent en `PAUSED` (il les active quand il veut) ; `sent_at` est effacée et la `version` monte d'un cran (un `If-Match` périmé est refusé en `412`) :

```sql
UPDATE shopping_list s
   SET status = CASE
         WHEN NOT EXISTS (SELECT 1 FROM shopping_list a WHERE a.owner = s.owner AND a.status = 'ACTIVE')
          AND s.id = (SELECT x.id FROM shopping_list x WHERE x.owner = s.owner AND x.status = 'SENT' ORDER BY x.created_at DESC, x.id DESC LIMIT 1)
         THEN 'ACTIVE' ELSE 'PAUSED' END,
       sent_at = NULL,
       version = version + 1
 WHERE s.status = 'SENT';
```

Aucune ligne n'est cochée ni supprimée ; le panier Leclerc n'est jamais touché. Les produits mémorisés (`drive_*`) restent, inutilisés par l'ancien code.

### Sauvegardes

Les tables `drive_*` et les colonnes ajoutées sont dans les `pg_dump` d'avant chaque déploiement du back et de la nuit (14 conservés, `deploy/backup.sh`) : rien à ajouter. Elles contiennent des données personnelles (produits choisis, magasin, listes envoyées) : les sauvegardes restent sur le serveur, en mode `700`, comme avant. Aucun secret d'enseigne n'existe, donc aucune sauvegarde n'en contient. Une restauration d'une sauvegarde antérieure aux migrations fait réappliquer celles-ci au démarrage suivant (elles sont additives). Une suppression de compte disparaît des sauvegardes au plus tard quand les 14 derniers dumps ont été renouvelés (environ deux semaines ; voir la [note de vie privée](drive/note-vie-privee.md)).

## Adoption des ingrédients (ING-003)

> **Historique (ING-014, 2026-10-24).** Cette migration a été exécutée en production le 2026-10-20 ; la commande `repas:ingredients:adopt-commons`, son script `adopter-ingredients.sh`, son retour arrière (`--undo`) et leurs tests ont été retirés du code. Ce qui suit décrit ce qui a été fait, pour mémoire. Il n'existe plus de commande de retour arrière des données : la **sauvegarde** d'avant un lancement (`avant-adoption-ingredients`, `avant-amorcage-ingredients`) est la seule voie, sur décision humaine.

Décision : [ADR 0028](adr/0028-ingredients-propres-a-chaque-utilisateur.md). Plan complet, vérifications, retour arrière et contrôles SQL : [`ingredients/migration.md`](ingredients/migration.md) (§ 13 pour le pas à pas). Pas à pas guidé avec l'utilisateur, une étape par message : skill `repas-ops`, `references/ingredients.md`.

**Ce que c'est.** Une **commande de console** (`php bin/console repas:ingredients:adopt-commons`), pas une migration Doctrine : elle ne change pas le schéma, n'est pas lancée par le déploiement, et n'a ni variable d'environnement ni secret. Chaque compte **concerné** (non administrateur, avec au moins un ingrédient personnel ou une recette) reçoit sa propre copie de chaque ingrédient commun, avec ses conversions propres, et tout ce qui référençait un commun chez lui (lignes de ses recettes et de ses listes, **listes envoyées au drive comprises**, choix du drive) pointe vers sa copie. Les communs restent, intacts ; l'administrateur et les comptes sans recette ni ingrédient personnel ne reçoivent rien ; **rien n'est jamais supprimé**. Une transaction par compte, rejouable (un second lancement n'écrit rien).

**Quand.** Après la mise en production d'ING-001, ING-002 et ING-004 (le déploiement du back A, observé quelques heures) ; **jamais le même jour qu'une autre release du back** ; pas pendant qu'un envoi de liste est en cours sur un téléphone ; à un moment calme.

**Qui.** L'humain, avec l'assistant, par un script (`!`) ; **jamais un agent de code**, et la sortie ne contient aucune donnée personnelle (comptes numérotés, aucun e-mail ni identifiant ni nom d'ingrédient personnel).

| Étape | Commande (`ssh …` = `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100`) | Effet |
|---|---|---|
| Rapport à blanc | `! ssh … 'bash -s' < .claude/skills/repas-ops/references/adopter-ingredients.sh` | lecture seule : qui est concerné, ce qui serait copié et repointé, les anomalies, les listes envoyées, les empreintes |
| Écrire | `! ssh … 'bash -s -- --appliquer --accepter-listes-envoyees' < …/adopter-ingredients.sh` | rapport à blanc, **sauvegarde `avant-adoption-ingredients`**, écriture compte par compte, vérification d'après |
| Vérifier | `! ssh … 'bash -s -- --verifier' < …/adopter-ingredients.sh` | lecture seule, « Rien à faire » (code de sortie 0) ou ce qui reste |

Le script s'arrête avant la sauvegarde si le rapport signale une anomalie bloquante (référence orpheline ou croisée d'un compte retenu), et `--appliquer` refuse d'écrire sans `--accepter-listes-envoyees` tant qu'une liste envoyée a des lignes sur un commun. `--compte=<référence>` (la référence courte du rapport) limite à un compte, pour un essai. Au premier échec d'un compte la commande s'arrête : ce compte n'a rien reçu, les autres comptes déjà faits le restent, relancer est sans danger.

**Contrôles (lecture seule).** Les requêtes SQL de fin (aucune ligne restée sur un commun chez un compte migré, alias valides, aucun alias pour l'administrateur, photographie globale identique à celle d'avant) sont dans [`ingredients/migration.md`](ingredients/migration.md) § 13, à lancer avec `docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'`.

**Retour arrière.** (1) Du **code** : sans danger, l'ancien code lit sans erreur ce que la migration écrit (les copies sont des ingrédients personnels ordinaires ; dégradations acceptées et visibles : chaque ingrédient en double dans les rayons, la recherche et le sélecteur de recette, [`ingredients/migration.md`](ingredients/migration.md) § 11). (2) Des **données**, sur décision humaine : `! ssh … 'bash -s -- --appliquer --annuler=<lancement>' < …/adopter-ingredients.sh` (sauvegarde `avant-annulation-adoption-ingredients`, puis repointage vers les communs, alias du lancement effacés, **copies conservées**), ou le SQL équivalent du document si la commande ne démarre plus. (3) La **restauration de la sauvegarde** `avant-adoption-ingredients` : décision humaine, jamais automatique ([`ingredients/migration.md`](ingredients/migration.md) § 10, `references/incident.md` § 3 du skill).

**Sauvegardes.** Celle du lancement s'ajoute à celle du déploiement du back et à celle de la nuit (14 conservées, `deploy/backup.sh`) ; elles contiennent des données personnelles et restent sur le serveur, en mode `700`.

### Ordre de publication et contrôle après le back (incident du 2026-10-20)

Un champ ajouté par le back à `/api/v1/drive/*` doit être toléré par le site (liste blanche vers l'agent, jamais de recopie en bloc) **avant** d'être servi : ordre site tolérant, puis back, puis application. Après chaque publication du back qui touche `/api/v1/drive/*` ou les ingrédients, un envoi réel de bout en bout sur le téléphone de test (« Reprendre l'envoi » jusqu'au choix ou « panier rempli », puis abandon). Origine : le back v0.6.7 a ajouté `needed.unitSlug/unitName` au plan, le site le recopiait vers l'agent au validateur strict, et tous les envois ont échoué jusqu'au site v0.5.18. Détail : [`drive/consignes-agents.md`](drive/consignes-agents.md).

## Amorçage des ingrédients (ING-008)

Décision : [ADR 0028](adr/0028-ingredients-propres-a-chaque-utilisateur.md), amendement du 2026-10-20. Plan, règle d'amorçage, requête de santé, pas à pas et retour arrière : [`ingredients/sans-commun.md`](ingredients/sans-commun.md) §§ 2.5 et 13. Pas à pas guidé avec l'utilisateur, une étape par message : skill `repas-ops`, `references/amorcage-ingredients.md`.

**Ce que c'est.** Un compte qui devient **actif** (confirmation de l'adresse, lien de mot de passe oublié d'un compte en attente, activation par l'administrateur, et la connexion avec Google quand elle existera) reçoit une copie des ingrédients « de base » du propriétaire du catalogue, avec leurs conversions propres, **dans la transaction de l'activation** ; si la copie échoue, l'activation échoue avec elle et la personne réessaie. Jamais à l'inscription seule. Deux outils : l'**interrupteur** `INGREDIENT_SEEDING_ENABLED` (l'amorçage automatique) et le **rattrapage** `php bin/console repas:ingredients:seed` (les comptes actifs qui n'ont pas encore les leurs : le compte vide, un compte activé interrupteur coupé), qui marche interrupteur coupé.

| Variable | Où | Valeur | Rôle |
|---|---|---|---|
| `INGREDIENT_SEEDING_ENABLED` | `/opt/repas/.env` du serveur ; `deploy/compose.yaml` la transmet au conteneur `php` (`${INGREDIENT_SEEDING_ENABLED:-false}`) ; modèle dans `deploy/.env.example` | `false` (défaut, aussi quand la ligne est absente) ou `true` | Interrupteur de l'amorçage **automatique** à l'activation. Coupé : activer un compte ne copie rien, ne coûte aucune requête de plus, et n'empêche rien. |

**Qui, quand.** L'humain, par scripts (`!`), avec l'assistant ; **jamais un agent de code**. L'ordre exact est celui du plan : classement des 151 « de base » et amorçage du compte vide (ING-009), contrôles, **puis** interrupteur, essai de bout en bout, **puis** passation. Le rattrapage et l'interrupteur ne se lancent pas le jour d'une release du back.

| Étape | Commande (`ssh …` = `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100`) | Effet |
|---|---|---|
| Rapport à blanc | `! ssh … 'bash -s' < .claude/skills/repas-ops/references/amorcer-ingredients.sh` | lecture seule : comptes retenus, ce qui serait copié et repointé, collisions, listes envoyées, empreintes, requête de santé |
| Rattraper | `! ssh … 'bash -s -- --appliquer --accepter-listes-envoyees' < …/amorcer-ingredients.sh` | rapport, **sauvegarde `avant-amorcage-ingredients`**, écriture compte par compte, vérification d'après (`--check`) |
| Un seul compte | `… --compte=<référence>` (de n'importe quel statut) ; `--sauf=<référence>` ; `--complet` (exige `--compte=`) ; `--lancement=<nom>` | essai sur un compte, ou compte en attente / désactivé qui a des lignes sur le catalogue |
| Vérifier (santé) | `! ssh … 'bash -s -- --verifier' < …/amorcer-ingredients.sh` | lecture seule : code 0 tout est conforme, 1 il reste à faire, 3 rien à écrire mais la santé est rouge |
| Allumer / couper | `! ssh … 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh` (ou `false`) | écrit `INGREDIENT_SEEDING_ENABLED` dans `/opt/repas/.env` (copie `.env.avant-amorcage`, droits `600`), recrée `php` (coupure de quelques secondes), contrôle la valeur vue par le conteneur et `/api/v1/health` |

Le rapport et les journaux ne contiennent **ni adresse e-mail, ni identifiant de compte, ni nom d'ingrédient personnel** : les comptes sont numérotés et désignés par une référence courte. Au premier échec d'un compte la commande s'arrête : ce compte n'a rien reçu, relancer est sans danger. `make deploy-smoke` vérifie que l'amorçage est **coupé par défaut** quand le `.env` ne dit rien.

**Contrôles (lecture seule).** La requête de santé du rapport, et les blocs SQL de [`ingredients/sans-commun.md`](ingredients/sans-commun.md) § 5 (« avant » et « après »), à lancer avec `docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'`.

**Retour arrière.** (1) Du **code** : sans danger, l'ancien code ignore l'événement et la variable, et lit tout ce que l'amorçage écrit (des ingrédients personnels ordinaires et des alias d'adoption, la forme d'ING-003). (2) De l'**amorçage automatique** : `… 'bash -s -- false' < …/ecrire-ingredient-seeding.sh`. (3) Des **données** d'un lancement : plus de commande depuis ING-014 (celle d'ING-003 est retirée), la sauvegarde seule. (4) La **restauration** de `avant-amorcage-ingredients` : décision humaine, jamais automatique (`references/incident.md` § 3 du skill).

## Passation du catalogue (ING-009)

> **Historique (ING-014, seconde PR, 2026-10-24).** La passation a été exécutée en production le 2026-10-21 ; la commande `repas:ingredients:hand-over-catalog`, son script `passer-communs-a-l-administrateur.sh`, son classement et leurs tests ont été retirés du code. Ce qui suit décrit ce qui a été fait, pour mémoire. Le retour arrière des données n'a plus de commande : la **sauvegarde** `avant-passation-communs` est la seule voie, sur décision humaine.

Décision : [ADR 0028](adr/0028-ingredients-propres-a-chaque-utilisateur.md), amendement du 2026-10-20. Plan, préconditions, contrôles SQL (« avant », « après », retour arrière) et pas à pas : [`ingredients/sans-commun.md`](ingredients/sans-commun.md) §§ 5, 6 et 14. Pas à pas guidé avec l'utilisateur, une étape par message : skill `repas-ops`, `references/passation-ingredients.md`.

**Ce que c'est.** Une **commande de console** (`php bin/console repas:ingredients:hand-over-catalog`), pas une migration Doctrine : elle ne change pas le schéma, n'est pas lancée par le déploiement, et n'a ni variable d'environnement propre ni secret (elle lit `INGREDIENT_SEEDING_ENABLED`, voir « Amorçage des ingrédients »). Elle fait, dans l'ordre et en s'arrêtant au premier échec : (1) **classer** les 261 anciens communs « de base » (151) ou « spécifiques » (110) d'après le fichier validé `src/Repas/Infrastructure/Data/base-ingredients.json` ; (2) **amorcer** tout compte actif qui n'a pas ses ingrédients (le compte vide) ; (3) contrôler les **préconditions** P1 à P7, relues dans la transaction ; (4) **passer** les 261 anciens communs (les ingrédients sans créateur) au compte administrateur protégé en **une instruction** ; (5) **vérifier**. Les recettes, listes (dont les envoyées au drive) et choix du drive du propriétaire ne sont pas touchés : mêmes slugs, aucune version ne monte. **Rien n'est jamais créé ni supprimé** dans la passation elle-même ; l'amorçage de la phase 2 copie les ingrédients de base au compte vide, comme à toute activation.

**Quand, qui.** Après la sortie R1 du back (ING-008, ING-009, ING-011, ING-012) observée au moins un jour ; **jamais le même jour qu'une release du back** (R1 comme R2) ; un moment calme, aucun envoi de liste en cours sur un téléphone. L'humain, avec l'assistant, par scripts (`!`) ; **jamais un agent de code**. La sortie ne contient ni adresse e-mail, ni identifiant de compte, ni nom d'ingrédient personnel (comptes numérotés, références courtes).

| Étape | Commande (`ssh …` = `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100`) | Effet |
|---|---|---|
| Rapport à blanc | `! ssh … 'bash -s' < .claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh` | lecture seule, aucun verrou : propriétaire, catalogue face au fichier, comptes à amorcer, préconditions, « prêt » ou « pas prêt » |
| Classer et amorcer | `! ssh … 'bash -s -- --appliquer --jusqu-a-l-amorcage --accepter-listes-envoyees' < …/passer-communs-a-l-administrateur.sh` | rapport, **sauvegarde `avant-passation-communs`**, classement (151/110), amorçage du compte vide ; la passation n'est **pas** faite |
| Interrupteur | `! ssh … 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh` | allume l'amorçage à l'activation (précondition P4) |
| Passer | `! ssh … 'bash -s -- --appliquer' < …/passer-communs-a-l-administrateur.sh` | rapport, **sauvegarde `avant-passation-communs`**, préconditions relues dans la transaction, `UPDATE` des créateurs, vérification d'après |
| Vérifier | `! ssh … 'bash -s -- --verifier' < …/passer-communs-a-l-administrateur.sh` (`--jusqu-a-l-amorcage` : classement posé, rien à amorcer) | lecture seule : code 0 seulement si tout est conforme |

Le script s'arrête avant la sauvegarde quand le rapport à blanc dit que `--appliquer` (avec les mêmes options) refuserait d'écrire : anomalie bloquante (un ancien commun inconnu du fichier ou manquant, un compte sans propriétaire…), précondition qui manquera même une fois l'amorçage fait (par exemple P4 : l'interrupteur est coupé), liste envoyée sans `--accepter-listes-envoyees`. **Rien n'est alors écrit, ni classement ni amorçage.** Au premier échec en cours de route la commande s'arrête : le compte ou la passation en cause n'a rien reçu (transaction annulée), le reste déjà fait le reste, relancer est sans danger. Un deuxième `--appliquer` n'écrit rien et le dit.

**Contrôles (lecture seule).** Les relevés R1 à R6 et les blocs SQL « avant » et « après » de [`ingredients/sans-commun.md`](ingredients/sans-commun.md) §§ 5 et 14, à lancer avec `docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v owner=<identifiant du propriétaire> -f -'` (l'identifiant se lit en base : `SELECT id FROM "user" WHERE roles::jsonb @> '["ROLE_ADMIN"]'::jsonb;`, une ligne, jamais dans un fichier du dépôt). Ces blocs sont ceux que `CatalogHandoverDocumentedSqlTest` exécute.

**Retour arrière.** (1) Du **code** : sans danger, R1 lit les deux formes de la base. (2) Des **données de la passation**, sur décision humaine, après une sauvegarde : le SQL « Retour arrière des données » du § 5 de `sans-commun.md` (une transaction : `psql --single-transaction`) remet « sans créateur » les seuls anciens communs (reconnus à leur slug sans identifiant de compte et à leur origine vide) et ramène la statistique « ingrédients créés » du propriétaire ; il laisse le marqueur « de base », les copies et les comptes amorcés ; rejouer la passation remet tout en ordre. Testé : après ce SQL, toutes les tables sont identiques à ce qu'elles étaient juste avant la passation. (3) La **restauration** de la sauvegarde `avant-passation-communs` : décision humaine, jamais automatique (`references/incident.md` § 3 du skill).

**Sauvegardes.** Celles du script s'ajoutent à celle du déploiement du back et à celle de la nuit (14 conservées, `deploy/backup.sh`) ; elles contiennent des données personnelles et restent sur le serveur, en mode `700`.

## Commande vocale Alexa (VOX-601)

Décision : [ADR 0029](adr/0029-commande-vocale-alexa.md). Analyse, tickets : [`voice/`](voice/roadmap.md). Procédure complète (mise en place, allumage, retour arrière, diagnostic) : skill `repas-ops`, `references/alexa.md`.

- **Ce qui est déployé avec le back** : la migration `voice_*` (trois tables, ajoutées vides, compatibles avec la version précédente du code, ADR 0017 règle 6) et les routes `/api/voice/*` et `/api/v1/voice/*`, toutes coupées tant que `ALEXA_ENABLED` n'est pas `true`.
- **Les scripts** (lancés par l'utilisateur, jamais par un agent) : `ecrire-alexa-env.sh` (identifiant du skill, adresses de retour, **empreinte** du secret du client ; le secret est saisi sans écho dans le terminal de l'utilisateur et n'est écrit nulle part) et `ecrire-alexa-enabled.sh true|false` (refuse d'allumer si la configuration est incomplète, recrée php, contrôle la santé).
- **Allumer** : après un déploiement stable, la console Amazon configurée (VOX-002) et le script de configuration passé ; puis la recette VOX-602 avec l'Echo.
- **Couper** : `ecrire-alexa-enabled.sh false`. Les liaisons de compte restent ; rallumer suffit.
- **Ce qui part avec un compte** : ses liaisons, codes d'autorisation et jetons (`PurgeUserVoiceData`).
- **Journaux** : un refus de requête Amazon écrit un seul `warning` avec une catégorie, jamais le corps (voix transcrite) ni la signature.
