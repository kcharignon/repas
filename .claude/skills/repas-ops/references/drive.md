# Mettre en production, couper ou annuler l'envoi de la liste au drive

Chantier « envoyer la liste de courses au drive Leclerc » ([ADR 0022](../../../../docs/adr/0022-remplissage-du-panier-drive.md), [ADR 0023](../../../../docs/adr/0023-liste-envoyee-au-drive.md), ticket DRV-502). **Les faits et les commandes de contrôle sont dans `docs/production.md`, section « Envoi de la liste au drive »** : ne pas les redire ici, les citer.

**Se déroule avec l'utilisateur, une étape par message** (SKILL.md §0). Pour chaque étape : le but en une phrase, ce qu'il doit voir si ça marche, puis on attend son résultat. Définir chaque mot nouveau (interrupteur, migration, sauvegarde). Il n'est pas devops.

**Depuis le 2026-10-24 (fin de la bêta, version 1.0.0)** : le déploiement du back et du site attend de nouveau l'approbation de l'utilisateur ; je crée moi-même les releases (`vX.Y.Z`, et `android-vX.Y.Z`, sans qu'il ait à le demander depuis le 2026-10-30), je le préviens quand le run attend et je n'approuve jamais (ADR 0017). Entre le 2026-10-20 et le 2026-10-24 (bêta), le déploiement partait sans approbation.

**Ce que je ne fais jamais** : allumer `DRIVE_ENABLED`, toucher un secret GitHub ou la clé de signature de l'application, contacter Leclerc (ni `curl`, ni navigateur, ni depuis le serveur). Je prépare, j'explique, je vérifie en lecture seule ; les écritures sont des scripts que l'utilisateur lance (`!`). Aucun secret dans le chat.

## A. Mettre la fonction en production (interrupteur coupé)

Ordre fixe : back, front, application, stabilité, puis l'utilisateur allume. Ne jamais proposer d'allumer avant la fin de l'étape 4.

1. **Contrôler avant** (lecture seule, moi) : CI verte sur `main`, `make deploy-smoke` vert (job `deploy-config`), migrations du chantier relues (5, additives), `DRIVE_ENABLED` absent ou `false` dans `/opt/repas/.env`, une sauvegarde récente. Résumer en trois lignes à l'utilisateur.
2. **Back** : je décide de la version et du moment, je publie la release (`references/release.md`) et l'utilisateur approuve le déploiement (ligne 7). Puis je contrôle (lecture) : `state/ok-back`, `docker compose ps`, `printenv DRIVE_ENABLED` = `false`, migrations à jour, `/api/v1/health`. Attendu : rien ne change pour les utilisateurs.
3. **Front** : même chose dans `kcharignon/repas-front`. Attendu : le site se charge, la connexion et les listes marchent, aucune fonction du drive n'est active.
4. **Application Android** : (si le protocole de l'agent a changé : le site web d'abord, point 3) étapes humaines du dépôt front (`android/README.md`) : clé de signature créée et gardée par l'utilisateur, secrets GitHub posés par lui, étiquette `android-vX.Y.Z` décidée par lui, installation du fichier sur les téléphones, `assetlinks.json`. Je guide chaque étape séparément, sans jamais voir la clé ni les mots de passe. Attendu : l'application s'ouvre sur Repas ; « Version et mode d'emploi » donne la version et une ligne « Agent : version …, empreinte … » (et non « absent » ni « empreinte inattendue »). Tant que l'APK signé n'existe pas (DRV-315), c'est la pré-version de test `android-dev-…` qu'on installe en essai (publique, débogable, signée d'une clé publique : empreinte SHA-256 vérifiée avant, désinstallée après la recette ; revue de sécurité du front, D2 et C6).
5. **Laisser tourner** : critères conseillés : aucune erreur `5xx` (`docker compose logs --tail 100 php`), une sauvegarde de nuit réussie, l'application installée sur le téléphone de l'utilisateur. La recette (DRV-505) ne se fait qu'allumé : elle suit l'allumage.
6. **Allumer** : décision de l'utilisateur seul. Expliquer ce que le script fait (une ligne dans `.env`, redémarrage de `php`, quelques secondes de coupure), puis :
   `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh`
   Attendu : `OK : DRIVE_ENABLED=true`. Puis contrôle en lecture (`printenv`), puis la recette réelle par l'utilisateur, sans valider de commande Leclerc : `docs/drive/recette.md` (parties 3 à 12), une étape à la fois, avec la famille prévenue de ne pas se servir de l'envoi avant la fin.

## B. Couper (immédiat, réversible)

À proposer dès que Leclerc change son site ou bloque, ou qu'un compte Leclerc est suspendu. Le runbook `docs/drive/runbook.md` donne les symptômes, le diagnostic, le repli, la correction et le retour en service : le suivre pas à pas avec l'utilisateur. Pour couper : le script de la section A (étape 6) avec `false` (`bash -s -- false`). Attendu : `OK : DRIVE_ENABLED=false`. Dire ce qui change pour la famille (le front n'offre plus l'envoi ; les listes déjà envoyées restent lisibles et peuvent être terminées, supprimées ou **rouvertes une à une** : `reopen` n'est pas derrière l'interrupteur, ADR 0023 §9) et que **rien n'est effacé**. Le script en bloc (section D) ne sert qu'avant de remettre une version du code qui ne connaît pas `SENT`.

## C. Revenir à une version précédente du back

1. Demander si la fonction a déjà été allumée. Si **non** : aucune liste `SENT`, retour normal (`references/incident.md` § 2).
2. Si **oui** (ou si on ne sait pas) : couper (B), puis **lecture seule** du script `rouvrir-listes-envoyees.sh` (sans argument) pour compter les listes `SENT`. S'il y en a, section D **avant** de remettre l'ancienne version, car l'ancien code lève une erreur sur le statut `SENT`.
3. Remettre la version (`./deploy.sh back vX.Y.Z` avec la version précédente, `gh release list`) ; une sauvegarde est faite avant. Rappeler que le **schéma reste** (les migrations du chantier sont additives : sans danger pour l'ancien code).
4. Contrôler (`state/ok-back`, `/api/v1/health`) et demander à l'utilisateur d'ouvrir ses listes.

## D. Rouvrir les listes envoyées (retour arrière, fonction coupée)

Explication à donner en premier : « un envoi verrouille la liste ; même fonction coupée, chacun peut rouvrir la sienne avec le bouton ; ce script les remet en état d'un coup, après une sauvegarde, et sert surtout quand on revient à une version du code qui ne connaît pas le verrou (elle n'a pas de bouton pour rouvrir) ». Toujours **deux temps** :

1. Lecture seule (rien n'est modifié) : `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < .claude/skills/repas-ops/references/rouvrir-listes-envoyees.sh` ; lire avec lui le tableau (listes par statut, propriétaires concernés).
2. Seulement après son accord : même commande avec `'bash -s -- --appliquer'`. Attendu : une sauvegarde `avant-reouverture-listes`, puis un tableau sans statut `SENT`.

La règle (une liste `ACTIVE` par propriétaire au plus, les autres en `PAUSED`) est expliquée dans `docs/production.md`, « Listes `SENT` ». Ne jamais lancer la requête simple de l'ADR 0023 à la place : elle peut donner deux listes actives au même propriétaire.

## Pièges

- **Interrupteur par enseigne `DRIVE_RETAILERS` (DRV-702)** : liste d'identifiants (`leclerc` par défaut, ligne absente = `leclerc`, **vide = aucune enseigne** : `deploy/compose.yaml` écrit `${DRIVE_RETAILERS-leclerc}`, sans `:`). Couper une seule enseigne = la retirer de la liste dans `/opt/repas/.env` puis `docker compose up -d --wait php` ; rien n'est effacé, `reopen` marche toujours. **Script d'écriture** : `references/ecrire-drive-retailers.sh` (sur le modèle de `ecrire-drive-enabled.sh`, lancé par l'humain : `bash -s -- leclerc,auchan`, `bash -s -- leclerc` ou `bash -s -- aucune`) ; il écrit la ligne avec une copie `.env.avant-enseignes`, recrée `php`, vérifie `printenv` et la santé. Un agent n'ajoute jamais un identifiant ; `docs/production.md` et `docs/drive/runbook.md` § 4 donnent la procédure.
- Le script d'interrupteur se lance avec `bash -s -- true|false` : sans argument il refuse (`ECHEC`). `DRIVE_ENABLED` absent du `.env` vaut `false` (valeur par défaut de `deploy/compose.yaml`).
- Une release du back ne change jamais `DRIVE_ENABLED` : le `.env` du serveur n'est pas copié par la CI, seuls `compose.yaml`, `Caddyfile`, `deploy.sh`, `backup.sh` et `.env.example` le sont.
- Un APK de débogage (`android-dev-AAAA-MM-JJ`) n'est pas signé avec la clé de publication : l'installer demande de désinstaller l'application de production (la connexion à Leclerc dans l'application est alors effacée).
- Android refuse d'installer une version plus ancienne par-dessus : corriger par une version plus récente, pas par un retour.

## Ordre de publication et contrôle après le back (2026-10-20)

Site tolérant (il ignore ou laisse passer un champ nouveau du back, liste blanche vers l'agent), puis back, puis application. Après chaque release du back qui touche `/api/v1/drive/*` ou les ingrédients : un envoi réel sur le téléphone de test (« Reprendre l'envoi » jusqu'au choix ou « panier rempli », puis abandon). Ne pas faire `adb shell am force-stop` juste après un renouvellement de session (la session est perdue). Nommer les téléphones (test, kantin, bich) ; le téléphone de test est connecté au compte de l'administrateur, il ne prouve rien sur un compte migré. Incident d'origine et détail : `docs/drive/consignes-agents.md` et `docs/production.md`.

## E. Allumer ou couper une seule enseigne (DRV-711)

`docs/production.md` (section « Envoi de la liste au drive », « Couper une seule enseigne ») et `docs/drive/runbook.md` § 16 font foi ; ne pas les redire ici.

- **Une nouvelle enseigne est livrée éteinte** : elle est dans le code (back, site, application) mais absente de `DRIVE_RETAILERS`. **Auchan (DRV-738)** : l'application publiée la sert et l'annonce (`app.info`, menu), mais le back reste l'interrupteur : tant que `DRIVE_RETAILERS` ne la contient pas, Repas ne la propose nulle part ; une application plus ancienne reste servie comme avant. Ordre : back, site, application, **recette réelle de l'enseigne par l'utilisateur** (`docs/drive/recette.md`, partie 20, gabarit), puis seulement alors l'allumage. Je ne l'allume jamais : c'est un script que l'utilisateur lance (`ecrire-drive-retailers.sh`, `bash -s -- leclerc,auchan`), une étape par message, avec le but (« rendre Auchan visible dans l'application »), l'attendu (`OK`, l'enseigne dans « Mes drives ») et le retour arrière (`bash -s -- leclerc`, qui retire Auchan des candidates sans toucher à l'application).
- **Couper une seule enseigne** : même script avec la liste de celles qui **restent** (`leclerc` pour couper Auchan ; `auchan` pour couper Leclerc ; `aucune` pour toutes, ce qui revient presque à `DRIVE_ENABLED=false`). Rien n'est effacé, les listes `SENT` se rouvrent, l'autre enseigne ne bouge pas.
- **Piège** : `DRIVE_ENABLED=false` l'emporte sur tout ; une enseigne allumée avec le drive global coupé reste invisible.
