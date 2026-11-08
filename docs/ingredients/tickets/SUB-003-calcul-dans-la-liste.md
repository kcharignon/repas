# SUB-003 — « 3 œufs + 2 jaunes + 2 blancs = 5 œufs » : le total du parent dans la liste de courses (back)

- **Dépôt** : **back** ; le front est SUB-007
- **Jalon** : I8 Sous-ingrédients
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (agrégat de liste, quantités, repas éclatés, liste `SENT`, budgets SQL, cœur du chantier)
- **Dépend de** : SUB-001 fusionné
- **Statut** : à faire
- **Lire d'abord** : [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 6 à 15, 17 en entier (le tableau des gestes) ; [ADR 0036](../../adr/0036-types-de-liste-de-courses.md) décisions 3, 5, 9 ; `ShoppingList`, `PlannedMealHandler`, `UpdateServingMealHandler`, `removeMealFromPlanHandler`, `AddIngredientToShoppingListHandler`, `RemoveIngredientToShoppingListHandler`, `PlannedMealGroup`, `ListRoutingPostgreSQLRepository`, `ConversionService` ; `CLAUDE.md` (puces « Liste envoyée au drive », « Types de liste de courses ») ; skill `repas-dev`.

## But

Une partie ne devient jamais une ligne : elle se plie dans la ligne de son parent selon `total = quantité directe + max(équivalent des parties)`, correctement quand on planifie, retire un repas, change ses personnes, ajoute à la main, coche, éclate une recette entre plusieurs listes ou envoie la liste au drive.

## À livrer

1. **Migration** `shopping_list.part_shares JSONB NULL` (additive, rejouable, `down` supprime la colonne seule ; `ShoppingListPartSharesMigrationTest`). L'entité `ShoppingList` la mappe comme `meal.placed_ingredients` (`type: 'json'`, `options: ['jsonb' => true]`).
2. **Domaine** : `PartShare` (value object : `parentSlug`, `quantity` dans l'unité d'achat de la partie, `equivalent` dans l'unité d'achat du parent) ; `ShoppingList::addPart(partSlug, parentSlug, parentPurchaseUnitSlug, quantity, equivalent)`, `subtractPart(partSlug, quantity): ?PartShare` (proportionnel, efface l'entrée sous `FLOAT_DUST`, `null` sans entrée), `partShareOf(slug)`, `partsOf(parentSlug)`, `getPartShares()` ; chaque mutateur appelle `assertEditable()` ; la différence de `max` est appliquée par `addRow`/`subtractRow` (qui crée ou supprime la ligne du parent). `removeQuantity(parent, …)` qui épuise la ligne efface aussi ses entrées. `ShoppingList::load` lit le registre ; `isIngredientUsed` (port) le regarde aussi (SUB-004).
3. **Service de domaine** `PartEquivalence::of(Ingredient $part, Ingredient $parent, float $quantityInPartPurchaseUnit): float` (ADR 0038 décision 4) par `ConversionService::convertTo` ; `PartEquivalenceTest`.
4. **Handlers** (un ajout se décide sur `Ingredient::isPart()` de l'ingrédient de la ligne, un retrait sur le registre de la liste ; le parent n'est chargé — `IngredientRepository::preload` + `ConversionService::preload` — que s'il y a au moins une partie) : `PlannedMealHandler` (une partie suit la **destination de son parent** : `destinationsFor` reçoit le slug du parent ; `placed_ingredients` garde le slug de la partie), `UpdateServingMealHandler`, `removeMealFromPlanHandler`, `AddIngredientToShoppingListHandler` (ajouter une partie à la main ajoute 1 à la partie), `RemoveIngredientToShoppingListHandler`. `ListRoutingRepository::placementsFor` rend aussi `partOf` (slug du parent) et, pour une partie, la destination et l'unité d'achat du parent **dans la même lecture** (`ProductPlacement`). `AddSpokenIngredientHandler` et `RemoveSpokenIngredientHandler` : SUB-005.
5. **Lecture** : `ShoppingListRowDTO` gagne `includes: list<{name, quantity, unitSymbol}>` (clé toujours écrite, `[]` sans partie, triée par nom) ; `ShoppingListPostgreSQLLoader::buildDetail` lit `part_shares` dans la requête qui lit déjà la liste et les noms et unités des parties en **une** lecture, seulement si le registre n'est pas vide ; la ligne seule (`GET …/rows/{id}`) aussi. Résumé, plan, estimation et envoi : **aucun changement**.
6. **Drive** : aucun code ; un test de bout en bout (vraie base) plan et estimation d'une liste « 3 œufs + 2 jaunes + 2 blancs » rendent « Œuf, 5 » (jamais « Jaune d'œuf »).
7. **Docs** : `docs/api/openapi.json`, ADR 0038 (amendements), `docs/ingredients/roadmap.md`, `CLAUDE.md` si une règle se précise, `docs/production.md` (retour arrière du code : voir ADR 0038 décision 35).

## Tests

- Unitaire `ShoppingListPartsTest` : **les huit gestes du tableau de l'ADR** (3 œufs ; + 2 jaunes = 5 ; + 2 blancs = 5 ; + 1 jaune = 6 ; retirer la recette des œufs = 3 ; « 2 blancs puis 3 jaunes » = 3 ; retirer les jaunes = 2 ; retirer le dernier = la ligne disparaît) ; retrait proportionnel (4 jaunes, ratio 2, retirer 1) ; poussière flottante ; liste `SENT` refusée (`409 SHOPPING_LIST_SENT`, rien d'écrit).
- **Propriété** `ShoppingListPartsInvariantTest` : séries pseudo-aléatoires (graine fixe) de planifier / retirer / personnes / ajout à la main sur 2 parents et 4 parties ; après chaque geste `total = direct + max(équivalents)` (direct recalculé à part) et aucune ligne négative ou nulle.
- Handlers (`InMemoryRepository`) : `PlannedMealHandlerTest`, `UpdateServingMealHandlerTest`, `removeMealFromPlanHandlerTest`, ajout/retrait à la main ; une partie découpée **après** la planification se retire encore (registre) ; une partie recollée après la planification aussi ; un ingrédient ordinaire : exactement les mêmes écritures qu'avant.
- Vraie base `ShoppingListPartsApiFlowTest` (vrais bus) : « 3 œufs + 2 jaunes + 2 blancs = 5 » ; « 2 blancs + 3 jaunes = 3 » ; retirer une recette ; baisser les personnes ; **recette éclatée entre deux listes de types** (le parent « Œuf » est dans le type « Marché », les parties l'y suivent, le retrait agit sur tout le groupe) ; ligne cochée qui grandit (reste cochée) ; liste envoyée au drive (rien ne bouge, `POST …/basket-lines` n'écrit pas le registre) ; détail avec `includes` ; plan du drive.
- Budgets (`tests/Repas/WriteBudget`) : `planifier-recette-avec-parties`, `modifier-portions-repas-avec-parties`, `retirer-repas-avec-parties`, `ajouter-partie-a-la-liste` — **constants quel que soit le nombre de parties** (`PartsReadsAreConstantTest` pour les lectures) ; **aucun budget existant ne change** (zéro partie = zéro requête de plus ; `SplitRecipeReadsAreConstantTest` reste vert).

## Critères d'acceptation

- [ ] `make test`, `make quality`, `make openapi-check`, `make sql-budget-update` (seulement les nouveaux) verts ; aucune baseline de plus.
- [ ] Les gestes de la demande (issue #437) passent sur la vraie base.
- [ ] Aucun lecteur de lignes n'a changé de code (détail excepté pour `includes`).
- [ ] PR vers `main` (**À relire** : l'invariant, le retrait selon le registre, la destination du parent).
