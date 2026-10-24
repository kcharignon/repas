# Adopter les ingrédients communs (migration ING-003)

> **Suite du chantier (amendement du 2026-10-20)** : l'adoption à la première utilisation est remplacée par l'amorçage des comptes à leur activation et par un rattrapage (ING-008) : `references/amorcage-ingredients.md`. Cette recette-ci reste celle de la migration déjà exécutée (et du retour arrière d'un lancement, y compris d'un lancement d'amorçage).

Chantier « ingrédients propres à chaque utilisateur » ([ADR 0028](../../../../docs/adr/0028-ingredients-propres-a-chaque-utilisateur.md), ticket ING-003). **Les faits, les vérifications, les contrôles SQL et le retour arrière sont dans `docs/ingredients/migration.md` (§ 10 et § 13) et `docs/production.md`, section « Adoption des ingrédients »** : ne pas les redire ici, les citer.

**Se déroule avec l'utilisateur, une étape par message** (SKILL.md §0). Pour chaque étape : le but en une phrase, ce qu'il doit voir si ça marche, puis on attend son résultat. Définir chaque mot nouveau (ingrédient commun, copie, rapport à blanc, sauvegarde, référence du compte). Il n'est pas devops.

**Ce que je ne fais jamais** : lancer le script moi-même sur le serveur (les écritures sont des scripts que l'utilisateur lance, `!`), lancer la commande pendant qu'une autre release du back se déploie, afficher une adresse e-mail ou un identifiant (la sortie n'en a pas : les comptes sont numérotés), supprimer quoi que ce soit.

## A. Avant de commencer (lecture seule, moi)

1. ING-001, ING-002 et ING-004 sont en production depuis quelques heures (`gh release list`, `state/ok-back`), `/api/v1/health` répond, **aucune autre release du back ce jour-là**, un créneau calme.
2. Aucun envoi de liste en cours sur un téléphone (le rapport compte les listes `SENT` ; en cas de doute, demander à la famille).
3. Les relevés de `docs/ingredients/migration.md` § 14 et la **photographie globale** du § 13 étape 1 (comptes, anomalies, nombre de lignes par table, somme de contrôle des communs). Les noter : on les compare à la fin.

## B. Le rapport à blanc

`! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s' < .claude/skills/repas-ops/references/adopter-ingredients.sh`

Rien n'est modifié. Lire le rapport **avec lui**, sans jargon : combien de comptes sont concernés (l'administrateur et le compte sans recette ni ingrédient sont ignorés, c'est voulu), les collisions de slug (« un ingrédient qu'il a déjà porte le nom d'un commun : il aura les deux, il pourra les fusionner plus tard »), les listes envoyées (« elles seront repointées : leur contenu, leur statut et leur date d'envoi ne changent pas, et c'est ce qui garde les produits mémorisés du drive »), les choix du drive. Aucune « ANOMALIE BLOQUANTE » ; sinon on s'arrête et on la comprend d'abord. Noter les empreintes du contenu.

## C. L'essai sur un compte (facultatif)

`… 'bash -s -- --appliquer --accepter-listes-envoyees --compte=<référence du rapport>'` ; puis avec lui, à l'écran : une recette, la liste, les produits du drive de ce compte (mêmes noms, mêmes quantités, mêmes produits), le rayon d'un ingrédient (une seule fois chaque, modifiable).

## D. L'écriture

`… 'bash -s -- --appliquer --accepter-listes-envoyees'`. Attendu : le rapport, une **sauvegarde** `avant-adoption-ingredients` (dire où elle est), une ligne par compte (« copies créées… empreinte … identique »), puis « Rien à faire : 0 à adopter, 0 à repointer, empreintes identiques à celles d'avant ». **Au premier échec le script s'arrête** : lire le message avec lui (le compte en cause n'a rien reçu), ne pas relancer à l'aveugle ; relancer est sans danger une fois compris.

## E. Le second lancement et les contrôles

1. Même commande une seconde fois : le script voit qu'il n'y a plus rien à faire et dit « Rien à faire : aucune sauvegarde, aucune écriture » ; rien n'est écrit. Puis `… 'bash -s -- --verifier'` (code de sortie 0).
2. Les **contrôles SQL de fin** du § 13, comparés à la photographie de l'étape A. Attendu : 0 ligne restée sur un commun chez les comptes migrés, 0 alias invalide, 0 alias hors périmètre, mêmes nombres de lignes et mêmes communs qu'avant.
3. À l'écran, par l'utilisateur : son compte (recette, liste, produits du drive), puis un compte non concerné et l'administrateur (rien de changé).

## F. Revenir en arrière (dernier recours, décision de l'utilisateur)

Du plus léger au plus lourd ; s'arrêter dès que c'est réglé.

1. **Le code** : sans danger, l'ancien code lit tout (`references/incident.md` § 2). Les copies s'y voient en double, rien ne casse.
2. **Les données** : `… 'bash -s -- --appliquer --annuler=<lancement> --accepter-listes-envoyees'` (le nom du lancement est dans le rapport, `migration-AAAA-MM-JJ`). Sauvegarde `avant-annulation-adoption-ingredients`, puis lignes et choix du drive repointés vers les communs, alias du lancement effacés, **copies conservées**. Une copie que la personne a modifiée depuis n'est pas défaite : la sortie le dit. Si la commande ne démarre plus : le SQL équivalent de `migration.md` § 10, en une transaction, après sauvegarde.
3. **La base** : restaurer `avant-adoption-ingredients` (`references/incident.md` § 3), décision humaine, jamais automatique.

## Pièges

- Le script se lance avec `bash -s -- --appliquer …` : sans `--appliquer` il ne fait que lire.
- Un compte désactivé ou en attente qui a des recettes est **concerné** (statut sans effet sur le périmètre) ; l'exclure se fait avec `--sauf=<référence>`.
- `--accepter-listes-envoyees` n'est demandé que si une liste envoyée a des lignes sur un commun ; au second lancement il ne l'est plus.
- Rejouer plus tard réadopte un commun que la personne aurait supprimé depuis : le rapport à blanc montre « à adopter ».
- La commande refuse d'écrire hors des environnements `prod` et `dev`.
