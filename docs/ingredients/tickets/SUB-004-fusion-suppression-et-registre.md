# SUB-004 — Fusionner, supprimer, repointer un ingrédient qui a des parties (back)

- **Dépôt** : **back**
- **Jalon** : I8 Sous-ingrédients
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (fusion en masse, données de l'utilisateur, tests de couverture du schéma)
- **Dépend de** : SUB-001 et SUB-003 fusionnés (le registre `part_shares` existe)
- **Statut** : fait (2026-11-08)
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

- [x] `make test`, `make quality`, `make openapi-check` verts.
- [x] Aucun refus pour un compte sans partie (comportement identique ; la fusion garde ses 22 requêtes, la suppression gagne une instruction, annoncée : 13 → 14).
- [x] PR vers `main` (**À relire** : l'ordre des refus, le registre jamais réécrit, les deux refus d'unités que le ticket ne disait pas).

## Réalisation (2026-11-08)

- **`MergeBlock`** : `PART_CONFLICT` (`422 MERGE_PART_CONFLICT`) puis `PART_IN_LIST` (`409 MERGE_PART_IN_LIST`), dans cet ordre, entre `IN_SENT_LIST` et `UNIT_MISSING` (`IngredientException::mergePartConflict()`, `mergePartInList()`). `IngredientMergePlanner` (pur) rend le même verdict à l'aperçu et à l'écriture ; `blockedBy` du contrat gagne les deux valeurs (`make openapi`). `PART_CONFLICT` : deux parties de deux parents différents, l'un parent de l'autre, une chaîne (le résultat serait à la fois une partie et un parent : un parent absorbé dans une partie, une partie absorbée dans un parent qui a des parties), plus de dix parties, et deux garde-fous d'unités que le ticket ne disait pas (voir l'amendement de l'ADR). `PART_IN_LIST` : le registre d'une liste non terminée nomme l'un des deux (partie ou parent), ou le gardé sera une partie alors que l'un des deux a une ligne dans une liste non terminée.
- **Les faits viennent de la même lecture** : `IngredientMergeUsageReader::usageOf` (l'aperçu et l'écriture) ajoute quatre colonnes à sa requête unique (nombre de parties de chacun, borné à onze ; registre nommant l'un des deux ; ligne dans une liste non terminée), donc **aucune requête de plus** ; la règle « le registre nomme cet ingrédient » est écrite une seule fois, `Infrastructure\Merge\PartRegistrySql`, lue aussi par `ShoppingListRepository::isIngredientUsed`. `MergeParty` gagne `parentSlug` et `cookingUnitSlug`.
- **Écriture** (`IngredientMergePostgreSQLRepository::repoint`) : **une** instruction (`WITH inherited AS (UPDATE …), moved AS (UPDATE …)`) : le gardé hérite du lien (parent et ratio) de l'absorbé s'il n'en a pas, les parties de l'absorbé deviennent celles du gardé ; jamais d'écrasement d'un lien ; exécutée seulement quand le plan dit qu'un lien est à transporter (`IngredientMergePlan::$repointsPartLinks`), donc **zéro requête de plus pour un compte sans partie** (`fusionner-ingredients` reste à 22). Le filet final compte aussi `ingredient.parent_slug = absorbé` (`INGREDIENT_MERGE_CONFLICT`). Compte rendu `IngredientMergeRepointed::$parts` / `$linkInherited`. **Le registre `shopping_list.part_shares` n'est jamais réécrit.**
- **Suppression** : `RemoveIngredientHandler` détache les parties d'un parent (`IngredientRepository::detachParts`, une instruction, toujours exécutée : l'ingrédient chargé ne sait pas s'il a des parties) et `ShoppingListRepository::isIngredientUsed` rend vrai aussi quand le registre d'une liste **non terminée** nomme l'ingrédient (clé ou `parent`), toujours **une seule requête**. Supprimer une partie ne change rien d'autre.
- **Doublons possibles** : `IngredientCandidate::$parentSlug`, `DuplicateIngredientFinder` ne propose jamais une partie avec son parent, aucune autre règle.
- **Couverture** : `IngredientReferencesAreAllRepointedTest` range `ingredient.parent_slug` parmi les colonnes repointées (et prouve la fusion sur la vraie base) et dit pour `shopping_list.part_shares` « jamais réécrit, refusé en liste » ; `UserOwnedDataCoverageTest` affirme explicitement qu'aucune table n'est ajoutée (les trois colonnes sont sur `Ingredient` et `ShoppingList`, déjà purgés avec le compte).
- **Budgets** : `supprimer-ingredient` 13 → **14** (l'instruction de détachement, constante : `RemoveIngredientWithPartsBudgetTest` mesure 0, 1 et 10 parties) ; nouveaux `fusionner-ingredients-avec-parties` (23) et `fusionner-ingredients-lien-herite` (23), une requête de plus que sans lien, constante quels que soient le nombre de parties et le volume (`MergeIngredientsBudgetTest`) ; aucun autre budget ne bouge.
- **Tests** : `IngredientMergePlannerTest` (chaque combinaison de liens, l'ordre des refus), `MergeIngredientsHandlerTest` (héritage, parties qui passent, dix refus qui n'écrivent rien), `IngredientMergeRepositoryTest` (la vraie base : décomptes, registre, registres qui n'en sont pas, héritage, parties, filet), `ShoppingListRepositoryTest`, `RemoveIngredientHandlerTest`, `DuplicateIngredientFinderTest` et `IngredientDuplicateLoaderTest`, `IngredientMergeApiFlowTest` (aperçu = écriture, rien n'est écrit sur refus, retirer le repas lève `PART_IN_LIST`, supprimer un parent détache ses parties).
- **Décisions prises seul** : voir l'amendement du 2026-11-08 de l'[ADR 0038](../../adr/0038-sous-ingredients.md) (SUB-004).
