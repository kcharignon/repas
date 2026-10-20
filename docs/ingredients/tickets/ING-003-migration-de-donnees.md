# ING-003 — Migration de données : adopter les communs pour les utilisateurs concernés

- **Dépôt** : back (+ script d'exploitation)
- **Jalon** : I2 Migration
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (données de production, listes envoyées, choix du drive, aucun droit à l'erreur)
- **Dépend de** : ING-001, ING-002 **déployés en production** pour l'exécution ; le code peut être écrit dès qu'ING-001 est fusionné
- **Statut** : à faire
- **Lire d'abord** : **[`migration.md`](../migration.md) en entier** ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) ; [ADR 0017](../../adr/0017-deploiement-et-releases.md) règle 6 ; [ADR 0023](../../adr/0023-liste-envoyee-au-drive.md) ; `.claude/skills/repas-ops/references/rouvrir-listes-envoyees.sh` (modèle du script) ; skills `/repas-dev`, `/repas-drive-back`, `/repas-ops`.

## Contexte

Le propriétaire veut que les utilisateurs **concernés** (au moins un ingrédient personnel ou une recette dans leur livre de recettes ; ni les administrateurs ni les comptes sans rien) reçoivent leur propre copie de chaque commun, avec ses conversions, et que tout ce qui référence un commun pointe vers la copie. Le plan complet, les définitions, les vérifications et la compatibilité sont dans `migration.md` : **ce ticket l'implémente, il ne le redécide pas**.

## Portée

1. **Commande applicative** `AdoptCommonIngredientsForUser(userId, run)` (+ handler transactionnel) : l'algorithme de `migration.md` § 7, écrit en SQL par lots dans un repository `IngredientAdoptionBulkRepository` (port `Domain/Interface`, implémentation `Infrastructure/Repository`), `IngredientSlugPolicy` d'ING-001 pour les slugs, `UuidGenerator` pour les identifiants de conversions. **Aucun événement.**
2. **Lecture à blanc et vérifications** : Loader `IngredientAdoptionReportLoader` (port `Application/Loader`, implémentation SQL en lecture seule) qui produit le rapport par utilisateur (statut, périmètre, communs à adopter, collisions, lignes à repointer par table dont `SENT`, anomalies) et les contrôles avant/après de `migration.md` § 8 ; une `Query` `GetAdoptionReport` et son handler (aucune écriture).
3. **Commande de console** `repas:ingredients:adopt-commons` (`src/Command/`, boucle et affichage seulement) : modes `à blanc` (défaut), `--apply`, `--check`, `--undo=<lancement>`, `--user`, `--except`, `--accept-sent`, `--run` (`migration.md` § 6) ; refuse `--apply` si le rapport à blanc contient une anomalie bloquante (référence orpheline ou croisée d'un utilisateur concerné), si une liste `SENT` d'un concerné existe sans `--accept-sent`, ou si l'environnement n'est pas `prod`/`dev` explicite.
4. **Retour arrière des données** : `--undo` selon `migration.md` § 10 (repointe vers le commun, supprime les alias du lancement, **laisse les copies**), testé.
5. **Script d'exploitation** `.claude/skills/repas-ops/references/adopter-ingredients.sh` sur le modèle de `rouvrir-listes-envoyees.sh` : sans argument, lecture seule (rapport à blanc) ; `--appliquer` : `./backup.sh avant-adoption-ingredients`, `--apply`, puis `--check`. Documentation : `docs/production.md` (section « Adoption des ingrédients »), `references/` du skill `repas-ops`. **L'agent n'exécute jamais le script en production** (étape humaine).
6. **Test d'équivalence du drive** (`migration.md` § 8 dernier paragraphe) sur base de test : plan et estimation identiques avant/après à l'ingrédient près.

## Hors portée

L'exécution en production ; l'adoption à la première utilisation pour tous (ING-008) ; masquer les communs (ING-002, déjà livré) ; toute modification des communs ; supprimer quoi que ce soit.

## Critères d'acceptation

(Les chiffres sont ceux du jeu de test, pas ceux de la production.)

- **Périmètre.** Étant donné quatre utilisateurs : `P` (deux ingrédients personnels, aucune recette), `R` (trois recettes dont une copie, aucun ingrédient personnel), `N` (rien, mais une liste de courses et un choix du drive), `A` (administrateur avec recettes), quand `--apply` s'exécute, alors `P` et `R` ont chacun une copie de **chaque** commun, `N` et `A` n'ont **aucune** copie ni alias, et **aucune ligne** de leurs recettes, listes et choix n'a été modifiée (comptage), ni leur version.
- **Copie.** Pour chaque commun : mêmes nom, image, rayon, unités par défaut, unités compatibles ; conversions propres copiées (même nombre, nouveaux identifiants), conversions communes non copiées ; `creator = U`, `adopted_from = commun` ; un alias `adoption` avec `source = <lancement>`.
- **Repointage.** Toute ligne de `recipe_row` (recettes de `U`), `shopping_list_row`, `shopping_list_ingredient` (listes de `U`, **y compris `SENT`, en pause, terminées**) et tout `drive_choice` de `U` qui référençait un commun référence sa copie ; `drive_choice_product` intact (même nombre) ; versions des recettes et listes modifiées +1, aucune autre.
- **Aucune perte.** Mêmes nombres de lignes par table, mêmes sommes de quantités, mêmes empreintes `(nom, rayon, quantité, unité)` par liste et par recette ; aucune référence orpheline ; `GetDrivePlan` et `GetDriveEstimate` d'un utilisateur avec des choix donnent les mêmes lignes (nom, quantité, unité, produits, nombre à commander, prix) ; `user.statistics` inchangées.
- **Listes `SENT`.** Contenu commandé, statut et `sent_at` inchangés ; `--apply` s'arrête sans `--accept-sent` ; avec, la liste est repointée.
- **Collision.** `P` avait déjà un ingrédient `tomate<P>` : la copie du commun prend `tomate-2<P>`, l'ingrédient de `P` est intact (nom, rayon, unités, conversions).
- **Idempotence.** Un deuxième `--apply` écrit **0** ligne et le rapport dit « 0 à adopter, 0 à repointer » ; après une écriture concurrente simulée (une ligne remise sur un commun aliasé), `--check` la signale et un `--apply` la repointe.
- **Atomicité.** Une erreur injectée au milieu de `P` annule `P` seul (aucune copie à moitié), `R` est migré.
- **À blanc.** Le mode par défaut n'écrit rien (aucun `INSERT`/`UPDATE`/`DELETE` capturé) et imprime « rien n'a été modifié ».
- **Retour arrière.** `--undo` après `--apply` repointe tout vers les communs, supprime les alias du lancement et laisse les copies ; les lignes touchées depuis par l'utilisateur sont listées, pas défaites.
- **Compatibilité.** L'ancienne image (`deploy-smoke` avec l'image précédente) lit la base migrée : recettes, listes et plan d'envoi s'affichent (visibles en double, [`migration.md`](../migration.md) § 11).

## Tests exigés

Handler en mémoire (périmètre, collision, idempotence, aucun événement) ; **base réelle** (`OutputAdapter`) pour le repository en lots et le Loader de rapport avec des données de tous les types (recettes copiées, listes `ACTIVE`, `PAUSED`, `SENT`, `COMPLETED`, choix du drive sur plusieurs enseignes et magasins avec produits, collisions, conversions propres et communes) ; test de console (`CommandTester`) pour les modes ; scénario `WriteBudget` `adopter-catalogue` (budget constant en nombre d'utilisateurs de 1 à 5, mesuré) ; test d'équivalence du drive ; test du script avec `REPAS_DIR` pointant une copie (comme le script modèle). `make test quality`.

## Definition of Done

`repas-dev` § 4 ; `docs/ingredients/roadmap.md` coché ; le rapport de PR liste **les requêtes SQL émises** (nombre par utilisateur) et le temps mesuré sur la base de développement chargée de 8 utilisateurs de test.

## Pièges

- **Le repointage d'une recette et celui de ses lignes de liste sont indissociables** ([`analyse.md`](../analyse.md) § 4, `subtractRow`) : jamais l'un sans l'autre, jamais dans deux transactions.
- `recipe.version` et `shopping_list.version` sont écrites par `PostgreSQLRepository::bumpVersion`, **pas** par le flush de Doctrine : ici, `UPDATE … SET version = version + 1` explicite (comme le script de réouverture des listes).
- Doctrine : les `UPDATE` en masse ne mettent pas à jour l'identity map : la commande ne charge **aucun** agrégat avant (elle n'en a pas besoin) ; si un test charge un agrégat avant et après, `clear()` l'entity manager.
- Le motif du slug de copie est celui des ingrédients personnels existants ; ne pas « corriger » les 5 collisions en fusionnant : [`migration.md`](../migration.md) § 5.
- Ne pas afficher d'adresse e-mail ni de nom d'ingrédient personnel dans la sortie par défaut (dépôt public, journaux conservés) ; le script d'exploitation lit l'adresse à part s'il en a besoin.
- Les administrateurs sont reconnus par `user.roles` (JSON) en SQL ; **le contexte `Repas` n'importe pas `User`** : lecture SQL de la table `"user"` dans le Loader, comme `ConversionPostgreSQLLoader::emailsOf` (`:94`).
- Ne jamais lancer `--apply` pendant qu'une autre release du back se déploie.
