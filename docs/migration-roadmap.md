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
- [x] `ModelCache` : conservé à la fin de la phase 7 (sans lui les écritures doublaient : `serving` 45 → 126 requêtes, `tick` 46 → 108, planification 88 → 171), retiré en phase 8 une fois les écritures allégées, sans régression.

## Écriture et extinction

### Phase 8 — Écritures allégées, puis retrait de `ModelCache` — retrait fait

**Constat (2026-10-14).** Les lectures ne passaient plus par `ModelCache`, les écritures si. Un handler chargeait l'agrégat entier (liste → repas → recettes → lignes → ingrédients → rayons, unités, créateurs) même pour changer un champ : renommer une liste coûtait 28 requêtes SQL, cocher une ligne 28 aussi, dont l'essentiel venait de `StopListEventListener` qui rechargeait toute la liste. `ModelCache` masquait ce surcoût : sans lui, les mêmes use cases faisaient 58 et 59 requêtes.

**Décision.** Alléger d'abord les use cases, retirer `ModelCache` ensuite, **sans aucune régression** de requêtes. Retirer le cache avant n'aurait fait que doubler les requêtes d'écriture (mesuré en phase 7).

**Outils.**
- [x] `tests/Repas/WriteBudget` : 32 use cases d'écriture mesurés de bout en bout (bus, handler, repositories, listeners, vraie base) sur un cas typique (3 recettes, une liste active). Budgets figés dans `budgets.php`, en cliquet : plus de requêtes = régression (test rouge) ; moins = baisser le budget (`make sql-budget-update`). Compteur : middleware DBAL de test `tests/Helper/SqlBudget` ([0010](adr/0010-strategie-de-tests.md) règle 12). `SQL_BUDGET_VERBOSE=1` affiche la répartition par table.
- [x] Pendant le chantier, chaque budget existait avec et sans cache et un script de feu vert vérifiait qu'aucun use case ne coûtait plus sans cache ; il a servi à décider du retrait, puis a été supprimé avec lui.

**Résultat** (requêtes SQL ; « départ » = avec cache / sans cache le 2026-10-14, avant la phase 8) :

| Use case | Départ | Maintenant |
|---|---|---|
| `tick-ligne`, `decocher-ligne`, `tick-derniere-ligne` | 28 / 59, 28 / 59, 33 / 107 | 3, 7, 13 |
| `renommer-liste`, `mettre-liste-en-pause`, `reactiver-liste`, `terminer-liste`, `supprimer-liste` | 28–29 / 58–59 | 9–10 |
| `ajouter-ingredient-liste`, `retirer-ingredient-liste` | 32 / 63, 28 / 61 | 16, 12 |
| `planifier-repas`, `retirer-repas`, `modifier-portions-repas` | 39 / 69, 30 / 74, 35 / 79 | 29, 21, 26 |
| `supprimer-ingredient`, `supprimer-recette` | 12 / 13, 14 / 22 | 12, 14 |
| `creer-recette`, `modifier-recette`, `copier-recette`, `creer-ingredient`, `modifier-ingredient` | 8–29 / 8–36 | 8, 23, 25, 29, 8 |
| `creer-liste`, `assurer-liste-active` | 5 / 8, 6 / 9 | 5, 6 |
| référentiels, conversions, utilisateurs | 2–8 / 2–9 | 2–4 |

Total des 32 scénarios : 328 requêtes, avec comme sans cache à l'arrivée.

**Ce qui a changé.**
- [x] La ligne de courses, l'ingrédient de liste et le repas référencent leurs agrégats par identifiant ([0013](adr/0013-agregats-references-par-identifiant.md)) : une liste se charge en 5 requêtes. `ShoppingListRowRepository::allRowsChecked` remplace le rechargement de la liste par `StopListEventListener`.
- [x] `PostgreSQLRepository::preload()` : lecture par lot des entités absentes de l'identity map de Doctrine, utilisée par `Unit` / `Department::findBySlugs` et `IngredientPostgreSQLRepository::preloadByRecipe` (ex-`cachedByRecipe`, sorti du port du domaine). Seule fonction qui dépendait vraiment du cache : tout l'écart « sans cache » mesuré était `SELECT unit`.
- [x] Nettoyage des listes terminées à la création d'une liste par identifiants (`findIdsByOwnerAndStatus`, l'événement porte le propriétaire).
- [x] `ModelCache` supprimé, avec ses ~70 appels dans les 8 repositories (trois lectures ne touchaient de toute façon jamais : `Recipe` lue par slug mais stockée par id, `RecipeType::findOneBySlug` lue sous `Recipe::class`, `Unit::findBySlugs` lue sous `UnitEntity::class`) et le mock de `DatabaseTestCase`. Chaque use case a gardé exactement son budget.

**Reste à alléger (optionnel, plus bloquant)** : le cliquet empêche toute régression ; ces points sont des gains supplémentaires.
- [ ] Planifier / retirer un repas, modifier les portions : graphe recette → ingrédients → conversions encore lu ligne à ligne, aux 5 lignes de liste près
- [ ] Suppressions d'ingrédient et de recette : `findByIngredient` / `findByRecipe` hydratent des agrégats entiers pour une vérification d'existence (ports booléens)
- [ ] Création et modification de recettes : un `SELECT recipe_row` par ligne à l'enregistrement, un `find` par unité
- [ ] Ajout et retrait d'un ingrédient de liste : l'ingrédient est chargé avec son rayon, ses unités et son créateur alors que seuls son slug et son unité d'achat servent

### Phase 9 — Endpoints API d'écriture

- [x] Corriger les trous d'autorisation (voir plus bas) : fait, avec une mutation de plus trouvée à l'audit
- [x] **Listes de courses** (14 endpoints, conventions dans [0007](adr/0007-api-rest-v1.md) §11 à 13) :
  - `POST /shopping-lists` (201 + `Location`), `PUT /shopping-lists/active` (s'assure d'une liste active), `PATCH /shopping-lists/{id}` (renommer), `DELETE /shopping-lists/{id}`
  - `POST /shopping-lists/{id}/activate|pause|complete`, `POST /shopping-list-rows/{id}/tick|uncheck`, `PUT /meals/{id}/serving`
  - `PUT` / `DELETE /shopping-lists/active/recipes/{recipeId}` (planifier, retirer), `POST /shopping-lists/active/ingredients/{slug}/increase|decrease`
  - Codes de domaine alignés : `activeShoppingListNotFound` 404, `recipeAlreadyInList` 409
- [x] **Recettes** (4 endpoints) : `POST /recipes` (201 + `Location`), `PUT /recipes/{id}` (remplacement complet, les lignes absentes du corps sont supprimées), `POST /recipes/{id}/copy` (201 + `Location` de la copie), `DELETE /recipes/{id}` (409 si la recette est dans une liste). Le corps (`RecipePayload`) exige au moins une ligne, 1 à 100 personnes, des quantités positives.
  - `CopyRecipeCommand` reçoit l'id de la copie (`newRecipeId`), comme les autres créations, pour pouvoir rendre le `Location`
  - Règle de domaine ajoutée : un ingrédient personnalisé n'est utilisable que dans les recettes de son créateur (ingrédient d'un autre = inconnu, 404). Le formulaire Twig ne proposait que les siens, l'API aurait laissé référencer n'importe quel slug
  - Corrigé en passant : supprimer une recette supprime ses lignes (migration `Version20261014120000` pour les lignes orphelines existantes) ; `supprimer-recette` passe de 14 à 15 requêtes (le `DELETE recipe_row`, budget relevé en connaissance de cause)
- [x] **Ingrédients et conversions** (6 endpoints) : `POST /ingredients` (201 + `Location`), `PUT /ingredients/{slug}`, `DELETE /ingredients/{slug}` (409 si utilisé dans une recette ou une liste), `GET` / `POST` / `PUT /conversions` (administrateur ; le `GET /conversions/{id}` sert le `Location`).
  - L'acteur vient du JWT : `CreateIngredientCommand::userId` remplace `ownerId`, et c'est le handler qui décide « commun » (administrateur) ou « personnalisé » (la règle était dans le formulaire Twig) ; le handler renvoie le slug créé
  - Gardes ajoutées : un slug existant donne 409 (créer deux fois le même nom écrasait l'ingrédient, en Twig comme en API), un coefficient absent alors que les unités diffèrent sans conversion donne 422 (c'était une `TypeError` 500), `creer-ingredient` passe de 8 à 9 requêtes (le contrôle d'existence)
  - Corrigé : modifier un ingrédient par le formulaire Twig remettait son image à vide ; l'image est désormais conservée (`UpdateIngredientCommand::$image` nul = inchangée)
  - `CreateConversionCommand` reçoit son id ; `ConversionException::notFound` passe à 404 ; codes d'`IngredientException` alignés (409 utilisé, 422 conversion impossible)
  - **Les commandes n'étaient pas atomiques** : `command.bus` n'avait aucun middleware de transaction. Ajout de `doctrine_transaction` ([0006](adr/0006-buses-messenger.md) §8, `CommandBusTransactionTest`)
- [x] **Référentiels administrateur** (6 endpoints) : `POST` (201 + `Location` vers la lecture existante) et `PUT` (remplacement, le slug ne change pas) sur `/units`, `/departments`, `/recipe-types`, réservés à `ROLE_ADMIN`.
  - Même trou que pour les ingrédients : le slug dérive du nom, créer « Gramme » une seconde fois écrasait l'unité existante (et ses conversions, ses recettes). Gardes ajoutées (`exists` sur les trois ports, `…::alreadyExists` en 409) ; les handlers de création renvoient le slug. Budgets `creer-unite`, `creer-rayon`, `creer-type-recette` : 2 → 3
  - Les quatre formulaires Twig de création (unité, rayon, type de recette, et ingrédient de la PR précédente) affichent « … de ce nom existe déjà. » sur le champ Nom au lieu d'une erreur 500
  - Image d'un rayon ou d'un type : URL `https://…` ou chemin relatif `images/…` (les valeurs en base sont de ces deux formes) ; `http:`, `javascript:` et `../` sont refusés en 422
- [x] **Utilisateur** (3 endpoints) : `POST /users` (inscription, **seule route d'écriture publique**, 201 + `Location`), `GET /users/{id}` (lui-même ou administrateur, c'est le `Location` de l'inscription) et `PUT /me/settings` (nombre de personnes, mot de passe, 204 ; l'identité vient du JWT).
  - Même trou que pour les slugs : `email` est `unique` en base mais le handler ne le vérifiait pas (le formulaire Twig le faisait par `IsEmailAlreadyUsed`) : un doublon donnait une violation d'unicité, donc une 500. Garde `UserRepository::emailExists` dans le handler, `UserException::emailAlreadyUsed` en 409 ; `inscription` passe de 2 à 3 requêtes (le contrôle d'existence)
  - `RegisterNewUserCommand` reçoit l'id (`userId`, généré par le controller comme les autres créations, ADR 0007 §11)
  - `UserException::invalidPassword` passe de 403 à 422 : le mot de passe actuel erroné est une règle métier non respectée, pas un défaut d'autorisation (le formulaire Twig s'appuie sur le message, inchangé)
  - Le corps de l'inscription exige un mot de passe de 8 caractères au moins (le formulaire Twig n'impose rien) ; 1 à 100 personnes
- [x] **Protocole `{views:[{selector, html}]}` remplacé par de l'état ressource côté API** : les 14 controllers AJAX de Twig (`ajax.js`, qui restent tant que Twig vit) ont chacun leur endpoint, qui ne renvoie ni HTML ni fragment : `204` (ou `201` + `Location`), et le front relit l'état par les lectures de la phase 4 (`GET /shopping-lists`, `/shopping-lists/{id}`, `/shopping-lists/active/summary`, `/recipes/{id}`). Les `alerts` deviennent `{error:{message}}` avec le code HTTP du domaine.
  - Correspondance : planifier / retirer une recette `PUT` / `DELETE /shopping-lists/active/recipes/{recipeId}`, ajouter / retirer un ingrédient `POST …/active/ingredients/{slug}/increase|decrease`, portions `PUT /meals/{id}/serving`, cocher / décocher `POST /shopping-list-rows/{id}/tick|uncheck`, renommer `PATCH /shopping-lists/{id}`, activer / pause / terminer `POST /shopping-lists/{id}/activate|pause|complete`, supprimer `DELETE /shopping-lists/{id}`, copier / supprimer une recette `POST /recipes/{id}/copy`, `DELETE /recipes/{id}`
  - Preuve de parité : le scénario Chromium des listes de courses (`p8-actions`, 11 relevés d'état en base : planifier, portions, ingrédients, cocher jusqu'à la fin, décocher, renommer, pause, terminer, retirer) rejoué en API avec un vrai JWT donne des états **identiques** à ceux de Twig, et les relectures `GET` suivent la base à chaque étape
  - Parcours de bout en bout ajoutés (vrais bus, `ShoppingListWriteApiFlowTest`) pour les 5 actions qui n'en avaient pas : activer (met l'ancienne liste active en pause), terminer (statistiques du propriétaire), décocher (réactive une liste terminée), ajouter / retirer un ingrédient, retirer une recette

### Phase 10 — Extinction de Twig

- **Prérequis : le front Vue.js (projet séparé) doit être prêt** avant toute extinction de Twig ; rien n'est retiré tant que ce n'est pas le cas.
- [ ] À décider produit, une fois le front prêt : quels `ViewController` (et `ajax.js`, templates, formulaires Twig) retirer (ADR dédié le moment venu)

## Chantiers de maintenance (décidés le 2026-10-14, une PR chacun, dans cet ordre)

Issus des points « hors périmètre » des PR et de `composer audit` (32 avis de sécurité, Symfony 7.2 hors support). PHP reste en 8.4.

- [x] **Ménage** (PR #30) : 404 sur les routes Twig, `ConversionService::clear()` sur `kernel.reset`, `symfony/translation` et locale `fr`, code mort retiré
- [x] **E1 — Symfony 7.4 LTS** : `symfony/*` en `7.4.*`, `twig/twig` 3.30, `doctrine/orm` 3.7, PHPUnit 12.5, 0 avis de sécurité ; `Dockerfile` aligné (`frankenphp:1.12.4-php8.4.23`, paquets `apt` non épinglés : l'épinglage exact cassait la build) ; Dependabot hebdomadaire, job CI `composer audit`, `make audit` / `make outdated`, [ADR 0014](adr/0014-montee-de-versions-et-dependabot.md) ; 5 tests de handlers passent de mocks sans attente au `SpyEventDispatcher` (4 entrées de baseline PHPStan retirées, notices PHPUnit 12.5 supprimées) ; zéro dépréciation de conteneur en `dev` et `test` (`debug:container --deprecations` : routes de bundles en PHP, signatures de `UserChecker` et `OwnerVoter`, `collect_serializer_data`), condition de E3
- [x] **B — Sécurité de l'API** : plafonds de débit (`login_throttling` JSON et formulaire : 5 échecs par 15 minutes par IP + email ; inscription : 10 par heure par IP ; `429` + `Retry-After`), JWT révoqués au changement de mot de passe (empreinte `pwd`), emails comparés sans casse et stockés en minuscules (migration qui refuse les doublons par la casse), doublon concurrent en `409` ; [ADR 0008](adr/0008-auth-session-et-jwt.md) §12 à 14
- [x] **E2 — Doctrine** : `doctrine/dbal` 3.10 → 4.5, `doctrine-bundle` 2.19 → 3.3, `doctrine-migrations-bundle` 3.7 → 4.0 (ORM 3.7 et DAMA 8.7 déjà compatibles). Le code applicatif n'a pas bougé : 945 tests verts du premier coup, **budgets SQL inchangés**, Twig identique au caractère près (150 Ko) et états API identiques. À changer : `config/packages/doctrine.yaml` (options retirées par le bundle 3 : `use_savepoints`, `auto_generate_proxy_classes`, `proxy_dir`, `enable_lazy_ghost_objects`, `report_fields_where_declared`, `controller_resolver.auto_mapping`), `CountingMiddleware` (`Statement::execute()` n'a plus de paramètre en DBAL 4) et une migration `Version20261014161857` : DBAL 4 voyait un écart de schéma sur une base migrée de zéro (le `doctrine:schema:validate` de la CI aurait échoué) : un commentaire `(DC2Type:…)` résiduel sur `shopping_list.created_at` et trois valeurs par défaut que le mapping ne déclare pas (`ingredient.compatible_units`, `user.status`, `user.statistics`, dont une date figée le jour de la migration d'origine)
- [ ] **E3 — Symfony 8.1** : bloqué aujourd'hui par doctrine-bundle 2.x, migrations-bundle 3.x, maker-bundle et web-profiler 7.x
- [ ] **D — `User` par identifiant** dans `Ingredient`, `Recipe`, `ShoppingList` (baseline Deptrac de 84 lignes à vider, ADR dédié)

## Trous d'autorisation (corrigés en phase 9)

Audit de tous les controllers qui dispatchent une commande (méthode HTTP et garde) :

- [x] `AdminCreateUnitViewController` (`/admin/unit/create`) : `ROLE_ADMIN` ajouté ; `CreateIngredientViewController` (`/ingredient`) : `ROLE_USER` ajouté (un anonyme obtenait une erreur 500 à la place d'un renvoi au login).
- [x] Routes `tick` / `uncheck` d'une ligne : voter `SHOPPING_LIST_ROW_OWNER` (`OwnershipLoader::shoppingListRowOwnerId`), seul le propriétaire de la liste (ou un administrateur) peut cocher.
- [x] `RemoveIngredientViewController` : plus de GET destructif. La suppression est un POST protégé contre le CSRF (jeton `remove-ingredient`, ajouté aux `stateless_token_ids`), envoyé par un formulaire placé hors du formulaire principal, le bouton y est rattaché par l'attribut `form`.
- [x] Trouvés à l'audit : `CreateShoppingListViewController` créait la liste sur un simple GET et `UpdateServingMealViewController` modifiait les portions sur un GET. Désormais `POST` et `PUT` seulement (le front n'utilisait déjà que ceux-là).
- [x] Le voter lève un 404 (`NotFoundHttpException`) quand la ressource est introuvable, au lieu d'une exception de domaine qui donnait un 500 sur les routes Twig ; l'API garde son 404.
- ~~`OwnerVoter` `INGREDIENT_OWNER` retourne `null` pour un ingrédient système~~ : corrigé en phase 7.

## Bugs connus à corriger

- [x] ~~Ménage des points « hors périmètre »~~ : corrigé. Id inconnu sur une route Twig = 404 pour tous (`WebDomainExceptionListener`, y compris pour un administrateur, que le voter laissait passer) ; `ConversionService::clear()` branché sur `kernel.reset` (le graphe de conversions ne survit plus aux requêtes en mode worker) ; `symfony/translation` installé et locale `fr` (le message de connexion s'affichait en anglais, les messages de validation de l'API et des formulaires sont désormais en français) ; code mort supprimé (`LoginCheckViewController` qui rendait un template inexistant, `RecipeRowRepository` vide) ; `SYMFONY_PHPUNIT_VERSION` retiré ; deux puces obsolètes retirées (cache de `RecipeType` et `Recipe`, `ModelCache` n'existe plus).
- [x] ~~Supprimer une recette laisse ses lignes orphelines~~ : corrigé en phase 9 (`RecipeRowPostgreSQLRepository::deleteByRecipeId` appelé par `RecipePostgreSQLRepository::delete`, test de repository sur base réelle, migration de nettoyage).

## Hors périmètre (vus en passant, non corrigés)

- Une ligne de recette peut porter une unité sans chemin de conversion vers l'unité d'achat de son ingrédient : l'enregistrement l'accepte (formulaire Twig comme API), la planification échoue ensuite (`cannotConvertToUnit`). À valider à l'enregistrement avec `ConversionService::canConvertToPurchaseUnit`.
- `GetActiveShoppingListViewController` crée la liste active sur un GET (lecture avec effet de bord, `EnsureActiveShoppingList`) : à garder tant que le front Twig l'attend, à supprimer avec l'API.
- Lignes d'une recette : aucune colonne de position, l'ordre affiché est l'ordre de stockage (celui de la saisie tant que la recette n'est pas modifiée, puis variable). `RecipePostgreSQLLoader` le conserve à l'identique ; ajouter une position si l'ordre doit être garanti.
- Le motif de date `dd MMMM YYYY` (templates des listes de courses et `ListShoppingLists`) utilise l'année ISO de la semaine : le 29 décembre 2025 s'affiche « 29 décembre 2026 ». Conservé tel quel pour ne pas changer l'affichage ; remplacer `YYYY` par `yyyy` partout en une fois (loader, `_shopping_list_card_header`, `shopping_list.html.twig`).
- Inscription publique (`POST /api/v1/users`) : pas de vérification d'email (ni de captcha) ; un email déjà pris répond 409, ce qui révèle l'existence du compte (le formulaire Twig le fait déjà). Le plafond par IP ne vaut que si `SYMFONY_TRUSTED_PROXIES` est configuré derrière un reverse proxy.
- `UserProvider::upgradePassword` met à jour le hash sur le modèle chargé mais ne l'enregistre pas : le rehachage automatique du mot de passe à la connexion est perdu (inoffensif tant que l'algorithme ne change pas).
- Formulaires Twig de rayon (`AbstractDepartmentType`) : `UrlType` préfixe `http://` à une valeur sans protocole, donc modifier un rayon dont l'image est un chemin relatif (`images/…`, forme stockée en base et acceptée par l'API) la transforme en `http://images/…`. `default_protocol` est explicitement `http` (comportement inchangé, notice de dépréciation symfony/form 7.1 retirée) ; le passer à `null` rendrait le champ en `type="url"` et bloquerait les chemins relatifs dans le navigateur. À trancher avec le futur front (champ texte validé côté serveur comme `DepartmentPayload`, ou liste d'images).
- `admin_recipe_type_row.html.twig` : la ligne porte `id="department-…"` (copier-coller du rayon), à renommer quand un test ou du JS la cible.
- Formulaires admin : `conversion|default(null)` / `department|default(null)` / `unit|default(null)` jamais fournis par les controllers update, le titre reste « Nouveau … ».
- Page de connexion : l'erreur « Identifiants invalides. » s'affiche sous le champ email et sous le champ mot de passe (`login.html.twig`), au lieu d'une seule fois.

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
