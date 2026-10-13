# Roadmap de migration : lecture CQRS et API

Suivi vivant. Décisions : [`adr/`](adr/README.md) (surtout [0003](adr/0003-cqrs-lecture-ecriture.md), [0009](adr/0009-strategie-de-migration.md)). Le skill `repas-dev` lit et met à jour ce fichier à chaque slice.

**Objectif** : toutes les lectures passent par `Query → QueryHandler → Loader → DTO` ; de nouveaux controllers API JSON (`/api/v1`, stateless JWT) sont consommés par un front ; les routes Twig/AJAX actuelles (session) coexistent.

**Definition of Done d'une slice** : voir [ADR 0009](adr/0009-strategie-de-migration.md) §6. En bref : `make test deptrac phpstan api-doc-validate` verts, plus aucun `Repository` ni appel de domaine dans le controller/template migré, tests (handler, Loader sur base réelle, controller API avec clés JSON), test visuel Chromium des pages Twig touchées, cette roadmap à jour.

## Phase 0 — Fondations

- [x] ADR 0001 à 0012 (`docs/adr/`)
- [x] Cette roadmap
- [x] Skill `repas-dev` (`.claude/skills/repas-dev/`)
- [x] `CLAUDE.md` mis à jour (lecture via Query/Loader, renvoi ADR et skill)
- [x] 0.1 Buses `command.bus` / `query.bus`, `QueryInterface`, `QueryBus` déballe `HandlerFailedException` ([0006](adr/0006-buses-messenger.md))
- [x] 0.2 Deptrac + cible Make + CI ([0011](adr/0011-garde-fous-deptrac-phpstan.md))
- [x] 0.3 PHPStan niveau 5 + baseline + règle « controller/handler ⇒ test » + `make quality`
- [x] 0.5 Plomberie API : serializer, validator, nelmio api-doc/cors, listener d'erreurs JSON, `GET /api/v1/health`, `make api-doc-*` ([0007](adr/0007-api-rest-v1.md))
- [x] 0.6 JWT stateless pour `/api` ([0008](adr/0008-auth-session-et-jwt.md))
- [x] 0.7 Tests de base rapides : schéma + fixtures chargés une fois, un test = une transaction annulée (DAMA), suite de ~31 s à ~4 s ([0010](adr/0010-strategie-de-tests.md) règle 10)
- [ ] 0.8 **Dette de tests** : sortir des fixtures globales (voir la section ci-dessous), **échéance : avant le début de la phase 3**

## Dette de tests : sortir des fixtures globales

`DatabaseTestCase` charge encore **toutes** les fixtures une fois par exécution (comportement legacy). 7 classes de tests (33 tests) en dépendent (`DepartmentRepositoryTest` converti en phase 1). La cible est celle de Compliance : un test crée ses propres données (builders + repositories), asserte uniquement sur elles, et `DatabaseTestCase` ne crée plus que le schéma ([0010](adr/0010-strategie-de-tests.md) règle 11). Cette dette ne doit pas traîner : elle gêne chaque nouveau test de Loader (impossible d'asserter un total ou un cas vide tant que les fixtures sont là).

**Règles dès maintenant**
- Tout **nouveau** test de base crée ses données et n'utilise jamais les fixtures globales (voir `references/read-slice-template.md` §6 du skill `repas-dev`).
- Une slice qui touche un agrégat convertit au passage les tests de cet agrégat listés ci-dessous (même commit ou commit suivant).

**À convertir** (nombre de tests qui cassent si on coupe les fixtures)

| Classe | Tests à réécrire | Convertie avec |
|---|---|---|
| `Shared/.../Api/JwtAuthenticationTest` | 4 / 13 (3 utilisateurs de fixtures) | phase 1 (`GetCurrentUser` remplace aussi les routes `_probe`) |
| `OutputAdapter/ShoppingListRepositoryTest` | 6 / 7 | phase 2 (`ListShoppingLists`) |
| `OutputAdapter/ShoppingListRowRepositoryTest` | 3 / 4 | phase 2 |
| `OutputAdapter/IngredientRepositoryTest` | 6 / 8 | commit dédié fin de phase 2 |
| `OutputAdapter/ConversionRepositoryTest` | 5 / 6 | commit dédié fin de phase 2 |
| `OutputAdapter/RecipeRepositoryTest` | 6 / 6 | commit dédié fin de phase 2 |
| `OutputAdapter/RecipeLoaderTest` | 3 / 3 | commit dédié fin de phase 2 (le `RecipeLoader` legacy est remplacé en phase 5) |

`UnitRepositoryTest` et `UserRepositoryTest` n'en dépendent déjà plus.

**Quand tout est converti** (commit « fin des fixtures globales ») : retirer `doctrine:fixtures:load` de `DatabaseTestCase::loadSchemaAndFixtures()`, ajouter les cas « liste vide » aux tests de Loader, retirer cette section. Les `DataFixture` restent pour le jeu de données de dev.

**Vérifier la dépendance** : commenter temporairement le chargement des fixtures dans `DatabaseTestCase` et lancer `make test` ; chaque test rouge dépend encore des fixtures.

## Slices de lecture

Chaque ligne = une ou plusieurs queries livrables ensemble.

### Phase 1 — Pilote : données de référence

- [x] `ListDepartments` — GetAllDepartment, AdminGetAllDepartment. DTO `DepartmentDTO` (slug, name, image), `DepartmentLoader` / `DepartmentPostgreSQLLoader` (une requête, tri par slug), `GET /api/v1/departments`. Premier `MockQueryBus` (`tests/Helper/`), premiers `InMemoryLoader` et `DTOBuilder`.
- [x] `ListRecipeTypes` — GetAllRecipeType, AdminGetAllRecipeType. DTO `RecipeTypeDTO` (slug, name, image, order), `RecipeTypeLoader` / `RecipeTypePostgreSQLLoader` (une requête, tri par ordre puis slug), `GET /api/v1/recipe-types`. Socle de test `QueryBusWebTestCase` (bus doublé, utilisateur, JWT) partagé par les tests de controllers.
- [ ] `ListUnits` — AdminGetAllUnit
- [ ] `GetCurrentUser` (`GET /api/v1/me`, remplace `app.user` / `current_user.getUser().id` côté front)

Valide de bout en bout : bus, Loader, Deptrac, API, JWT. Risque faible.

### Phase 2 — Listes de courses (liste)

- [ ] `ListShoppingLists` — GetAllShoppingList, Activated/Paused/Stopped (re-read des fragments). DTO : id, nom affiché (nom par défaut inclus), statut, flags `isActive/isPaused/isCompleted`, createdAt. Supprime le pire N+1 (un agrégat complet par liste pour 4 champs) et 8 tests de statut dans `_shopping_list_list.html.twig`.

### Phase 3 — Résumé de la liste active

- [ ] `GetActiveShoppingListSummary` (quantité par ingrédient + recettes planifiées) — GetOneDepartment, GetOneRecipeType, Add/RemoveIngredientToActive, Add/RemoveRecipeToActive. Remplace `shoppingList.hasRecipe(recipe)` ×4 par ligne et `getIngredientQuantity` dans `_recipe_row` / `_ingredient_row`.

### Phase 4 — Liste de courses (détail)

- [ ] Commande `EnsureActiveShoppingList` (extrait l'écriture de `GetActiveShoppingListViewController`)
- [ ] `GetShoppingList` (par id / active / meal) — GetActive, GetOne, UpdateServingMeal, Renamed, Tick/Uncheck. Ingrédients pré-groupés par rayon, recettes par type avec servings. Supprime O(rayons × lignes) et les `usort` mutants dans Twig, l'appel inutile à `getRecipesByType`.

### Phase 5 — Recettes

- [ ] `GetRecipe` — GetOneRecipe, prefill UpdateRecipe, re-read Add/Remove
- [ ] `ListRecipesByType` — GetOneRecipeType
- [ ] `ListOtherPeopleRecipes` — GetAllOtherPeopleRecipes
- [ ] Remplacer le `RecipeLoader` legacy (renvoie des modèles) par une interface `Application\Loader` + `RecipePostgreSQLLoader` (DTO) ; supprimer le doublon `RecipeRepository::findByAuthorAndType`

### Phase 6 — Ingrédients et conversions

- [ ] `ListIngredientsByDepartment` (flag `canEdit` calculé serveur, retire `current_user.getUser().isEqual(...)` de `_ingredient_row`)
- [ ] `GetIngredient` — prefill UpdateIngredient
- [ ] `ListCompatibleUnits` — GetCompatibleUnitsForIngredient (déjà JSON, à formaliser)
- [ ] `ListConversions` — AdminGetAllConversion (N+1 : ingrédient + 2 unités par conversion)

### Phase 7 — Dernières lectures directes

- [ ] Options de formulaires : `SelectDepartmentType`, `SelectIngredientType`, `SelectUnitType`, `AbstractRecipeType`, `UpdateIngredientType`, `RegisterNewUserType` (unicité email)
- [ ] `OwnerVoter` : remplacer les 3 repositories par un Loader d'ownership léger
- [ ] `ListUsers` — AdminGetUsers
- [ ] Deptrac : interdire `Domain\Interface\*Repository` dans `Infrastructure\Http` et `Infrastructure\Http\Form`

## Écriture et extinction

### Phase 8 — Endpoints API d'écriture

- [ ] Corriger les trous d'autorisation (voir plus bas)
- [ ] Endpoints d'écriture pour les 28 use cases (`#[MapRequestPayload]`, 204 / 201 + `Location`)
- [ ] Remplacer le protocole `{views:[{selector, html}]}` par de l'état ressource côté API (le front re-requête ; `ajax.js` et les 14 controllers AJAX restent pour Twig)

### Phase 9 — Extinction de Twig

- [ ] À décider produit : quels `ViewController` retirer quand le front les remplace (ADR dédié le moment venu)

## Trous d'autorisation à corriger avant la phase 8

- `AdminCreateUnitViewController` (`/admin/unit/create`) et `CreateIngredientViewController` (`/ingredient`) sans `#[IsGranted]`.
- Routes `tick` / `uncheck` d'une ligne : `ROLE_USER` sans voter propriétaire.
- `RemoveIngredientViewController` : GET destructif.
- `OwnerVoter` `INGREDIENT_OWNER` retourne `null` pour un ingrédient système (type de retour `bool`).

## Hors périmètre (vus en passant, non corrigés)

- `LoginCheckViewController` rend `login_check/index.html.twig`, template inexistant.
- `RecipeTypePostgreSQLRepository::findOneBySlug` interroge le cache avec `Recipe::class`.
- `RecipePostgreSQLRepository::convertEntityToModel` : clé de cache = slug de la recette, pas son id.
- `phpunit.dist.xml` : `SYMFONY_PHPUNIT_VERSION=9.6` obsolète (PHPUnit 12 installé).
- `symfony/translation` absent alors que `login.html.twig` utilise `|trans`.
- `RecipeRowRepository` : interface vide, le repository concret est injecté.
- `admin_recipe_type_row.html.twig` : la ligne porte `id="department-…"` (copier-coller du rayon), à renommer quand un test ou du JS la cible.
- Formulaires admin : `conversion|default(null)` / `department|default(null)` / `unit|default(null)` jamais fournis par les controllers update, le titre reste « Nouveau … ».

## Couverture de tests (cliquet)

- Seuil CI : **75 %** (`COVERAGE_THRESHOLD`, `.github/workflows/ci.yml`). Mesure au 2026-10-13 : 77,1 %. Le seuil de 80 % précédent n'était plus tenu avant la migration (57,2 %, toute la couche Twig non testée).
- Exclus de la mesure (`phpunit.dist.xml`) : `Http/Controller` et `Http/Form` des contextes `Repas` et `User` (ils sortent de l'exclusion en même temps que du code, phase 9 ; les controllers API restent mesurés), et les `DataFixture` (données de test chargées hors d'un test, donc jamais créditées).
- Règle : le seuil ne baisse jamais. Le remonter par paliers vers 80 % quand la mesure le permet (les slices Query/Loader/API entrent à 100 %, donc le chiffre monte avec la migration).
- Plus gros trous restants (éléments non couverts) : `Infrastructure/Entity` 172, `Domain/Model` 134, commandes console `src/Command` 113, `Infrastructure/Repository` 89.

## Inventaire des lectures directes (état initial, 2026-10-12)

- 15 controllers de lecture pure (10 `Get*`, 4 `AdminGetAll*` Repas, `AdminGetUsers`).
- 11 controllers AJAX hybrides (commande puis relecture puis fragment HTML) : Activated, Paused, Stopped, AddIngredientToActive, RemoveIngredientToActive, AddRecipeToActive, RemoveRecipeToActive, TickLine, UncheckLine, RenamedShoppingList, UpdateServingMeal.
- 12 controllers de formulaire qui lisent pour pré-remplir.
- 6 form types qui injectent des repositories pour leurs choix.
- `OwnerVoter` (3 repositories).
- 1 loader legacy (`RecipeLoader`, utilisé par GetOneRecipeType et GetAllOtherPeopleRecipes).
- 0 query handler, `Application/Query` vide.
