# Roadmap de migration : lecture CQRS et API

Suivi vivant. Décisions : [`adr/`](adr/README.md) (surtout [0003](adr/0003-cqrs-lecture-ecriture.md), [0009](adr/0009-strategie-de-migration.md)). Le skill `repas-dev` lit et met à jour ce fichier à chaque slice.

**Objectif** : toutes les lectures passent par `Query → QueryHandler → Loader → DTO` ; de nouveaux controllers API JSON (`/api/v1`, stateless JWT) sont consommés par un front ; les routes Twig/AJAX actuelles (session) coexistent.

**Definition of Done d'une slice** : voir [ADR 0009](adr/0009-strategie-de-migration.md) §6. En bref : `make test deptrac phpstan api-doc-validate` verts, plus aucun `Repository` ni appel de domaine dans le controller/template migré, tests (handler, Loader sur base réelle, controller API avec clés JSON), test visuel Chromium des pages Twig touchées, cette roadmap à jour.

## Phase 0 — Fondations

- [x] ADR 0001 à 0012 (`docs/adr/`) ; 0013 ajouté en phase 8
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
- [x] `GetCurrentUser` (`GET /api/v1/me`, remplace `app.user` / `current_user.getUser().id` côté front). Query `GetCurrentUserQuery(userId)` (l'identifiant vient du token via `#[CurrentUser]`), DTO `CurrentUserDTO` (id, email, roles, isAdmin, defaultServing ; jamais de mot de passe ni de statistiques), `UserLoader` / `UserPostgreSQLLoader` (`UserException::NotFound` si absent, donc 404). Première slice du contexte `User` : `Application/Query/` et `Application/Loader/` y sont créés comme dans `Repas` (les règles Deptrac sont par namespace). Aucune page Twig touchée : la navbar garde `app.user` (sécurité, pas une lecture de repository). `JwtAuthenticationTest` ne dépend plus des fixtures et `/me` remplace la route d'essai `whoami` ; la route d'essai `admin` a été remplacée en phase 6 par `GET /api/v1/conversions` (ADR 0008 §9). Premier test de bout en bout du chemin réel firewall JWT, bus, handler, loader.

Valide de bout en bout : bus, Loader, Deptrac, API, JWT. Risque faible.

### Phase 2 — Listes de courses (liste)

- [x] `ListShoppingLists` — GetAllShoppingList, Activated/Paused/Stopped (re-read du fragment `#shopping-list-list`). Query `ListShoppingListsQuery(ownerId)`, DTO `ShoppingListDTO` (id, displayName, status, isActive, isPaused, isCompleted, createdAt ATOM), `ShoppingListLoader` / `ShoppingListPostgreSQLLoader` (une requête, du plus récent au plus ancien), `GET /api/v1/shopping-lists`. `displayName` = nom saisi, sinon la date de création en français (`dd MMMM YYYY`, comme l'ancien template). Sur la page avec 4 listes : 14 requêtes SQL avant, 2 après ; plus aucun test de statut ni `format_datetime` dans `_shopping_list_list.html.twig`. Les 4 controllers n'importent plus `User\Domain\Model\User` (interface Shared `AuthenticatedUser`, ADR 0001 §8), 4 entrées retirées de la baseline Deptrac. Reste `GetOneShoppingListViewController` et le détail (phase 4) : la page détail affiche « Liste du … » là où la liste affiche la date seule, à unifier alors dans le DTO.

### Phase 3 — Résumé de la liste active

- [x] `GetActiveShoppingListSummary` — GetOneDepartment, GetOneRecipeType, Add/RemoveIngredientToActive, Add/RemoveRecipeToActive. Query `GetActiveShoppingListSummaryQuery(ownerId)`, DTO `ActiveShoppingListSummaryDTO` (`shoppingListId` ou null, `quantityByIngredientSlug`, `plannedRecipeIds`), `ShoppingListLoader::getActiveSummary()` (3 requêtes bornées : liste active, lignes, repas), `GET /api/v1/shopping-lists/active/summary` (sans liste active : 200, identifiant null et collections vides). `_recipe_row` et `_ingredient_row` ne rappellent plus `hasRecipe` ni `getIngredientQuantity` ; le résumé remplace la liste de courses complète dans les 2 pages et les 4 fragments. Rayon : 13 → 8 requêtes SQL, type de recette : 14 → 6, HTML identique. Premiers tests de ces 6 controllers. Restent dans ces controllers : `ingredient`, `recipe` et `department` en modèles (phases 5 et 6, dont `current_user` de `_ingredient_row`) et, dans les 2 controllers `Add…`, le « créer la liste active si absente » (commande `EnsureActiveShoppingList`, phase 4).

### Phase 4 — Liste de courses (détail)

- [x] Commande `EnsureActiveShoppingList(shoppingListId, ownerId)` : ne fait rien si l'utilisateur a une liste active, sinon la crée (via `CreateShoppingListHandler`). Nouveau port `ShoppingListRepository::hasActiveShoppingList(ownerId)` (sans modèle `User`, ADR 0001 §7). Remplace le bloc « créer la liste active si absente » dupliqué dans `AddIngredientToActive…` et `AddRecipeToActive…`, qui n'injectent plus de `ShoppingListRepository` ni `User`. `GetActiveShoppingListViewController` garde son comportement (liste active, sinon la plus récente, sinon création avec `CreateShoppingListCommand`) ; pas de changement visible.
- [x] `GetShoppingList` — GetOne, GetActive, UpdateServingMeal, Renamed, Tick/Uncheck. Quatre queries sur `ShoppingListLoader` / `ShoppingListRowLoader` : `GetShoppingListQuery(id)`, `GetCurrentShoppingListQuery(ownerId)` (active, sinon la plus récente, sinon null), `GetShoppingListByMealQuery(mealId)`, `GetShoppingListRowQuery(rowId)` (fragments cocher / décocher). DTO `ShoppingListDetailDTO` : identité (`name`, `displayName` « Liste du … » par défaut, `dateLabel`, statut et flags), `rowCount` / `recipeCount`, ingrédients pré-groupés par rayon (`departments` → `rows`), recettes par type (`recipeTypes` → `meals`). 3 requêtes bornées par liste ; plus de `usort` ni de boucle rayons × lignes dans Twig. `GET /api/v1/shopping-lists/{id}` (voter propriétaire `SHOPPING_LIST_OWNER`). Page détail : 34 → 4 requêtes SQL, HTML identique. Tri : rayons par slug, lignes par slug d'ingrédient (en PHP, la collation PostgreSQL ordonne autrement les tirets), types par ordre d'affichage, repas d'un type par nom de recette (l'ancien ordre n'était pas défini). Premiers tests de ces 6 controllers.

### Phase 5 — Recettes

- [x] `GetRecipe` — GetOneRecipe, prefill UpdateRecipe, re-lecture des fragments Add/RemoveRecipeToActive. Query `GetRecipeQuery(id)`, DTO `RecipeDTO` (id, name, serving, typeSlug, typeName, rows) et `RecipeRowDTO` (ingrédient, quantité, unité), `GET /api/v1/recipes/{id}` (lisible par tout utilisateur authentifié, comme sur le site ; `{id}` contraint à un UUID pour ne pas capter `other-people`). `UpdateRecipeType` et `UpdateRecipeRowType` se préremplissent depuis le DTO ; `CreateRecipe` et `UpdateRecipe` ne relisent plus la recette (la commande porte déjà `typeSlug`). Page recette : 8 → 3 requêtes SQL.
- [x] `ListRecipesByType` — GetOneRecipeType. Query `ListRecipesByTypeQuery(authorId, typeSlug)`, DTO `RecipeTypeRecipesDTO` (le type + `RecipeSummaryDTO` id, name, triés par slug), `GET /api/v1/recipe-types/{slug}/recipes` (`{recipeType, member, totalItems}`). Type inconnu : `RecipeException::typeNotFound`.
- [x] `ListOtherPeopleRecipes` — GetAllOtherPeopleRecipes. Query `ListOtherPeopleRecipesQuery(userId)`, DTO `OtherPeopleRecipeDTO` (id, name, typeName, triés par slug), `GET /api/v1/recipes/other-people`. Une seule requête (plus de recettes chargées une à une) : 7 → 2.
- [x] `RecipeLoader` legacy remplacé par l'interface `Application\Loader\RecipeLoader` + `RecipePostgreSQLLoader` (DTO) ; supprimés : l'ancienne classe `Infrastructure\Loader\RecipeLoader` et son test, le doublon `RecipeRepository::findByAuthorAndType`, leurs entrées de baseline Deptrac et PHPStan. 3 entrées de baseline Deptrac de controllers en moins. `AbstractRecipeType` lit toujours `RecipeTypeRepository` pour ses choix (phase 7).

### Phase 6 — Ingrédients et conversions

- [x] `ListIngredientsByDepartment` — GetOneDepartment. Query `ListIngredientsByDepartmentQuery(slug, viewerId, viewerIsAdmin)`, DTO `DepartmentIngredientsDTO` (rayon + `IngredientRowDTO` : slug, name, purchaseUnitSymbol, `canEdit` calculé côté serveur : ingrédient de l'utilisateur, ou commun et utilisateur administrateur), `GET /api/v1/departments/{slug}/ingredients`. Retire `current_user.getUser()` de `_ingredient_row`. Rayon : 8 → 6 requêtes SQL, formulaire d'ingrédient : 9 → 6, HTML identique.
- [x] `GetIngredient` — prefill UpdateIngredient, fragments Add/RemoveIngredientToActive, redirection de RemoveIngredient. Query `GetIngredientQuery(slug, viewerId, viewerIsAdmin)`, DTO `IngredientDTO` (rayon, unités, `hasSameUnit`, `coefficient` précalculé, `canEdit`), `GET /api/v1/ingredients/{slug}`. Un ingrédient créé par un autre utilisateur n'existe pas (404) pour qui n'est pas administrateur. `UpdateIngredientType` n'injecte plus `ConversionRepository` (le coefficient vient du DTO).
- [x] `ListCompatibleUnits` — GetCompatibleUnitsForIngredient. Query `ListCompatibleUnitsQuery(slug)`, DTO `CompatibleUnitDTO` (slug, nom en majuscule initiale), `GET /api/v1/ingredients/{slug}/units` ; la route JSON du site garde son contrat `{units:[{name, slug}]}`. Ordre : celui de l'enregistrement de l'ingrédient (l'ancien ordre dépendait du planificateur PostgreSQL).
- [x] `ListConversions` — AdminGetAllConversion, et `GetConversion` pour le prefill de AdminUpdateConversion. DTO `ConversionDTO`, `ConversionLoader` / `ConversionPostgreSQLLoader` (2 requêtes ; l'email du créateur est lu en SQL dans la table `user` pour ne pas importer l'entité du contexte `User`), `GET /api/v1/conversions` réservé à `ROLE_ADMIN`. Page admin : 79 → 2 requêtes SQL. Il remplace la route d'essai `_probe/admin`, supprimée avec `config/routes/test/` (ADR 0008 §9). Deux conversions ex æquo (même ingrédient, même unité de départ) sont départagées par id.

### Phase 7 — Dernières lectures directes

- [x] Options de formulaires : `SelectDepartmentType`, `SelectUnitType`, `SelectIngredientType` (`ListAvailableIngredients`), `AbstractRecipeType` (types de recette, portion par défaut via `AuthenticatedUser::getDefaultServing()`), préremplissage des formulaires admin par `GetUnit` / `GetDepartment` / `GetRecipeType`, `RegisterNewUserType` (`IsEmailAlreadyUsed`, prédicat booléen : nommer `Is{X}` / `Has{X}`)
- [x] `OwnerVoter` : port `Shared\Application\Interface\OwnershipLoader` (renvoie des ids, jamais un agrégat) implémenté par `Repas\Infrastructure\Loader\OwnershipPostgreSQLLoader` ; le contexte `User` n'importe plus rien de `Repas` ici (entrée de baseline retirée). Le trou « ingrédient commun » est fermé : `INGREDIENT_OWNER` renvoie `false` (seul un administrateur le modifie).
- [x] `ListUsers` — AdminGetUsers (`UserSummaryDTO`) et `GET /api/v1/users` (ROLE_ADMIN) ; réglages et inscription via `GetCurrentUser` / `IsEmailAlreadyUsed` ; le mot de passe actuel est vérifié par `UpdateUserSettingsHandler` (règle de domaine), le controller traduit `UserException::INVALID_PASSWORD` en erreur de formulaire
- [x] Endpoints API de lecture unitaire : `GET /api/v1/units/{slug}`, `/departments/{slug}`, `/recipe-types/{slug}`
- [x] Deptrac : `deptrac.no-repository-in-http.yaml` interdit tout `*Repository` dans `…\Infrastructure\Http\…` (controllers, API, formulaires) des deux contextes ; aucune baseline, 0 violation
- [ ] ~~Supprimer `ModelCache`~~ **Conservé** : plus aucune *lecture* n'en dépend (lecture détail liste : 4 requêtes, liste des recettes : 2, identiques avec ou sans cache), mais le chemin d'*écriture* (handlers qui chargent des agrégats) en profite encore. Mesure en dev, cache neutralisé : `serving` 45 → 126 requêtes, `tick` / `uncheck` 46 → 108, ajout ingrédient 57 → 119, retrait 48 → 112, planification recette 88 → 171, retrait recette 55 → 159. Le chantier est la phase 8 : on allège d'abord les écritures, on retire le cache ensuite.

## Écriture et extinction

### Phase 8 — Écritures allégées, puis retrait de `ModelCache`

**Constat (2026-10-14).** Les lectures ne passent plus par `ModelCache`, les écritures si. Un handler charge l'agrégat entier (liste → repas → recettes → lignes → ingrédients → rayons, unités, créateurs) même pour changer un champ : renommer une liste coûte 28 requêtes SQL, cocher une ligne 28 aussi, dont l'essentiel vient de `StopListEventListener` qui recharge toute la liste pour savoir si toutes les lignes sont cochées. `ModelCache` masque ce surcoût : sans lui, les mêmes use cases font 58 et 59 requêtes (la différence vient de `UnitRepository::findBySlugs`, qui n'évite le SQL que grâce au cache).

**Décision.** On allège d'abord les use cases (ne charger que ce que le use case modifie ou vérifie), puis on retire `ModelCache`, **sans aucune régression** de requêtes. Retirer le cache avant ne ferait que doubler les requêtes d'écriture (mesuré en phase 7).

**Outils (faits).**
- [x] `tests/Repas/WriteBudget` : 32 use cases d'écriture mesurés de bout en bout (bus, handler, repositories, listeners, vraie base), avec et sans `ModelCache`, sur un cas typique (3 recettes, une liste active). Budgets figés dans `budgets.php`, en cliquet : plus de requêtes = régression (test rouge) ; moins = baisser le budget (`make sql-budget-update`). Compteur : middleware DBAL de test `tests/Helper/SqlBudget` ([0010](adr/0010-strategie-de-tests.md) règle 12).
- [x] `make cache-removal-check` : feu vert quand aucun use case ne fait plus de requêtes sans cache qu'avec.

**État** (requêtes SQL : avec cache / sans cache ; « départ » = mesure du 2026-10-14 avant la phase 8) :

| Use case | Départ | Maintenant | Reste à alléger |
|---|---|---|---|
| `tick-ligne`, `decocher-ligne`, `tick-derniere-ligne` | 28 / 59, 28 / 59, 33 / 107 | 3 / 3, 7 / 7, 13 / 13 | rien d'utile |
| `renommer-liste`, `mettre-liste-en-pause`, `reactiver-liste`, `terminer-liste`, `supprimer-liste` | 28–29 / 58–59 | 9–10 / 9–10 | rien d'utile (liste = 5 requêtes) |
| `ajouter-ingredient-liste`, `retirer-ingredient-liste` | 32 / 63, 28 / 61 | 16 / 16, 12 / 12 | charger l'ingrédient (rayon, unités, créateur) alors que seuls le slug et l'unité d'achat servent |
| `planifier-repas`, `retirer-repas`, `modifier-portions-repas` | 39 / 69, 30 / 74, 35 / 79 | 29 / 40, 21 / 30, 26 / 35 | graphe recette → ingrédients → conversions lu ligne à ligne : préchargement par lot |
| `supprimer-ingredient`, `supprimer-recette` | 12 / 13, 14 / 22 | inchangé | `findByIngredient` / `findByRecipe` hydratent des agrégats entiers pour une vérification d'existence : ports booléens |
| `creer-recette`, `modifier-recette`, `copier-recette`, `creer-ingredient`, `modifier-ingredient` | 8–29 / 8–36 | inchangé | un `findOneBySlug` par ligne (ingrédient, unité) : préchargement par lot |
| `creer-liste`, `assurer-liste-active` | 5 / 8, 6 / 9 | inchangé | l'utilisateur est relu plusieurs fois sans cache |
| référentiels, conversions, utilisateurs | 2–8 / 2–9 | inchangé | rien à faire |

**Slices d'allègement** (une par ligne du tableau, dans l'ordre d'impact ; chacune baisse des budgets et le commit inclut `budgets.php`) :
- [x] Cocher / décocher une ligne, fin de liste automatique : la ligne, l'ingrédient de liste et le repas référencent désormais leurs agrégats par identifiant ([0013](adr/0013-agregats-references-par-identifiant.md)), `ShoppingListRowRepository::allRowsChecked` remplace le rechargement de la liste par `StopListEventListener`
- [x] Opérations d'en-tête d'une liste (renommer, pause, réactiver, terminer, supprimer) : obtenues par la même refonte, sans port dédié
- [x] Ajouter / retirer un ingrédient de la liste : la liste se charge en 5 requêtes ; reste le chargement de l'ingrédient lui-même
- [ ] Planifier / retirer un repas, modifier les portions (`UpdateServingMealHandler` charge désormais la recette de son repas par `RecipeRepository`)
- [ ] Suppressions d'ingrédient et de recette (vérifications d'existence)
- [ ] Création de liste et liste active assurée (utilisateur relu plusieurs fois sans cache)
- [ ] Création et modification de recettes et d'ingrédients (préchargement par lot)

**Retrait de `ModelCache`** (quand `make cache-removal-check` est vert) :
- [ ] Corriger `Unit` / `Department::findBySlugs` pour s'appuyer sur l'identity map de Doctrine (mesuré en phase 7 : mêmes requêtes qu'avec le cache, +5 à 10 % de temps sur une liste de 18 repas)
- [ ] Retirer `cachedByRecipe` (préchargement qui ne fonctionne que par le cache) et les ~70 appels des 8 repositories ; trois lectures ne touchaient de toute façon jamais (`Recipe` lue par slug mais stockée par id, `RecipeType::findOneBySlug` lue sous `Recipe::class`, `Unit::findBySlugs` lue sous `UnitEntity::class`)
- [ ] Supprimer `ModelCache`, `ModelCacheTest`, le mock de `DatabaseTestCase` et, dans `WriteBudgetTest`, la variante « avec cache » ; mettre à jour CLAUDE.md et ADR 0001

### Phase 9 — Endpoints API d'écriture

- [ ] Corriger les trous d'autorisation (voir plus bas)
- [ ] Endpoints d'écriture pour les 28 use cases (`#[MapRequestPayload]`, 204 / 201 + `Location`)
- [ ] Remplacer le protocole `{views:[{selector, html}]}` par de l'état ressource côté API (le front re-requête ; `ajax.js` et les 14 controllers AJAX restent pour Twig)

### Phase 10 — Extinction de Twig

- [ ] À décider produit : quels `ViewController` retirer quand le front les remplace (ADR dédié le moment venu)

## Trous d'autorisation à corriger avant la phase 9

- `AdminCreateUnitViewController` (`/admin/unit/create`) et `CreateIngredientViewController` (`/ingredient`) sans `#[IsGranted]`.
- Routes `tick` / `uncheck` d'une ligne : `ROLE_USER` sans voter propriétaire.
- `RemoveIngredientViewController` : GET destructif.
- ~~`OwnerVoter` `INGREDIENT_OWNER` retourne `null` pour un ingrédient système~~ : corrigé en phase 7.

## Hors périmètre (vus en passant, non corrigés)

- Lignes d'une recette : aucune colonne de position, l'ordre affiché est l'ordre de stockage (celui de la saisie tant que la recette n'est pas modifiée, puis variable). `RecipePostgreSQLLoader` le conserve à l'identique ; ajouter une position si l'ordre doit être garanti.
- Le motif de date `dd MMMM YYYY` (templates des listes de courses et `ListShoppingLists`) utilise l'année ISO de la semaine : le 29 décembre 2025 s'affiche « 29 décembre 2026 ». Conservé tel quel pour ne pas changer l'affichage ; remplacer `YYYY` par `yyyy` partout en une fois (loader, `_shopping_list_card_header`, `shopping_list.html.twig`).
- `LoginCheckViewController` rend `login_check/index.html.twig`, template inexistant.
- `RecipeTypePostgreSQLRepository::findOneBySlug` interroge le cache avec `Recipe::class`.
- `RecipePostgreSQLRepository::convertEntityToModel` : clé de cache = slug de la recette, pas son id.
- `ConversionService::$graphs` : graphe de conversions mis en cache par ingrédient dans le service, jamais vidé ; en mode worker (FrankenPHP) il survit aux requêtes et reste périmé après la modification d'une conversion tant que le worker n'est pas redémarré. À rendre `ResetInterface` (comme `ModelCache`).
- `phpunit.dist.xml` : `SYMFONY_PHPUNIT_VERSION=9.6` obsolète (PHPUnit 12 installé).
- `symfony/translation` absent alors que `login.html.twig` utilise `|trans`.
- `RecipeRowRepository` : interface vide, le repository concret est injecté.
- `admin_recipe_type_row.html.twig` : la ligne porte `id="department-…"` (copier-coller du rayon), à renommer quand un test ou du JS la cible.
- Formulaires admin : `conversion|default(null)` / `department|default(null)` / `unit|default(null)` jamais fournis par les controllers update, le titre reste « Nouveau … ».

## Couverture de tests (cliquet)

- Seuil CI : **80 %** (`COVERAGE_THRESHOLD`, `.github/workflows/ci.yml`). Mesure au 2026-10-13 : 77,1 %, puis 81,8 % à la fin de la phase 2, d'où le passage de 75 à 80. Le seuil de 80 % d'origine n'était plus tenu avant la migration (57,2 %, toute la couche Twig non testée).
- Exclus de la mesure (`phpunit.dist.xml`) : `Http/Controller` et `Http/Form` des contextes `Repas` et `User` (ils sortent de l'exclusion en même temps que du code, phase 10 ; les controllers API restent mesurés), et les `DataFixture` (données de test chargées hors d'un test, donc jamais créditées).
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
