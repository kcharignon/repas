# Ingrédients propres à chaque utilisateur : inventaire de l'existant

> **Amendement du 2026-10-20 : plus d'ingrédient commun** ([`sans-commun.md`](sans-commun.md)). Cet inventaire décrit la situation **d'avant** : les passages sur les communs (§ 3 la règle « `creator` = moi ou vide », § 4 la création par l'administrateur, § 6 la copie de recette, § 14) deviennent historiques à la passation ; `sans-commun.md` § 9 et § 11 donnent ce qui change et ce qui meurt.

Rédigé le 2026-10-20 par l'assistant architecte, qui n'implémente pas. Code lu sur `main` au commit `cae13be` (contexte `Drive` relu après DRV-652 et DRV-653). Les références `fichier:ligne` sont celles de ce commit ; elles bougent, le nom du symbole reste. Décisions : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md). Suite : [`migration.md`](migration.md), [`rapprochement.md`](rapprochement.md), [`fusion.md`](fusion.md), [`roadmap.md`](roadmap.md).

## 1. Le besoin et ce qui existe

Il y a aujourd'hui deux sortes d'ingrédients dans la même table :

- les **communs** (`ingredient.creator` vide) : créés par un administrateur, visibles et utilisables par tous, modifiables par un administrateur seulement ;
- les **personnels** (`ingredient.creator` = identifiant de l'utilisateur) : créés par l'utilisateur ou copiés avec une recette, visibles et modifiables par lui seul.

Le propriétaire veut que chaque utilisateur ait **ses** ingrédients (modifiables, avec leurs conversions), que les communs restent sans être supprimés, que la copie d'une recette se branche sur un ingrédient que le copieur possède déjà quand le nom correspond (même rayon), et que l'utilisateur puisse fusionner deux de ses ingrédients.

Ordres de grandeur en production (relevés en lecture seule le 2026-10-20, jamais recopiés en détail ici) : une dizaine d'utilisateurs ; quelques centaines d'ingrédients dont environ 260 communs et une centaine de personnels créés par **trois** comptes seulement ; une centaine de recettes (environ 670 lignes dont presque toutes sur des communs) ; une douzaine de listes ; une centaine de conversions dont environ 105 rattachées à un ingrédient ; une cinquantaine de choix du drive avec leurs produits mémorisés.

## 2. Le modèle de données

| Table | Colonne qui désigne un ingrédient | Clé / contrainte | Entité Doctrine |
|---|---|---|---|
| `ingredient` | `slug` (clé primaire, 255) ; `creator` (utilisateur, nullable) ; `slugged_name` ; `department` ; `default_cooking_unit`, `default_purchase_unit` ; `compatible_units` (JSON) ; `image` (2048) | PK `slug` | `src/Repas/Infrastructure/Entity/Ingredient.php:9` |
| `conversion` | `ingredient` (nullable : vide = conversion commune à tous) ; `start_unit`, `end_unit`, `coefficient` | PK `id` (767) | `src/Repas/Infrastructure/Entity/Conversion.php:9` |
| `recipe_row` | `ingredient` | aucune contrainte d'unicité | `src/Repas/Infrastructure/Entity/RecipeRow.php:17` |
| `shopping_list_row` | `ingredient` (une ligne par ingrédient et par liste, imposé par le domaine, pas par le schéma) | | `src/Repas/Infrastructure/Entity/ShoppingListRow.php:19` |
| `shopping_list_ingredient` | `ingredient` (clé métier : liste + ingrédient + unité) | | `src/Repas/Infrastructure/Entity/ShoppingListIngredient.php:19` |
| `drive_choice` | `ingredient_slug` | unique `(user_id, retailer, store_id, ingredient_slug)` | `src/Drive/Infrastructure/Entity/Choice.php:17` et `:33` |
| `drive_choice_product` | aucune : rattachée à `drive_choice.id` ; porte, depuis DRV-652, la contenance du produit **dans l'unité de la liste** (`pack_size_value`, `pack_size_unit_slug` : un **slug d'unité**, pas d'ingrédient) | unique `(choice_id, rank)` | `src/Drive/Infrastructure/Entity/ChoiceProduct.php` |
| `drive_department_rayon` | aucune : clé `(retailer, department_slug, rayon_id)`, par **rayon de Repas**, pas par ingrédient | | `src/Drive/Infrastructure/Entity/DepartmentRayon.php` |

Aucune clé étrangère nulle part (ADR 0013, 0021) : une référence est un texte, rien ne se propage ni ne protège. `user.statistics` (JSON du contexte `User`) porte un compteur `ingredients`.

### Le slug d'un ingrédient

`Ingredient::create()` calcule `slug = slugify(nom . créateur)` et `sluggedName = slugify(nom)` (`src/Repas/Domain/Model/Ingredient.php:144`). Pour un commun, `créateur` est vide : `tomate`. Pour un personnel, l'identifiant (un UUID) est collé au nom sans séparateur : `praline<uuid>` (motif constaté en production). Conséquences :

- le slug ne change **jamais** après un renommage (`Ingredient::update()` ne le touche pas, `:199`) ;
- deux utilisateurs peuvent avoir le même nom : les slugs diffèrent par l'UUID ;
- **un utilisateur ne peut pas avoir deux ingrédients de même nom** (même slug) : `CreateIngredientHandler` répond `409` (`:56`) ;
- le slug tient dans 255 caractères mais le nom aussi (`IngredientPayload`, `Length(max: 255)`) : un nom de plus de 219 caractères dépasserait la colonne une fois l'UUID (36) ajouté. Défaut latent, aucun nom réel n'approche (les communs ont 24 caractères au plus).

## 3. Qui voit quoi : la règle `creator = moi OU vide`

La règle de visibilité est écrite **à la main à chaque endroit** :

| Endroit | Fichier:ligne | Ce qu'il fait |
|---|---|---|
| Ingrédients d'un rayon | `IngredientPostgreSQLLoader::listByDepartment` `src/Repas/Infrastructure/Loader/IngredientPostgreSQLLoader.php:34` (règle `:53`) | page d'un rayon, avec `canEdit` |
| Fiche d'un ingrédient | `::get` `:72` (règle `:91`) | un ingrédient d'un autre est inconnu, sauf pour un administrateur |
| Choix pour une recette | `::listAvailable` `:154` (règle `:159`) | `GET /api/v1/ingredients` |
| Recherche (ADR 0019) | `::search` `:175` (règle `:193`) | classement en PHP par `SearchRelevance`, 30 résultats |
| `canEdit` | `:223` | `créateur = moi` ou (administrateur et commun) |
| Port de domaine | `IngredientPostgreSQLRepository::findByDepartmentAndOwner` `src/Repas/Infrastructure/Repository/IngredientPostgreSQLRepository.php:99`, `::findByOwner` `:113` | seul appelant : la commande de console `UpdateUserStatisticsCommand.php:37` |
| Ajout à la liste | `::findPurchaseUnitSlugUsableBy` `:77` | un personnel d'un autre est « inconnu » (404), y compris pour un administrateur |
| Domaine d'une recette | `Recipe::assertIngredientIsAvailable` `src/Repas/Domain/Model/Recipe.php:238` | appelée par `Recipe::create` (`:152`) et `addRow` (`:220`) |
| Droit de modification | `OwnerVoter::INGREDIENT_OWNER` `src/User/Infrastructure/Security/OwnerVoter.php:68` ; port `OwnershipPostgreSQLLoader::ingredientCreatorId` `src/Repas/Infrastructure/Loader/OwnershipPostgreSQLLoader.php:26` | **un administrateur passe toujours** (`:45`) : il peut modifier ou supprimer l'ingrédient personnel de n'importe qui |

Pas de lecture « tous les ingrédients » pour l'utilisateur : tout passe par ces cinq requêtes SQL ou ces deux méthodes de port. Le jeu de règles à changer est donc petit et énumérable.

## 4. Les écritures qui reçoivent un slug d'ingrédient

| Cas d'usage | Fichier:ligne | Contrôle de l'ingrédient |
|---|---|---|
| Créer une recette | `CreateRecipeHandler` `src/Repas/Application/Command/CreateRecipe/CreateRecipeHandler.php:46` (`findBySlugs`), conversion `:69` | `Recipe::addRow` : commun ou à l'auteur ; `assertConvertibleToPurchaseUnit` par ligne |
| Modifier une recette | `UpdateRecipeHandler` `.../UpdateRecipe/UpdateRecipeHandler.php:52`, `:62` | idem |
| Ajouter / retirer un ingrédient à la liste | `AddIngredientToShoppingListHandler` `.../AddIngredientToShoppingList/AddIngredientToShoppingListHandler.php:33` ; `RemoveIngredientToShoppingListHandler` | `findPurchaseUnitSlugUsableBy` (ajout) ; aucun contrôle (retrait) |
| Planifier / retirer un repas, changer les portions | `PlannedMealHandler` `:51`, `removeMealFromPlanHandler` `:53`, `UpdateServingMealHandler` `:53` | **calculent les lignes de liste à partir des lignes de la recette** : `ShoppingList::addRow` / `subtractRow` par **slug** (`src/Repas/Domain/Model/ShoppingList.php:247`, `:260`) |
| Créer / modifier / supprimer un ingrédient | `CreateIngredientHandler` (`:43` : un administrateur crée un commun), `UpdateIngredientHandler` (`:46`), `RemoveIngredientHandler` (`:35`, `:47`) | propriétaire (voter) ; suppression refusée si l'ingrédient est dans une recette ou dans `shopping_list_ingredient` seulement |
| Créer / modifier une conversion | `CreateConversionApiController` et `UpdateConversionApiController` (`#[IsGranted('ROLE_ADMIN')]`) | **réservé aux administrateurs** : un utilisateur n'a que le coefficient unité d'achat / unité de recette de l'écran de son ingrédient |
| Copier une recette | `CopyRecipeHandler` `src/Repas/Application/Command/CopyRecipe/CopyRecipeHandler.php:30` | voir § 6 |
| Choix du drive | `SaveProductChoicesHandler` `src/Drive/Application/Command/SaveProductChoices/SaveProductChoicesHandler.php:111`, `:329` ; `ReplaceIngredientPreferenceHandler` `:60` ; `ForgetChoiceHandler` `:37` ; routes `/api/v1/drive/choices/{ingredientSlug}` | aucun contrôle de l'ingrédient (le slug doit seulement avoir la bonne forme, `Choice::SLUG_PATTERN`) |

**Piège central** : le calcul d'une liste relit le **slug courant** des lignes de la recette (`PlannedMealHandler:51`, `removeMealFromPlanHandler:53`). Si une recette est repointée vers un autre slug sans que les lignes de liste le soient dans la même transaction, retirer ce repas ne retrouve plus la ligne (`subtractRow` ne trouve rien) : la quantité reste, fausse. Toute migration et toute fusion repointent recettes **et** listes **et** choix du drive ensemble.

## 5. Les lectures qui joignent `ingredient`

Elles joignent toutes par `ingredient.slug`, sans regarder le créateur : aucune ne change quand le slug d'une ligne change.

`RecipePostgreSQLLoader` `src/Repas/Infrastructure/Loader/RecipePostgreSQLLoader.php:52` (lignes de la recette) ; `ShoppingListPostgreSQLLoader` `:217` (lignes, nom, rayon) et `:93` (quantités par slug du résumé de liste active) ; `ShoppingListRowPostgreSQLLoader` `:26` ; `ShoppingListForDrivePostgreSQLLoader` `:66` (port `Shared` du drive) ; `IngredientNamePostgreSQLLoader` `:24` (noms par slug pour l'écran des produits) ; `IngredientDepartmentPostgreSQLResolver` `:28` (rayon par slug, pour apprendre le rayon de l'enseigne). Un ingrédient absent de la table fait tomber la jointure `INNER` : **une référence orpheline fait disparaître la ligne** de l'affichage sans erreur (à vérifier en production avant toute migration, voir [`migration.md`](migration.md) § 8).

## 6. La copie d'une recette aujourd'hui

> **Corrigé par ING-004** ([`tickets/ING-004-rapprochement-et-copie-de-recette.md`](tickets/ING-004-rapprochement-et-copie-de-recette.md), section « Réalisation ») : ce paragraphe décrit le comportement d'AVANT, gardé pour mémoire.

`POST /api/v1/recipes/{id}/copy` (`CopyRecipeApiController`, aucune règle sur la recette copiée : n'importe quel identifiant) → `CopyRecipeHandler` (`:30`) :

1. charge la recette d'origine ;
2. pour **chaque ligne dont l'ingrédient est personnel** (`getCreatorId() !== null`, `:40`) : `Ingredient::copyFromOriginal` (`Ingredient.php:167`) crée un ingrédient **au nom du copieur** (même nom, rayon, unités, image ; slug = nom + copieur), l'**enregistre** (`:43`) puis copie ses conversions propres avec de nouveaux identifiants (`:45-49`) ;
3. les ingrédients **communs sont gardés tels quels** (partagés) ;
4. crée la recette copiée (`Recipe::copyFromOriginal`, `Recipe.php:158`, qui refait une copie des ingrédients personnels par `RecipeRow::copyFromOriginal`, `:99`) ;
5. annonce `RecipesOrIngredientsCreatedEvent` avec les slugs **créés** (`:60`), ce qui incrémente les statistiques de l'utilisateur.

Défauts constatés, qu'il faut corriger au passage (ING-004) :

- **aucune détection de doublon** : le copieur qui a déjà un ingrédient de même nom reçoit une copie qui porte le **même slug** (nom + copieur) ; `IngredientPostgreSQLRepository::save` (`:125`) trouve l'entité existante et la met à jour (`updateEntity` `:138`) avec le rayon, les unités et l'image de l'auteur : **l'ingrédient existant du copieur est écrasé** par celui de l'auteur et ses conversions sont **dupliquées** (nouveaux identifiants) ;
- copier **sa propre** recette (l'API le permet, le front ne le propose pas) écrase l'ingrédient par lui-même et duplique ses conversions ;
- les statistiques comptent des « créations » qui n'en sont pas ;
- aucun contrôle que l'unité d'une ligne se convertit dans l'unité d'achat de l'ingrédient copié (elle l'est si l'ingrédient est identique, rien ne le garantit après un rapprochement).

Le front ne propose la copie que depuis « Recettes des autres » (`repas-front/src/features/recipes/OtherRecipesView.vue:18`, `useCopyRecipe` `queries.ts:62`), dont la liste (`RecipePostgreSQLLoader::listOfOtherPeople` `:107`) ne montre que les recettes originales des autres.

## 7. Conversions et unités

- Le graphe de conversion d'un ingrédient est celui de ses conversions propres **plus** les conversions communes (`ingredient` vide) : `ConversionPostgreSQLRepository::findByIngredientOrCommon` (`:44`), `ConversionService::generateGraph` (`src/Repas/Domain/Service/ConversionService.php:178`), parcours en largeur dans les deux sens (`1/coefficient`).
- Une ligne de recette doit pouvoir se convertir dans l'unité d'achat de son ingrédient (`assertConvertibleToPurchaseUnit` `:41`), sinon la planification échouerait plus tard (`IngredientException::cannotConvertToUnit`). **Tout branchement d'une ligne sur un autre ingrédient doit rejouer ce contrôle** (rapprochement et fusion).
- `ingredient.compatible_units` est une valeur **dérivée** et stockée (unités atteignables depuis l'unité de recette et d'achat), recalculée à la création, à la modification (`UpdateIngredientHandler`) et par `php bin/console repas:update-ingredients-units` (`src/Command/UpdateIngredientsUnitsCommand.php:28`). Une copie conforme des conversions garde cette valeur exacte.
- Une conversion **commune** (g ↔ kg, litre ↔ millilitre…) reste partagée par tous : elle n'est pas copiée.
- La suppression d'un ingrédient supprime ses conversions propres (`RemoveIngredientHandler`, `deleteByIngredient`).
- La liste d'administration des conversions (`ConversionPostgreSQLLoader::list`, `src/Repas/Infrastructure/Loader/ConversionPostgreSQLLoader.php:25`) affiche **toutes** les conversions de tous les ingrédients, avec l'adresse email du créateur : copier ~100 conversions par utilisateur concerné multipliera cette liste (voir ADR 0028, conséquences).

## 8. Recherche (ADR 0019)

`IngredientPostgreSQLLoader::search` (`:175`) lit en une requête tous les ingrédients visibles (règle `:193`), note chacun avec `SearchRelevance::score` (`src/Shared/Domain/Tool/SearchRelevance.php`) et garde 30 résultats à partir de 0,85. `SearchRelevance` est une pertinence de **recherche** : un nom qui *commence* par le texte reçoit 0,9 et plus (« tomate » est à 0,946 de « tomate cerise »). Elle est taillée pour taper « tom » et trouver « tomate » ; **elle ne convient pas pour décider que deux noms sont le même ingrédient**. On réutilise en revanche `Shared\Domain\Tool\JaroWinkler` (la similarité elle-même, `src/Shared/Domain/Tool/JaroWinkler.php:146`). Voir [`rapprochement.md`](rapprochement.md).

## 9. Statistiques et événements

- `IngredientCreatedEvent` (`CreateIngredientHandler:62`, seulement pour un personnel) et `RecipesOrIngredientsCreatedEvent` (`CopyRecipeHandler:60`) incrémentent `user.statistics.ingredients` (`UpdateUserStatisticsHandler`, `User::createIngredients` `src/User/Domain/Model/User.php:206`) ; `IngredientRemovedEvent` (`RemoveIngredientHandler:47`) le décrémente.
- La commande de console `UpdateUserStatisticsCommand` (`src/Command/UpdateUserStatisticsCommand.php:37`) recalcule le compteur comme le nombre d'ingrédients dont le créateur est l'utilisateur.
- **Conséquence** : 260 copies de communs au nom d'un utilisateur feraient passer son compteur de « ingrédients créés » de quelques unités à plus de 260, et supprimer une de ces copies le décrémenterait. Une copie d'adoption n'est pas une création : elle n'émet aucun événement et la commande de recalcul l'exclut (ADR 0028).

## 10. Suppression d'un compte (ADR 0021)

`PurgeUserDataHandler` (`src/Repas/Application/Command/PurgeUserData/PurgeUserDataHandler.php:29`) supprime en masse, dans l'ordre : listes, repas d'autres listes, recettes (copies rendues originales), conversions des ingrédients du créateur (`:41`), ingrédients du créateur (`:42`). Le contexte `Drive` purge ses choix. `UserOwnedDataCoverageTest` (`tests/Shared/Infrastructure/UserOwnedDataCoverageTest.php:29`) échoue si une entité gagne une colonne `owner|author|creator|user` absente de sa liste. **Toute nouvelle table qui désigne un utilisateur (alias d'ingrédient, doublons écartés) doit y entrer et être purgée.** Les copies d'adoption, créées au nom de l'utilisateur, sont déjà supprimées par `deleteByCreator`.

## 11. Le contexte `Drive`

Le drive référence un ingrédient par son slug dans **une seule table** : `drive_choice.ingredient_slug` (+ `drive_choice_product` par l'identifiant du choix). Tout le reste (produits `drive_product`, prix, contenance, `drive_department_rayon`, réglages) est indépendant de l'ingrédient.

| Endroit | Fichier:ligne | Effet d'un changement de slug |
|---|---|---|
| Plan d'envoi | `DrivePlanPostgreSQLLoader` `src/Drive/Infrastructure/Loader/DrivePlanPostgreSQLLoader.php:75`, `:145` | cherche le choix par `(utilisateur, enseigne, magasin, slug de la ligne)` : **une ligne dont le slug ne correspond plus à son choix perd ses produits mémorisés** |
| Estimation du coût | `DriveEstimatePostgreSQLLoader` `:58`, `:116` | idem |
| Lecture des choix | `ChoicePostgreSQLLoader` `:39`, `:92` | noms par slug (`IngredientNameLoader`) |
| Enregistrement d'un lot de choix | `SaveProductChoicesHandler` `:111`, `:179`, `:329` ; `ChoicePostgreSQLRepository` `:103` (`INSERT … ON CONFLICT`) | crée un choix sous le slug reçu, **sans contrôler qu'il existe** |
| Remplacer / oublier | `ReplaceIngredientPreferenceHandler:60`, `ForgetChoiceHandler:37` | idem |
| Liste vue par le drive | `ShoppingListForDrivePostgreSQLLoader:66` (port `Shared\Application\Dto\ShoppingListLineForDrive`) | slug, nom, rayon, unité d'achat de la ligne |
| Rayon de l'enseigne | `IngredientDepartmentPostgreSQLResolver:28` | rayon de **Repas** de l'ingrédient (`department`), appris par rayon, pas par ingrédient |

Points qui protègent les choix : le **contenu net et le prix** sont sur le produit (fait public), pas sur l'ingrédient ; la **contenance donnée par la personne** (« 20 tranches dans ce produit », DRV-652) est sur la ligne `drive_choice_product` (donc suit l'identifiant du choix, pas le slug) et ne sert que si son unité est **exactement** celle de la ligne de la liste (`PackCalculator::usableSizeFor` : « la liste a pu changer d'unité depuis ») ; le nombre de produits se calcule à partir de l'unité d'achat de la ligne (dimension et facteur de base) : une copie qui garde les mêmes unités donne **le même plan** ; la recherche mémorisée (`drive_choice.search_query`, DRV-650) est portée par le choix et suit son slug. Aucune enseigne n'est nommée dans ce chantier : toutes les lignes `drive_choice` d'un utilisateur sont repointées quelle que soit l'enseigne.

## 12. Le front (`kcharignon/repas-front`)

| Écran / module | Fichier | Rôle |
|---|---|---|
| Rayons | `src/features/ingredients/DepartmentsView.vue`, `DepartmentView.vue` (`canEdit` `:68`, bouton `+/-` `:74`) | liste des ingrédients d'un rayon, ajout à la liste |
| Recherche | `IngredientSearchResults.vue`, `queries.ts:43` (`GET /ingredients/search`) | résultats classés avec rayon |
| Création / modification | `IngredientEditorView.vue` (route `ingredient-new`, `ingredient-edit`, `src/router/index.ts:152` et `:157`), `queries.ts:116` (`POST`/`PUT`), `:148` (`DELETE`) | coefficient unité d'achat / de recette |
| Recette | `src/features/recipes/RecipeEditorView.vue:26`, `RecipeRowEditor.vue:31` et `:60`, `recipes/queries.ts:120` (`GET /ingredients`, mis en cache 5 min), `:134` (unités compatibles) | sélecteur d'ingrédient d'une ligne |
| Copie | `OtherRecipesView.vue:18`, `recipes/queries.ts:62` | `POST /recipes/{id}/copy` |
| Liste de courses | `src/features/shopping-lists/ShoppingListContent.vue` ; résumé par slug `quantityByIngredientSlug` | |
| Administration | `src/features/admin/ConversionsAdminView.vue:26` (liste des ingrédients pour une conversion), `admin/queries.ts` | l'administrateur choisit un ingrédient parmi ceux qu'il voit |
| Drive | `src/features/drive/choices.ts:52`, `choicesQueries.ts:35`, `DriveSendLine.vue`, `restrictToPlan.ts:62`, `bridge/draft.ts:25` et `:111` | le slug de l'ingrédient voyage dans le plan, dans le **brouillon de passage** `repas.drive.run` gardé sur l'appareil, puis dans le retour de l'agent |
| Contrat | `src/api/schema.d.ts` (généré depuis `docs/api/openapi.json`) | |
| Hors-ligne | `src/offline/persistence.ts` | copie de la liste active gardée sept jours dans le navigateur (avec les slugs) |

Conséquence : un slug peut rester **périmé sur l'appareil** après une migration ou une fusion (liste en cache, brouillon de passage d'un envoi en cours, onglet ouvert, application Android qui n'est pas à jour). Les écritures qui reçoivent un slug doivent **tolérer un ancien slug** (alias, [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision d).

## 13. Tests et budgets déjà en place qui sont touchés

- `tests/Repas/WriteBudget/budgets.php` : `copier-recette` 16, `creer-ingredient` 9, `modifier-ingredient` 7, `supprimer-ingredient` 10, `ajouter-ingredient-liste` 13, `retirer-ingredient-liste` 9, `supprimer-compte` 21 (cliquet, ADR 0010 règle 12) : les nouvelles règles les font monter (à dire dans l'ADR et les tickets), les nouveaux cas d'usage (adopter, fusionner) ont leurs scénarios.
- `UserOwnedDataCoverageTest` (`:29`), `IngredientRepositoryTest`, `IngredientPostgreSQLLoaderTest`, `ConversionRepositoryTest`, `ShoppingListForDrivePostgreSQLLoaderTest`, `DrivePlan…LoaderTest`, `CopyRecipeHandlerTest` (en mémoire), `tests/Helper/InMemoryRepository/IngredientInMemoryRepository.php`, `tests/Helper/Builder/` (builders d'ingrédient), `tests/Helper/DataPersister.php`.
- Fixtures de développement : `src/Repas/Infrastructure/DataFixture/IngredientFixture.php`, `ConversionFixture.php`, `RecipeFixture.php`, `DemoRepasFixture.php`, `Data/ingredient.json` (256 communs publics, source des expériences de [`rapprochement.md`](rapprochement.md)).

## 14. Défauts latents trouvés en chemin

1. `CopyRecipeHandler` écrase un ingrédient existant du copieur et duplique ses conversions (§ 6).
2. Un administrateur ne peut créer que des communs (`CreateIngredientHandler:43`) ; il n'a pas d'ingrédient « à lui ».
3. `RemoveIngredientHandler:35` ne regarde que `shopping_list_ingredient` pour les listes (`ShoppingListPostgreSQLRepository::isIngredientUsed`), pas `shopping_list_row` : un ingrédient présent dans une ligne dérivée d'une recette est protégé par la recette (`RecipePostgreSQLRepository::isIngredientUsed`), mais pas une ligne orpheline.
4. Le nom d'un ingrédient peut dépasser la colonne du slug (§ 2).
5. `OwnerVoter` laisse un administrateur modifier l'ingrédient personnel d'un autre : la fusion ne doit pas hériter de cette exception.
6. `shopping_list_ingredient` et `shopping_list_row` n'ont pas de contrainte d'unicité : la règle « une ligne par ingrédient et par liste » n'est tenue que par le code (une fusion qui ne l'applique pas la casserait).
