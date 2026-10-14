# 0013 — Un agrégat référence les autres agrégats par identifiant

- **Statut** : Accepted
- **Date** : 2026-10-14
- **Liens** : [0001](0001-architecture-hexagonale.md), [0002](0002-modele-de-domaine-riche.md), [0004](0004-repository-vs-loader.md), [0010](0010-strategie-de-tests.md) règle 12, roadmap phase 8

## Contexte

La liste de courses embarquait des objets de domaine complets : chaque ligne portait un `Ingredient` (avec son rayon, ses unités, son créateur), chaque repas une `Recipe` (avec son type, son auteur, ses lignes, leurs ingrédients). Charger une liste hydratait donc tout ce graphe, et `ModelCache` masquait le surcoût. Mesuré avec `tests/Repas/WriteBudget` : renommer une liste coûtait 28 requêtes SQL (58 sans cache), cocher une ligne 28 aussi.

## Décision

1. **Un agrégat référence un autre agrégat par son identifiant** (slug ou id), jamais par l'objet. `ShoppingListRow` porte `ingredientSlug` et `unitSlug`, `ShoppingListIngredient` pareil, `Meal` porte `recipeId`.
2. Les factories et les méthodes de l'agrégat qui ont besoin des données de l'autre agrégat le reçoivent en paramètre et n'en retiennent que l'identifiant (`ShoppingList::addRow(Ingredient, quantité)`, `addMeal(id, Recipe)`).
3. Un use case qui a besoin des données d'un autre agrégat le charge lui-même par son port (`UpdateServingMealHandler` lit la `Recipe` de son repas) : le coût est payé par les use cases qui en ont l'usage, pas par tous.
4. Une question d'existence ou d'état d'un ensemble se pose par une méthode de port qui renvoie un booléen (`ShoppingListRowRepository::allRowsChecked`), pas en hydratant des agrégats.
5. Les nombres de requêtes sont figés par le cliquet `tests/Repas/WriteBudget` ([0010](0010-strategie-de-tests.md) règle 12) : alléger baisse le budget, jamais l'inverse.

## Conséquences

- Positives : charger une liste coûte 5 requêtes quelle que soit sa taille (liste, propriétaire, repas, ingrédients, lignes) ; cocher une ligne 3, la décocher 7, renommer, mettre en pause ou supprimer une liste 9. Les use cases de liste ont les mêmes coûts avec ou sans `ModelCache`.
- Coûts : le code qui lisait `row->getIngredient()` ou `meal->getRecipe()` charge désormais explicitement ; les builders et assertions de test comparent des identifiants.
- À appliquer aux prochains agrégats : `Recipe` référence déjà ses lignes et son type par objet ; son allègement relève des slices suivantes de la phase 8.
