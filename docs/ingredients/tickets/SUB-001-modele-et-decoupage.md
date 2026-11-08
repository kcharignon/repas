# SUB-001 — Une partie, son parent et son ratio : modèle, découpage, lectures (back)

- **Dépôt** : **back** ; le front est SUB-007
- **Jalon** : I8 Sous-ingrédients
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (migration, invariants, droits sans passe-droit, contrat d'API)
- **Dépend de** : MAR-003 fusionné dans `main` (même fichier `CopyRecipeHandler`, mêmes `openapi.json`, `budgets.php`, `migrations/`)
- **Statut** : à faire
- **Lire d'abord** : [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 1 à 5, 16, 28 à 31 ; `CLAUDE.md` (puces « Ingrédients propres à chaque utilisateur », « Conversions propres », « Plus de nouvel ingrédient commun », « Contextes étanches ») ; skill `repas-dev` ; issue #437.

## But

Un ingrédient du compte peut devenir **une partie d'un autre** (« Jaune d'œuf » est une partie d'« Œuf », ratio 1) ou redevenir ordinaire, avec ses invariants et ses lectures. Ce ticket ne touche à aucune liste de courses (SUB-003), à aucune copie (SUB-002), ni à la fusion et à la suppression (SUB-004).

## À livrer

1. **Migration** `Version2026110…` (additive, rejouable, `down` supprime les colonnes seules) : `ingredient.parent_slug VARCHAR(255) NULL`, `ingredient.parent_ratio DOUBLE PRECISION NULL`, index partiel `idx_ingredient_parent ON ingredient (creator, parent_slug) WHERE parent_slug IS NOT NULL`. Aucune donnée écrite. Test de migration comme `DepartmentIconMigrationTest` (jumelle du schéma, rejouable, ancien code compatible, `down`).
2. **Domaine** : `Ingredient` gagne `getParentSlug()`, `getParentRatio()`, `isPart()`, `makePartOf(string $parentSlug, float $ratio)`, `detachFromParent()`, `Ingredient::MAX_PARTS = 10` ; `Ingredient::load` lit les deux colonnes ; **`create`, `adopt`, `copyFromOriginal` et la copie en masse laissent `parent_slug`/`parent_ratio` à `NULL`** (ADR 0038 décision 5). Ratio : `QuantityBounds` (0,000001 à 1 000 000), jamais `NaN`/infini. `IngredientException` : `partOfItself()`, `partChain()`, `partLimitReached()`, `partRatioInvalid()`, `partInOpenList()` (messages constants `INGREDIENT_PART_OF_ITSELF`, `INGREDIENT_PART_CHAIN`, `INGREDIENT_PART_LIMIT_REACHED`, `INGREDIENT_PART_RATIO_INVALID`, `INGREDIENT_PART_IN_LIST`).
3. **Ports** : `IngredientRepository::countParts(string $parentSlug): int` (ou test d'existence du N-ième, sans `COUNT` complet), `hasParts(slug): bool`, `detachParts(parentSlug)` (utilisé par SUB-004 mais déclaré ici) ; `ShoppingListRepository::isIngredientInOpenList(slug): bool` (une ligne `shopping_list_row` de l'ingrédient dans une liste **non terminée**). Entité Doctrine, `IngredientPostgreSQLRepository`, `InMemoryRepository`, builder (`IngredientBuilder::withParent()`).
4. **Commandes** `SetIngredientParent` (`ingredientSlug`, `parentSlug`, `ratio`, `userId`) et `RemoveIngredientParent`, un handler chacune (`Application/Command/…/`), dans cet ordre de contrôle : slug de la partie et du parent traduits par les alias (`IngredientSlugResolver`) ; les deux **à l'utilisateur** (le parent d'un autre : `404`, jamais de passe-droit administrateur) ; pas le même ingrédient ; le parent n'est pas une partie et la partie n'a pas de partie (`partChain`) ; la partie n'a pas de ligne dans une liste non terminée (`partInOpenList`) ; le plafond de 10 parties du parent quand le parent change (changer seulement le ratio ou refaire le même appel n'est jamais refusé, idempotent) ; ratio valide ; tout est refusé **avant** la première écriture. `ConversionService::clear()` n'est pas nécessaire (aucune conversion ne change). Les unités compatibles ne changent pas.
5. **Controllers** `Infrastructure/Http/Api/SetIngredientParent/SetIngredientParentApiController` (`PUT /api/v1/ingredients/{slug}/parent`, corps `{parent: string, ratio: number}`, `#[IsGranted('INGREDIENT_OWNER', subject: 'slug')]`, `204`) et `RemoveIngredientParentApiController` (`DELETE`, `204`) ; ratio reçu en propriété `mixed` + `Assert\Type('numeric')` puis `Assert\Range` (un texte est `422` sans conversion), `#[OA\…]` complet avec les erreurs, `repas_write` sans marquer la route. **Aucun passe-droit `ROLE_ADMIN`** (`OwnerVoter`/`INGREDIENT_OWNER` ne change pas). `IngredientSlugWritesAreResolvedTest` : déclarer `SetIngredientParentCommand` (et son handler).
6. **Lectures** (clés **toujours écrites**) : `IngredientDTO` (`GET /ingredients/{slug}`) gagne `parts: list<{slug, name, ratio, unitName}>` (triées par nom puis slug) et `partOf: {slug, name, ratio}|null` ; `IngredientOptionDTO` (`GET /ingredients`, le choix d'un ingrédient) et `IngredientSearchResultDTO` gagnent `partOfSlug: string|null` et `partOfName: string|null` ; `RecipeRowDTO` gagne `partOfName: string|null`. Une seule requête de plus dans le chargeur de la fiche (les parties), aucune dans les listes (jointure sur la même instruction). `GET /ingredients/to-fix` ne change pas.
7. **Fixtures de développement** : `ingredient.json` donne à `Jaune d'œuf` et `Blanc d'œuf` (ajoutés ici, rayon et unités d'« Œuf », `is_base` vrai) le parent « Œuf » et le ratio 1 ; `base-ingredients.tsv` : les deux lignes ajoutées avec la mention « partie » en commentaire de doc ; `DevelopmentFixturesTest`. **Aucune donnée de production** n'est écrite par ce ticket (ADR 0038 décision 32).
8. **Docs** (même commit) : `docs/api/openapi.json` (`make openapi`), ADR 0038 (amendements éventuels), `docs/ingredients/roadmap.md` (case cochée), skill `repas-dev` (une ligne dans la Definition of Done : « une copie ne recopie jamais `parent_slug` »).

## Tests

- Domaine : `IngredientPartTest` (bornes du ratio, `makePartOf`, `detachFromParent`, copies à `NULL`).
- Handlers (`InMemoryRepository`, unitaires) : cas heureux, idempotence, changer le ratio, changer de parent, retirer ; refus : soi-même, chaîne dans les deux sens, onzième partie, parent d'un autre (404, rien écrit), ligne dans une liste non terminée, ratio nul / négatif / énorme.
- Vraie base (`tests/Repas/OutputAdapter/`) : `IngredientRepositoryTest` (colonnes lues et écrites, `countParts`, une requête), chargeur de la fiche (`parts`, `partOf`, tri), des options et de la recherche (`partOf*`), de la ligne de recette.
- API (`IngredientPartApiFlowTest`, vrais bus) : `204`, `204` idempotent, `401`, `403` sur l'ingrédient d'un autre **y compris pour un administrateur**, `404` parent d'un autre, les cinq `422`/`409` avec leurs messages, `GET` qui montre le lien des deux côtés, `DELETE` qui le défait.
- Budgets SQL (`tests/Repas/WriteBudget`) : `decouper-ingredient`, `recoller-ingredient`, nombre de requêtes constant. **Aucun budget existant ne change.**

## Critères d'acceptation

- [ ] `make test`, `make quality`, `make openapi-check` verts ; aucune entrée de baseline PHPStan de plus ; Deptrac sans baseline.
- [ ] Un compte sans partie voit exactement les mêmes réponses qu'avant, aux clés nouvelles près ; aucun budget existant ne monte.
- [ ] `NoSharedIngredientTest`, `IngredientVisibility::visibleBy` et `OwnerVoter` inchangés.
- [ ] PR vers `main` (**À relire** : la migration, les cinq erreurs, l'absence de passe-droit administrateur).
