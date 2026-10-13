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
- [x] 0.8 Tests de base sans fixtures globales : `DatabaseTestCase` ne crée plus que le schéma (vide), chaque test crée ses données avec les builders et `tests/Helper/DataPersister` (enregistre un modèle et les agrégats qu'il référence) ; suite de 346 tests en ~3 s, cas « liste vide » ajoutés aux tests de Loader ([0010](adr/0010-strategie-de-tests.md) règle 11)

## Slices de lecture

Chaque ligne = une ou plusieurs queries livrables ensemble.

### Phase 1 — Pilote : données de référence

- [x] `ListDepartments` — GetAllDepartment, AdminGetAllDepartment. DTO `DepartmentDTO` (slug, name, image), `DepartmentLoader` / `DepartmentPostgreSQLLoader` (une requête, tri par slug), `GET /api/v1/departments`. Premier `MockQueryBus` (`tests/Helper/`), premiers `InMemoryLoader` et `DTOBuilder`.
- [x] `ListRecipeTypes` — GetAllRecipeType, AdminGetAllRecipeType. DTO `RecipeTypeDTO` (slug, name, image, order), `RecipeTypeLoader` / `RecipeTypePostgreSQLLoader` (une requête, tri par ordre puis slug), `GET /api/v1/recipe-types`. Socle de test `QueryBusWebTestCase` (bus doublé, utilisateur, JWT) partagé par les tests de controllers.
- [x] `ListUnits` — AdminGetAllUnit, et le ré-affichage de la liste après création / modification (AdminCreateUnit, AdminUpdateUnit). DTO `UnitDTO` (slug, name, symbol), `UnitLoader` / `UnitPostgreSQLLoader` (une requête, tri par slug), `GET /api/v1/units`. `AdminUpdateUnitViewController` garde `UnitRepository::findOneBySlug` pour pré-remplir son formulaire (lecture de formulaire, hors périmètre de cette slice). Premiers tests des controllers de formulaire (POST valide avec jeton CSRF stateless, `HTTP_ORIGIN`) ; le test factice `AddNewUnitControllerTest` (`assertTrue(true)`) est supprimé avec son entrée de baseline PHPStan.
- [x] `GetCurrentUser` (`GET /api/v1/me`, remplace `app.user` / `current_user.getUser().id` côté front). Query `GetCurrentUserQuery(userId)` (l'identifiant vient du token via `#[CurrentUser]`), DTO `CurrentUserDTO` (id, email, roles, isAdmin, defaultServing ; jamais de mot de passe ni de statistiques), `UserLoader` / `UserPostgreSQLLoader` (`UserException::NotFound` si absent, donc 404). Première slice du contexte `User` : `Application/Query/` et `Application/Loader/` y sont créés comme dans `Repas` (les règles Deptrac sont par namespace). Aucune page Twig touchée : la navbar garde `app.user` (sécurité, pas une lecture de repository). `JwtAuthenticationTest` ne dépend plus des fixtures et `/me` remplace la route d'essai `whoami` ; la route d'essai `admin` reste tant qu'aucun endpoint API n'exige `ROLE_ADMIN` (ADR 0008 §9). Premier test de bout en bout du chemin réel firewall JWT, bus, handler, loader.

Valide de bout en bout : bus, Loader, Deptrac, API, JWT. Risque faible.

### Phase 2 — Listes de courses (liste)

- [x] `ListShoppingLists` — GetAllShoppingList, Activated/Paused/Stopped (re-read du fragment `#shopping-list-list`). Query `ListShoppingListsQuery(ownerId)`, DTO `ShoppingListDTO` (id, displayName, status, isActive, isPaused, isCompleted, createdAt ATOM), `ShoppingListLoader` / `ShoppingListPostgreSQLLoader` (une requête, du plus récent au plus ancien), `GET /api/v1/shopping-lists`. `displayName` = nom saisi, sinon la date de création en français (`dd MMMM YYYY`, comme l'ancien template). Sur la page avec 4 listes : 14 requêtes SQL avant, 2 après ; plus aucun test de statut ni `format_datetime` dans `_shopping_list_list.html.twig`. Les 4 controllers n'importent plus `User\Domain\Model\User` (interface Shared `AuthenticatedUser`, ADR 0001 §8), 4 entrées retirées de la baseline Deptrac. Reste `GetOneShoppingListViewController` et le détail (phase 4) : la page détail affiche « Liste du … » là où la liste affiche la date seule, à unifier alors dans le DTO.

### Phase 3 — Résumé de la liste active

- [x] `GetActiveShoppingListSummary` — GetOneDepartment, GetOneRecipeType, Add/RemoveIngredientToActive, Add/RemoveRecipeToActive. Query `GetActiveShoppingListSummaryQuery(ownerId)`, DTO `ActiveShoppingListSummaryDTO` (`shoppingListId` ou null, `quantityByIngredientSlug`, `plannedRecipeIds`), `ShoppingListLoader::getActiveSummary()` (3 requêtes bornées : liste active, lignes, repas), `GET /api/v1/shopping-lists/active/summary` (sans liste active : 200, identifiant null et collections vides). `_recipe_row` et `_ingredient_row` ne rappellent plus `hasRecipe` ni `getIngredientQuantity` ; le résumé remplace la liste de courses complète dans les 2 pages et les 4 fragments. Rayon : 13 → 8 requêtes SQL, type de recette : 14 → 6, HTML identique. Premiers tests de ces 6 controllers. Restent dans ces controllers : `ingredient`, `recipe` et `department` en modèles (phases 5 et 6, dont `current_user` de `_ingredient_row`) et, dans les 2 controllers `Add…`, le « créer la liste active si absente » (commande `EnsureActiveShoppingList`, phase 4).

### Phase 4 — Liste de courses (détail)

- [x] Commande `EnsureActiveShoppingList(shoppingListId, ownerId)` : ne fait rien si l'utilisateur a une liste active, sinon la crée (via `CreateShoppingListHandler`). Nouveau port `ShoppingListRepository::hasActiveShoppingList(ownerId)` (sans modèle `User`, ADR 0001 §7). Remplace le bloc « créer la liste active si absente » dupliqué dans `AddIngredientToActive…` et `AddRecipeToActive…`, qui n'injectent plus de `ShoppingListRepository` ni `User`. `GetActiveShoppingListViewController` garde son comportement (liste active, sinon la plus récente, sinon création avec `CreateShoppingListCommand`) ; pas de changement visible.
- [x] `GetShoppingList` — GetOne, GetActive, UpdateServingMeal, Renamed, Tick/Uncheck. Quatre queries sur `ShoppingListLoader` / `ShoppingListRowLoader` : `GetShoppingListQuery(id)`, `GetCurrentShoppingListQuery(ownerId)` (active, sinon la plus récente, sinon null), `GetShoppingListByMealQuery(mealId)`, `GetShoppingListRowQuery(rowId)` (fragments cocher / décocher). DTO `ShoppingListDetailDTO` : identité (`name`, `displayName` « Liste du … » par défaut, `dateLabel`, statut et flags), `rowCount` / `recipeCount`, ingrédients pré-groupés par rayon (`departments` → `rows`), recettes par type (`recipeTypes` → `meals`). 3 requêtes bornées par liste ; plus de `usort` ni de boucle rayons × lignes dans Twig. `GET /api/v1/shopping-lists/{id}` (voter propriétaire `SHOPPING_LIST_OWNER`). Page détail : 34 → 4 requêtes SQL, HTML identique. Tri : rayons par slug, lignes par slug d'ingrédient (en PHP, la collation PostgreSQL ordonne autrement les tirets), types par ordre d'affichage, repas d'un type par nom de recette (l'ancien ordre n'était pas défini). Premiers tests de ces 6 controllers.

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
- [ ] Supprimer `ModelCache` (cache maison des modèles hydratés, devenu `ResetInterface` en phase 2 pour être vidé entre deux requêtes en mode worker) dès que plus aucune lecture ne passe par un modèle : il ne servait qu'à éviter des hydratations répétées.
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

- Le motif de date `dd MMMM YYYY` (templates des listes de courses et `ListShoppingLists`) utilise l'année ISO de la semaine : le 29 décembre 2025 s'affiche « 29 décembre 2026 ». Conservé tel quel pour ne pas changer l'affichage ; remplacer `YYYY` par `yyyy` partout en une fois (loader, `_shopping_list_card_header`, `shopping_list.html.twig`).
- `LoginCheckViewController` rend `login_check/index.html.twig`, template inexistant.
- `RecipeTypePostgreSQLRepository::findOneBySlug` interroge le cache avec `Recipe::class`.
- `RecipePostgreSQLRepository::convertEntityToModel` : clé de cache = slug de la recette, pas son id.
- `phpunit.dist.xml` : `SYMFONY_PHPUNIT_VERSION=9.6` obsolète (PHPUnit 12 installé).
- `symfony/translation` absent alors que `login.html.twig` utilise `|trans`.
- `RecipeRowRepository` : interface vide, le repository concret est injecté.
- `admin_recipe_type_row.html.twig` : la ligne porte `id="department-…"` (copier-coller du rayon), à renommer quand un test ou du JS la cible.
- Formulaires admin : `conversion|default(null)` / `department|default(null)` / `unit|default(null)` jamais fournis par les controllers update, le titre reste « Nouveau … ».

## Couverture de tests (cliquet)

- Seuil CI : **80 %** (`COVERAGE_THRESHOLD`, `.github/workflows/ci.yml`). Mesure au 2026-10-13 : 77,1 %, puis 81,8 % à la fin de la phase 2, d'où le passage de 75 à 80. Le seuil de 80 % d'origine n'était plus tenu avant la migration (57,2 %, toute la couche Twig non testée).
- Exclus de la mesure (`phpunit.dist.xml`) : `Http/Controller` et `Http/Form` des contextes `Repas` et `User` (ils sortent de l'exclusion en même temps que du code, phase 9 ; les controllers API restent mesurés), et les `DataFixture` (données de test chargées hors d'un test, donc jamais créditées).
- Règle : le seuil ne baisse jamais. Le remonter par paliers quand la mesure le permet (les slices Query/Loader/API entrent à 100 %, donc le chiffre monte avec la migration).
- Plus gros trous restants (éléments non couverts) : `Infrastructure/Entity` 172, `Domain/Model` 134, commandes console `src/Command` 113, `Infrastructure/Repository` 89.

## Inventaire des lectures directes (état initial, 2026-10-12)

- 15 controllers de lecture pure (10 `Get*`, 4 `AdminGetAll*` Repas, `AdminGetUsers`).
- 11 controllers AJAX hybrides (commande puis relecture puis fragment HTML) : Activated, Paused, Stopped, AddIngredientToActive, RemoveIngredientToActive, AddRecipeToActive, RemoveRecipeToActive, TickLine, UncheckLine, RenamedShoppingList, UpdateServingMeal.
- 12 controllers de formulaire qui lisent pour pré-remplir.
- 6 form types qui injectent des repositories pour leurs choix.
- `OwnerVoter` (3 repositories).
- 1 loader legacy (`RecipeLoader`, utilisé par GetOneRecipeType et GetAllOtherPeopleRecipes).
- 0 query handler, `Application/Query` vide.
