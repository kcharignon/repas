# Passer les anciens communs à l'administrateur (ING-009)

Chantier « plus d'ingrédient commun » ([ADR 0028](../../../../docs/adr/0028-ingredients-propres-a-chaque-utilisateur.md), amendement du 2026-10-20, ticket ING-009). **Les faits, les préconditions, les contrôles SQL (« avant », « après », retour arrière) et le pas à pas détaillé sont dans `docs/ingredients/sans-commun.md` (§§ 5, 6 et 14) et `docs/production.md`, section « Passation du catalogue »** : ne pas les redire ici, les citer.

**Se déroule avec l'utilisateur, une étape par message** (SKILL.md §0). Pour chaque étape : le but en une phrase, ce qu'il doit voir si ça marche, puis on attend son résultat. Définir chaque mot nouveau (ingrédient commun, ingrédient « de base », classement, amorçage, interrupteur, rapport à blanc, sauvegarde, référence du compte). Il n'est pas devops.

**Ce que je ne fais jamais** : lancer la commande ou un script d'écriture moi-même sur le serveur (`!`, c'est lui), allumer l'interrupteur, faire la passation le jour d'une release du back (R1 comme R2), afficher une adresse e-mail ou un identifiant (la sortie n'en a pas : les comptes sont numérotés ; l'identifiant du propriétaire se lit en base et ne va jamais dans le chat ni dans un fichier), supprimer quoi que ce soit. Je lis la production en lecture seule (les relevés R1 à R6, le bloc « avant » et le bloc « après » de `sans-commun.md`, `--verifier`, `printenv`, `docker compose logs`).

## A. Avant de commencer (lecture seule, moi)

1. La sortie R1 du back (ING-008, ING-009, ING-011, ING-012) est en production depuis au moins un jour (`gh release list`, `state/ok-back`), `/api/v1/health` répond, **aucune autre release du back ce jour-là** (et ING-013 / ING-014 ne sont pas fusionnées), un créneau calme, aucun envoi de liste en cours sur un téléphone.
2. `ssh … 'cd /opt/repas && docker compose exec -T php printenv INGREDIENT_SEEDING_ENABLED < /dev/null'` répond `false` (on l'allume à l'étape E, pas avant).
3. Les **relevés R1 à R6** (`sans-commun.md` § 14, étape 1), notés : R1 une ligne (un seul administrateur, actif) ; R2 les lignes du compte vide sur les anciens communs ; R4 les comptes en attente ou désactivés (**s'ils ont des lignes sur des anciens communs, la précondition P2 refusera la passation** : décider avec lui de les servir un par un, `amorcer-ingredients.sh --compte=<référence>`) ; R5 la photographie, à comparer après.

## B. Le rapport à blanc

`! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < .claude/skills/repas-ops/references/passer-communs-a-l-administrateur.sh`

Rien n'est modifié, aucun verrou. Lire avec lui, sans jargon : « 261 anciens communs, dont 151 de base : ceux qu'il a choisis pour les nouveaux comptes » ; le compte vide (et lui seul) qui recevrait ses ingrédients de base et verrait ses listes, dont une envoyée, repointées sans que rien change pour lui ; les préconditions (P2 et P3 « levées par l'amorçage », P4 « il est coupé » : normal à ce stade) ; aucune « ANOMALIE BLOQUANTE » (un ancien commun inconnu du fichier ou manquant, un compte sans propriétaire : on s'arrête et on le comprend).

## C. Le classement et l'amorçage du compte vide

`… 'bash -s -- --appliquer --jusqu-a-l-amorcage --accepter-listes-envoyees'`

Attendu : le rapport, une **sauvegarde `avant-passation-communs`** (dire où elle est), « 261 ingrédient(s) sans créateur classés : 151 « de base », 110 « spécifiques » », une ligne pour le compte vide (« copies créées… empreinte … identique »), la vérification, puis « OK : classement posé et comptes amorcés. La passation n'est PAS faite ». **Au premier échec le script s'arrête** : lire le message avec lui (le compte en cause n'a rien reçu), ne pas relancer à l'aveugle ; relancer est sans danger une fois compris. Puis le bloc « avant » de `sans-commun.md` § 5 (151 de base, sommes de contrôle, 0 ligne d'un autre compte sur un ancien commun, plus aucun compte actif sans amorçage) et, à l'écran, par lui : le compte vide voit ses ingrédients, ses listes et ses produits du drive sont intacts.

## D. L'interrupteur (décision de l'utilisateur, après C)

`! ssh … 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh` ; attendu `OK : INGREDIENT_SEEDING_ENABLED=true, le conteneur php le voit et /api/v1/health répond.` (le site coupe quelques secondes). Puis **l'essai de bout en bout** (`amorcage-ingredients.md` § F) : un compte d'essai s'inscrit, confirme, voit ses ~150 ingrédients ; il le supprime depuis l'administration.

## E. La passation

Un nouveau rapport à blanc (B) : le compte vide n'est plus retenu, P1 à P7 sont « OK », « Prêt pour la passation complète (--apply) : OUI ». Puis `… 'bash -s -- --appliquer'` (`--accepter-listes-envoyees` seulement si le rapport le demande). Attendu : une nouvelle **sauvegarde** `avant-passation-communs`, « 261 ingrédient(s) passé(s) au propriétaire en une instruction », les empreintes « identiques avant et après », « Passation faite et conforme ». Si la commande refuse (« La passation est refusée, rien n'est écrit : P… »), lire la précondition avec lui : rien n'est écrit, ni classement ni amorçage ni passation.

## F. Les contrôles « après »

Le bloc « après » de `sans-commun.md` § 5 : 0 sans créateur, 261 anciens communs chez le propriétaire (151 de base, mêmes sommes de contrôle, même nombre de conversions propres), l'invariant « toute ligne désigne un ingrédient du compte qui la possède » à 0 partout, 0 orphelin, mêmes listes envoyées et mêmes sommes qu'au relevé R5, 0 compte actif sans amorçage. À l'écran, par lui : **l'administrateur voit les mêmes ingrédients qu'avant**, ses recettes, ses listes (dont les envoyées) et ses produits du drive ; un compte migré ; l'ancien compte vide (il ne voit plus que ses ~156 ingrédients : c'est la décision D5) ; un compte d'essai neuf. Puis `… 'bash -s -- --verifier'` (code 0) et `amorcer-ingredients.sh --verifier` (`verdict : VERT`).

## G. Revenir en arrière (dernier recours, décision de l'utilisateur)

Du plus léger au plus lourd ; s'arrêter dès que c'est réglé.

1. **Le code** : sans danger, l'ancien code lit tout (`references/incident.md` § 2) ; R1 lit les deux formes de la base.
2. **L'interrupteur** : le couper (`… 'bash -s -- false' …`). L'activation continue de marcher, sans amorçage.
3. **Les données de la passation** : le SQL « Retour arrière des données » de `sans-commun.md` § 5 (`psql --single-transaction -v owner=<identifiant>`), après une sauvegarde. Il remet « sans créateur » les seuls anciens communs (slug sans identifiant de compte, origine vide) et ramène la statistique du propriétaire ; **les copies, les alias, le marqueur « de base » et les comptes amorcés restent**. Vérifier avec le bloc « avant » (261 sans créateur), puis rejouer le script de passation quand la cause est comprise.
4. **La base** : restaurer `avant-passation-communs` (`references/incident.md` § 3), décision humaine, jamais automatique.

## Pièges

- Le script se lance avec `bash -s -- --appliquer …` : sans `--appliquer` il ne fait que lire.
- **Pas le jour d'une release du back**, et ING-013 / ING-014 (nettoyage, R2) ne sortent qu'après les contrôles « après » et un autre jour.
- La commande refuse d'écrire hors des environnements `prod` et `dev`, et ne crée ni ne supprime aucun ingrédient dans la passation.
- Un compte en attente ou désactivé qui a des lignes sur un ancien commun bloque la passation (P2) tant qu'il n'est pas servi (`amorcer-ingredients.sh --compte=<référence>`) : c'est voulu, sinon il perdrait l'accès à ses lignes.
- Le rapport à blanc répond « refuserait d'écrire » (code 1) tant que P4 manque, même s'il peut déjà classer et amorcer : c'est `--jusqu-a-l-amorcage` qui dit « écrirait ».
