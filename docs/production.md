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
- Envoi de la liste au drive ([ADR 0022](adr/0022-remplissage-du-panier-drive.md), [ADR 0023](adr/0023-liste-envoyee-au-drive.md)) : l'interrupteur `DRIVE_ENABLED` est **coupé par défaut**. `deploy/compose.yaml` le liste avec la valeur `false` : sans cette ligne, la valeur du `.env` embarqué dans l'image (`composer dump-env prod`) s'appliquerait en silence. Coupé (ou absent), les routes du drive répondent `404` `DRIVE_DISABLED` et aucune liste n'est envoyée. L'allumer est une décision humaine, après un déploiement stable. Procédure, contrôles et retour arrière : section [Envoi de la liste au drive](#envoi-de-la-liste-au-drive-drv-502) ci-dessous.

## Envoi de la liste au drive (DRV-502)

Décisions : [ADR 0022](adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](adr/0023-liste-envoyee-au-drive.md). Si Leclerc change son site ou bloque les requêtes : [`drive/runbook.md`](drive/runbook.md), à connaître avant d'allumer la fonction. Pas à pas guidé, une étape par message : skill `repas-ops`, `references/drive.md`.

> **Phase bêta : plus d'approbation du déploiement (depuis le 2026-10-20, jusqu'à la 1.0.0).** L'environnement `production` n'a plus de relecteur : l'assistant principal (pas les sous-agents de code) crée les versions (`vX.Y.Z`, `android-vX.Y.Z`) et le déploiement part tout seul quand la CI est verte. Garde-fous : tests avant l'image, sauvegarde automatique, contrôle de santé et retour arrière automatique du code, migrations compatibles avec la version précédente, une seule release du site à la fois ([ADR 0017](adr/0017-deploiement-et-releases.md), amendement du 2026-10-20). Le passage à la 1.0.0 remettra une approbation.
>
> **L'humain décide encore** l'allumage de `DRIVE_ENABLED` (et de `GOOGLE_CLIENT_ID`), la restauration de la base et toute commande destructive ; aucun agent de code ne déploie ni ne crée de version. Les écritures sur le serveur passent par des scripts que l'utilisateur lance lui-même (`!`). Aucun secret dans le chat. Dans les commandes ci-dessous, `ssh …` veut dire `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100` (lecture seule sauf mention contraire).

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
