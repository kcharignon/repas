# Amorcer les ingrédients des comptes (ING-008)

Chantier « plus d'ingrédient commun » ([ADR 0028](../../../../docs/adr/0028-ingredients-propres-a-chaque-utilisateur.md), amendement du 2026-10-20, ticket ING-008). **Les faits, la règle, la requête de santé, le pas à pas et le retour arrière sont dans `docs/ingredients/sans-commun.md` (§§ 2.5 et 13) et `docs/production.md`, section « Amorçage des ingrédients »** : ne pas les redire ici, les citer.

**Se déroule avec l'utilisateur, une étape par message** (SKILL.md §0). Pour chaque étape : le but en une phrase, ce qu'il doit voir si ça marche, puis on attend son résultat. Définir chaque mot nouveau (ingrédient « de base », copie, rapport à blanc, amorcer, interrupteur, référence du compte). Il n'est pas devops.

**Ce que je ne fais jamais** : lancer un script d'écriture moi-même sur le serveur (`!`, c'est lui), allumer l'interrupteur, lancer le rattrapage pendant qu'une autre release du back se déploie ou le jour d'une release, afficher une adresse e-mail ou un identifiant (la sortie n'en a pas : les comptes sont numérotés), supprimer quoi que ce soit. Je lis la production en lecture seule (`printenv`, `psql` des blocs de `sans-commun.md` § 5, `docker compose logs`).

## A. Avant de commencer (lecture seule, moi)

1. Le back avec ING-008 est en production depuis quelques heures (`gh release list`, `state/ok-back`), `/api/v1/health` répond, **aucune autre release du back ce jour-là**, un créneau calme.
2. `ssh … 'cd /opt/repas && docker compose exec -T php printenv INGREDIENT_SEEDING_ENABLED < /dev/null'` répond `false`.
3. Le classement des ingrédients « de base » est posé (ING-009, phase 1) : sinon le rapport à blanc dit « aucun ingrédient « de base » » et l'écriture est refusée (voulu).
4. Une sauvegarde récente ; les relevés de `sans-commun.md` § 12 (R1 à R4) et le bloc « avant » du § 5, notés : on les compare à la fin.

## B. Le rapport à blanc

`! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < .claude/skills/repas-ops/references/amorcer-ingredients.sh`

Rien n'est modifié. Lire avec lui, sans jargon : combien d'ingrédients « de base » partiraient, quels comptes seraient servis (le compte vide ; un compte en attente ou désactivé n'est pas retenu, il sera servi à son activation), les collisions de slug (« il a déjà un ingrédient de ce nom : il aura les deux, il pourra les fusionner »), les listes envoyées (« elles seront repointées : contenu, statut et date d'envoi ne changent pas »), les choix du drive, la santé. Aucune « ANOMALIE BLOQUANTE » ; sinon on s'arrête et on la comprend d'abord.

## C. L'essai sur un compte (facultatif)

`… 'bash -s -- --appliquer --accepter-listes-envoyees --compte=<référence du rapport>'` ; puis à l'écran, par lui : ses recettes, sa liste, les produits du drive de ce compte (mêmes noms, mêmes quantités), ses ingrédients dans les rayons.

## D. L'écriture

`… 'bash -s -- --appliquer --accepter-listes-envoyees'`. Attendu : le rapport, une **sauvegarde** `avant-amorcage-ingredients` (dire où elle est), une ligne par compte (« copies créées… empreinte … identique »), puis « Rien à faire : 0 à copier, 0 à repointer » et la santé. **Au premier échec le script s'arrête** : lire le message avec lui (le compte en cause n'a rien reçu), ne pas relancer à l'aveugle ; relancer est sans danger une fois compris.

## E. La santé

`… 'bash -s -- --verifier'`. `verdict : VERT` et code 0. Code 3 (« rien à écrire, santé rouge ») : un compte en attente ou désactivé garde des lignes sur les anciens communs (ou une ligne d'un vieil onglet est restée sur l'origine d'une copie) ; le dire avec ses mots, décider avec lui : le servir (`--compte=<référence>`, `--complet` pour un compte déjà amorcé), ou attendre (la passation l'exigera).

## F. Allumer l'interrupteur (décision de l'utilisateur, après D et E)

`! ssh … 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh`. Attendu : `OK : INGREDIENT_SEEDING_ENABLED=true, le conteneur php le voit et /api/v1/health répond.` (le site coupe quelques secondes). Puis **l'essai de bout en bout** avec lui : un compte d'essai (son adresse) s'inscrit, confirme, voit ses ~150 ingrédients ; je vérifie en lecture seule le nombre de copies et d'alias ; il le supprime depuis l'administration (tout part avec lui). En cas de `500` à la confirmation : couper (`… 'bash -s -- false' …`), lire `docker compose logs --tail 60 php`, rattraper ensuite par le script.

## G. Revenir en arrière (dernier recours, décision de l'utilisateur)

Du plus léger au plus lourd ; s'arrêter dès que c'est réglé.

1. **L'interrupteur** : le couper (`false`). L'activation continue de marcher, sans amorçage.
2. **Le code** : sans danger, l'ancien code lit tout (`references/incident.md` § 2).
3. **La base** : restaurer `avant-amorcage-ingredients` (`references/incident.md` § 3), décision humaine, jamais automatique.

## Pièges

- Le script se lance avec `bash -s -- --appliquer …` : sans `--appliquer` il ne fait que lire.
- `--complet` exige `--compte=` : on ne complète jamais tous les comptes.
- Le rattrapage marche interrupteur coupé ; l'interrupteur ne commande que l'amorçage automatique.
- Un compte déjà amorcé n'est jamais touché sans `--complet` ; rejouer le script n'écrit rien.
- La commande refuse d'écrire hors des environnements `prod` et `dev`.
