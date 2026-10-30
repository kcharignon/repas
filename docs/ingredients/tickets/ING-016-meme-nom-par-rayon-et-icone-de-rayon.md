# ING-016 — Le même nom dans deux rayons, et l'icône d'un rayon

- **Dépôt** : **back** (cette fiche) ; le front est [**ING-017**](ING-017-icones-de-rayon-et-creation-dans-la-recette.md) (fait)
- **Jalon** : I6 Plus d'ingrédient commun (suite)
- **Taille** : M
- **Effort recommandé pour l'agent** : **high** (règle d'unicité, plusieurs chemins d'écriture, aucune donnée de production touchée)
- **Dépend de** : — (le code d'ING-012 à ING-014 est en production)
- **Statut** : fait (partie A : PR #356 ; partie B : seconde PR)
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-30 ; [`sans-commun.md`](../sans-commun.md) ; `CLAUDE.md` (puces « Plus de nouvel ingrédient commun », « Fusion », « Doublons possibles », « Amorçage ») ; skill `repas-dev`.

## Contexte

Demande du propriétaire (2026-10-30) : « Deux ingrédients d'une même personne peuvent porter le même nom s'ils sont dans deux rayons différents » (« Petits pois » en Surgelés et « Petits pois » en Fruits et légumes), et « un rayon peut porter une icône (un flocon pour Surgelés, une boîte pour Conserves, d'autres plus tard), réglée par l'administrateur sur l'écran des rayons : elle sert à distinguer visuellement ces ingrédients ».

## A. Un nom est unique par propriétaire ET par rayon

**Règle** : un propriétaire n'a pas deux ingrédients de même nom normalisé (`slugged_name` : sans accent ni majuscule) dans le **même** rayon (`409 INGREDIENT_NAME_ALREADY_USED`) ; le même nom dans un autre rayon est permis. Aucune contrainte unique en base, aucune migration : c'est une règle d'application, comme avant (l'index `idx_ingredient_creator (creator, slugged_name)` sert la requête). Les identifiants (`slug`) restent uniques et **ne changent jamais** : le slug d'une création est le premier libre de `IngredientSlugPolicy::firstFreeSlug` (`nom + identifiant du compte`, puis `…-2…`, `…-3…`, comme pour une copie).

### Les chemins qui contrôlaient l'unicité du nom, avant et maintenant

| Chemin | Avant | Maintenant |
|---|---|---|
| `CreateIngredientHandler` (API `POST /api/v1/ingredients`) | `existsForCreator(slug, créateur, nom)` : refus si le slug existe **ou** si le créateur a le nom (`409 « already exists »`) | `creatorHasNameInDepartment(créateur, nom, rayon)` : refus seulement dans le même rayon (`409 INGREDIENT_NAME_ALREADY_USED`) ; le slug est choisi libre avant (`firstFreeSlug`), donc plus jamais un refus de slug |
| `UpdateIngredientHandler` (`PUT /api/v1/ingredients/{slug}`) | aucun contrôle : un ingrédient pouvait être renommé en doublon exact | refusé (`409 INGREDIENT_NAME_ALREADY_USED`, rien n'est écrit) si le nom normalisé **ou** le rayon change et que le propriétaire a déjà ce nom dans ce rayon, sans compter l'ingrédient lui-même ; modifier l'image ou les unités d'un ingrédient qui a déjà un jumeau (copie ancienne) n'est jamais refusé |
| Création par la voix (`SpokenIngredientCreatorAdapter`) | `CreateIngredientHandler` : refus si le nom existait, quel que soit le rayon dit | `CreateIngredientHandler` : le rayon dit complète le contrôle ; « petits pois » dit pour un autre rayon est créé |
| Amorçage à l'activation (`SeedUserIngredientsHandler`, `IngredientAdoptionBulkRepository`) | aucun contrôle de nom ; le slug de chaque copie est le premier libre (`forAdoption`) | inchangé : deux ingrédients de même nom du propriétaire du catalogue (deux rayons) donnent deux copies, `…` et `…-2…`, chacune avec son rayon et son origine (`adopted_from`) |
| Copie d'une recette (`CopyRecipeHandler`) | provenance (alias), puis nom **du même rayon** (`IngredientNameMatcher`), sinon copie au premier slug libre | inchangé : jamais de lien vers le même nom d'un autre rayon (une copie est créée, au slug `…-2…` si besoin) ; ce n'est pas une création, le doublon exact éventuel (conversion impossible) reste proposé par « Doublons possibles » |
| Fusion (`IngredientMergePlanner`, `MergeIngredientsHandler`) | aucune hypothèse de nom unique | inchangé : le gardé garde nom, rayon et identifiant ; fusionner deux « Petits pois » de deux rayons est permis (l'aperçu dit `departmentChanges`) |
| Doublons possibles (`DuplicateIngredientFinder`) | paires du **même rayon** seulement | inchangé : deux « Petits pois » de deux rayons ne sont jamais proposés |

### Les lectures par nom

| Lecture | Que fait-elle quand deux ingrédients de même nom existent dans deux rayons ? |
|---|---|
| Recherche (`SearchIngredients`), choix (`ListAvailableIngredients`), rayon (`ListIngredientsByDepartment`), fiche | rend les deux, ordre déterministe (pertinence, nom, slug) ; la recherche et la fiche disent le rayon de chacun (`departmentSlug`, `departmentName`) |
| Voix, ajout (`SpokenIngredientRanker::ties`) | déjà départagé par le rayon : « Petits pois : en surgelé ou en fruit et légume ? » (jamais deviné quand les habitudes ne tranchent pas) |
| Voix, retrait et « est-ce qu'il y a… ? » (`SpokenListLookupAdapter`) | déjà : les lignes qui se valent sont départagées par le rayon |
| Voix, propositions d'un produit inconnu (`SpokenIngredientResolverAdapter`) | **changé** : un nom n'est proposé qu'une fois (le mieux classé, habitudes comprises), l'autre rayon ne se dit pas en double ; une troisième place revient à un autre nom |
| Rapprochement à la copie d'une recette (`IngredientNameMatcher::match`) | ne compare que le même rayon (déjà) |
| Mémoire de phrase, alias d'adoption, « Pas le même » | par slug : aucune hypothèse de nom unique |

### Tests

`CreateIngredientHandlerTest`, `UpdateIngredientHandlerTest` (création, modification de nom, **changement de rayon qui crée une collision**, ingrédient qui a déjà un jumeau, autre propriétaire), `IngredientRepositoryTest` (`creatorHasNameInDepartment`, une requête), `IngredientWriteApiFlowTest` (409 et code stable), `SameNameInTwoDepartmentsTest` (amorçage, copie vers une personne qui a déjà l'autre rayon, copie rapprochée du bon rayon), `SpokenIngredientCreatorFlowTest` (voix : création puis départage), `SpokenIngredientResolverAdapterTest` (une proposition par nom), `IngredientPostgreSQLLoaderTest`, `IngredientDuplicateLoaderTest`, `IngredientMergePostgreSQLLoaderTest`. Budgets SQL : `creer-ingredient*` +1 requête (le slug libre se demande à part du nom), `modifier-ingredient*` +1 (le contrôle, seulement quand le nom ou le rayon change).

## B. Une icône de rayon

**Schéma** : `department.icon VARCHAR(32) DEFAULT NULL` (migration `Version20261030190000`, additive, rejouable, `down` supprime la colonne seule). Aucune donnée écrite : `NULL` partout, la valeur se règle par l'écran d'administration. Les rayons sont des **données globales** (pas de propriétaire) : l'icône l'est aussi.

**Domaine** : l'énumération `Repas\Repas\Domain\Model\DepartmentIcon` (`snowflake`, `can`), seule liste des valeurs permises (`values()`, `parse()` pour une demande de client, `known()` pour une lecture) ; `Department::getIcon()`, `changeIcon()` ; `update(name, image)` ne touche jamais l'icône.

**Écriture** : `PUT /api/v1/departments/{slug}` et `POST /api/v1/departments`, `ROLE_ADMIN` comme avant. `icon` : une valeur, `null` (efface) ou clé **absente** (inchangée, `UpdateDepartmentCommand::$changeIcon` posé par le controller d'après le corps) ; valeur inconnue : `422` (corps) et `DepartmentException::unknownIcon()` (cas d'usage, avant toute écriture).

**Lecture** (formes exactes) :

| Où | Champ ajouté |
|---|---|
| `DepartmentDTO` (`GET /departments`, `GET /departments/{slug}`, `department` de `GET /departments/{slug}/ingredients`) | `icon: string\|null`, clé toujours écrite |
| `RecipeRowDTO` (lignes de `GET /recipes/{id}`) | `departmentSlug: string`, `departmentName: string` |
| `ShoppingListRowDTO` (lignes de `GET /shopping-lists/{id}` et ligne seule) | `departmentSlug: string`, `departmentName: string` |
| `IngredientOptionDTO` (`GET /ingredients`, le choix d'un ingrédient) | `departmentSlug: string`, `departmentName: string` |
| `ConversionDTO` (`GET /conversions*`, écran de l'administrateur) | `departmentSlug: string\|null`, `departmentName: string\|null` (`null` pour une conversion générale) |

Déjà présents (inchangés) : la recherche (`IngredientSearchResultDTO`), la fiche (`IngredientDTO`), les ingrédients à régler, les candidats et l'aperçu de la fusion, les doublons possibles.

**Fixtures** : `department.json` donne `snowflake` à `surgele` et `can` à `conserve` (développement et tests seulement).

**Hors périmètre** : les DTO du contexte `Drive` qui montrent un nom d'ingrédient (`ChoiceDTO`, `DrivePlanLineDTO`, `DriveEstimateLineDTO`) ne disent pas le rayon : à décider avec ING-017 (le contexte ne dépend que de `Shared`).

**Tests** : `DepartmentIconTest`, `CreateDepartmentHandlerTest` / `UpdateDepartmentHandlerTest` (valeur, `null`, clé absente, inconnue), `CreateDepartmentApiControllerTest` / `UpdateDepartmentApiControllerTest` (corps, clés), `DepartmentIconApiFlowTest` (parcours complet avec les vrais bus, accès), `DepartmentRepositoryTest`, `DepartmentPostgreSQLLoaderTest` (valeur inconnue lue comme aucune icône), `DepartmentIconMigrationTest` (jumelle du schéma, rejouable, ancien code compatible, `down`), `DevelopmentFixturesTest`, et les loaders des quatre DTO ci-dessus. Aucun budget SQL ne change.
## ING-017 (front, fait)

Le front montre l'icône du rayon devant un ingrédient (choix, recette, liste, écran des rayons), dit le rayon sous le nom quand deux ingrédients de même nom sont dans la même liste, règle l'icône sur l'écran des rayons de l'administrateur, traduit `INGREDIENT_NAME_ALREADY_USED` et permet de créer un ingrédient sans quitter la recette : voir [ING-017](ING-017-icones-de-rayon-et-creation-dans-la-recette.md).
