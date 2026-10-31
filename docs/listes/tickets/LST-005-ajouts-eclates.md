# LST-005 — Ajouts éclatés (back)

- **Dépôt** : back — **Taille** : L — **Effort** : **xhigh** (écritures sur plusieurs agrégats, une transaction) — **Dépend de** : LST-003, LST-004
- **Lire d'abord** : ADR 0036 (décisions 9 à 12) ; `PlannedMealHandler`, `AddIngredientToShoppingListHandler`, `RemoveIngredientToShoppingListHandler`, `removeMealFromPlanHandler`, `UpdateServingMealHandler`.

## À faire

1. **Planifier** (`PUT /shopping-lists/active/recipes/{recipeId}`) : résoudre la destination de chaque produit de la recette (une lecture), ouvrir les listes nécessaires, ajouter à chacune son repas (`planned_group_id` commun) et ses lignes, tout refuser si une liste visée a déjà la recette (erreur actuelle) ou est `SENT`. Réponse `200 {lists: [{id, name, typeId}]}` (les listes qui ont reçu quelque chose) ; sans type : la liste principale seule, comportement identique à part ce corps.
2. **Augmenter / diminuer un produit** (`…/active/ingredients/{slug}/increase|decrease`) : la liste de sa destination ; diminuer cherche d'abord celle-là, sinon n'importe quelle liste en cours qui en a une ligne.
3. **Nombre de personnes** (`PUT /meals/{id}/serving`) et **retirer la recette** (`DELETE …/active/recipes/{recipeId}`) : agissent sur tout le groupe de repas, une transaction ; une liste `SENT` dans le groupe refuse tout (`409 SHOPPING_LIST_SENT`, rien n'est écrit).
4. `If-Match` : vérifié sur la liste principale quand elle est visée, ignoré pour les listes d'un type.
5. Contrat OpenAPI. Budgets SQL constants quel que soit le nombre de produits : `planifier-recette-eclatee`, `ajouter-produit-a-un-type`, `modifier-portions-repas-eclate`, `retirer-repas-eclate`.

## Critères d'acceptation

- Sans type, les budgets SQL existants ne bougent pas.
- Hachis parmentier avec Viande -> Boucherie, Légumes -> Marché, le reste en principale : trois listes reçoivent chacune leur repas et leurs lignes ; changer les personnes ou retirer la recette agit sur les trois ; si Marché est `SENT`, rien ne change nulle part.
- Une destination qui change après la planification ne déplace aucune ligne.
