# SUB-004 — Fusionner, supprimer, repointer un ingrédient qui a des parties (back)

- **Dépôt** : **back**
- **Jalon** : I8 Sous-ingrédients
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (fusion en masse, données de l'utilisateur, tests de couverture du schéma)
- **Dépend de** : SUB-001 et SUB-003 fusionnés (le registre `part_shares` existe)
- **Statut** : à faire
- **Lire d'abord** : [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 25 à 27 ; [`fusion.md`](../fusion.md) ; `IngredientMergePlanner`, `MergeBlock`, `IngredientMergePostgreSQLRepository`, `RemoveIngredientHandler`, `IngredientReferencesAreAllRepointedTest` ; `CLAUDE.md` (puces « Fusion », « Doublons possibles »).

## À livrer

1. **`MergeBlock`** : `PART_CONFLICT` (`422`) quand la fusion créerait une chaîne ou deux parents (les deux sont des parties de deux parents différents ; l'absorbé est le parent du gardé ou l'inverse ; un parent qui a des parties absorbé dans une partie ; le gardé dépasserait 10 parties) et `PART_IN_LIST` (`409`) quand le registre d'une liste **non terminée** mentionne l'un des deux (en partie ou en parent). Placés **après** `IN_SENT_LIST` et avant `UNIT_MISSING` dans l'ordre de l'enum ; `IngredientException` : `mergePartConflict()`, `mergePartInList()` ; `IngredientMergePlanner` (pur) rend le même verdict à l'aperçu et à l'écriture.
2. **Écriture de la fusion** (`IngredientMergePostgreSQLRepository::repoint`) : le gardé **hérite** du lien de l'absorbé s'il n'en a pas ; les parties de l'absorbé deviennent celles du gardé ; `ingredient.parent_slug` est repointé en masse (une instruction) ; compte rendu `IngredientMergeRepointed` ; nombre de requêtes constant (budget `fusionner-ingredients` : +1 au plus, annoncé).
3. **Aperçu** (`GET …/merge-preview`) : le même refus, avec un message de l'écran dans le contrat (`blockedBy`: `PART_CONFLICT`, `PART_IN_LIST`).
4. **Suppression** (`RemoveIngredientHandler`) : supprimer un **parent** détache ses parties (`IngredientRepository::detachParts`, une instruction) ; supprimer un ingrédient mentionné par le registre d'une liste non terminée est refusé (`cannotBeRemoveExistInShoppingList`) : `ShoppingListRepository::isIngredientUsed` est élargie (la **même** requête, un `OR jsonb_exists` / existence dans `part_shares`). Supprimer une partie ne change rien d'autre.
5. **Couverture** : `IngredientReferencesAreAllRepointedTest` connaît `ingredient.parent_slug` (repointée) et déclare `shopping_list.part_shares` (colonne JSON qui désigne des ingrédients : refusée-en-liste, jamais réécrite) ; `UserOwnedDataCoverageTest` : aucune nouvelle table, rien à ajouter (le test doit le dire par une assertion explicite).
6. **Doublons possibles** (`DuplicateIngredientFinder`) : ne propose jamais une paire partie/parent ; aucune autre règle.

## Tests

- `IngredientMergePlannerTest` : chaque combinaison (aucun lien ; un seul lien ; deux liens au même parent ; deux parents différents ; parent absorbé dans un autre parent ; chaîne) et `PART_IN_LIST`.
- Vraie base : fusion de deux « Jaune d'œuf » (le gardé garde son ratio, les recettes et listes suivent), d'un parent dans un autre (les parties suivent, plafond), refus avec registre ; suppression d'un parent (parties détachées) ; suppression refusée par le registre ; `DuplicateIngredientFinderTest`.
- API : `IngredientMergeApiFlowTest` étendu (aperçu = écriture, rien n'est écrit sur refus).
- Budgets : `fusionner-ingredients` (annoncé), `supprimer-ingredient` (annoncé).

## Critères d'acceptation

- [ ] `make test`, `make quality`, `make openapi-check` verts.
- [ ] Aucun refus pour un compte sans partie (comportement identique, requêtes identiques à une instruction près annoncée).
- [ ] PR vers `main` (**À relire** : l'ordre des refus, le registre jamais réécrit).
