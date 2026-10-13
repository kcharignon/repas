# Basculer un ViewController Twig vers le QueryBus

Objectif : même page, même HTML (à la logique d'affichage près), mais sans `Repository` dans le controller et sans appel de domaine dans le template. Voir [ADR 0009](../../../../docs/adr/0009-strategie-de-migration.md).

## Avant de coder : inventaire

1. Ouvrir le controller : noter chaque appel `$xRepository->…` et chaque variable passée à `render()`.
2. Ouvrir le template et ses includes (`_row`, `_card`, `_list`…) : lister
   - les propriétés lues (`row.ingredient.name`, `meal.recipe.name`…) ;
   - les **appels de méthodes de domaine** (`shoppingList.hasRecipe(recipe)`, `getIngredientQuantity`, `countRows`, `getDepartments`, `isEqual`…) ;
   - les usages de `current_user` / `app.user` ;
   - les règles d'affichage (nom par défaut, tests de statut répétés, regroupements, tris `usort`).
3. Chercher les endpoints AJAX qui re-rendent le même fragment (`grep -rn "_nom_du_fragment" src/`) : ils doivent utiliser la même query.
4. Noter le nombre de requêtes SQL actuel (web profiler → onglet Doctrine) pour comparer ensuite.

## Conception du DTO

- Une donnée par besoin d'écran, pas par champ du modèle. Ne pas recopier l'agrégat.
- Chaque appel de méthode de domaine du template devient **une propriété précalculée** du DTO (ADR 0005). Ex. : `isInActiveList: bool`, `quantityInActiveList: float`, `canEdit: bool`, `displayName: string`, `isActive/isPaused/isCompleted: bool`.
- Un regroupement fait dans Twig (par rayon, par type) devient une structure du DTO : `list<DepartmentGroupDTO>` contenant `list<RowDTO>`.
- Les valeurs qui dépendent de l'utilisateur courant (`canEdit`, propriété) sont calculées en passant l'id/rôle de l'utilisateur à la query — pas via `current_user` dans le template.

## Mise en œuvre

1. Écrire la slice de lecture : `references/read-slice-template.md` (DTO → Loader → Query/Handler → implémentation → tests).
2. Dans le controller : retirer les `*Repository`, injecter `QueryBusInterface`, `ask()` la query, passer le DTO à `render()`. Passer l'utilisateur courant à la query si nécessaire (`$this->getUser()` → id).
3. Dans le template : remplacer les appels de domaine par les propriétés du DTO. Conserver les helpers de présentation (`format_datetime`, `asset()`, `path()`).
4. Les controllers AJAX qui re-rendent un fragment : même query, même DTO.
5. Lecture avec effet de bord (ex. `GetActiveShoppingListViewController` crée la liste si absente) : extraire une **Command** (`EnsureActiveShoppingList`), la dispatcher dans le controller, **puis** `ask()` une query pure.

## Vérification

- `make test` (ne doit rien casser) puis tests de la slice (handler, Loader sur base réelle).
- `make deptrac phpstan` : le controller ne référence plus de `Domain\Interface\*Repository`.
- Test visuel dans Chromium headless de chaque page touchée, captures lues, liens et actions AJAX essayés : [`visual-check.md`](visual-check.md). Même contenu que la version précédente.
- Web profiler : nombre de requêtes SQL avant/après — il doit baisser ou rester borné (pas de boucle de requêtes).
- `grep -n "\.get\|\.has\|\.is\|\.count\|current_user" <templates concernés>` : plus d'appel de domaine.
- Cocher la slice dans `docs/migration-roadmap.md`.

## Pièges connus de ce dépôt

- `ShoppingList::getDepartments`, `recipeTypePresent`, `getMealByType` mutent des caches internes et trient : à reproduire dans le Loader sans mutation.
- `current_user` est le service `security.helper` exposé en global Twig (`config/packages/twig.yaml`) : à ne plus utiliser dans les templates migrés.
- `OwnerVoter` recharge l'agrégat complet (`SHOPPING_LIST_OWNER`, `RECIPE_OWNER`…) : phase 7 de la roadmap, ne pas y toucher dans une slice de lecture.
- `RecipeRepository::findByAuthorAndType` et `RecipeLoader::findByAuthorAndType` font doublon ; ne pas ajouter de troisième variante.
