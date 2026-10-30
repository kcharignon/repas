# ING-016 — Le même nom dans deux rayons, et l'icône d'un rayon

- **Dépôt** : **back** (cette fiche) ; le front est **ING-017** (non fait)
- **Jalon** : I6 Plus d'ingrédient commun (suite)
- **Taille** : M
- **Effort recommandé pour l'agent** : **high** (règle d'unicité, plusieurs chemins d'écriture, aucune donnée de production touchée)
- **Dépend de** : — (le code d'ING-012 à ING-014 est en production)
- **Statut** : partie A (unicité par rayon) faite ; partie B (icône de rayon) : voir la section B
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

Voir l'amendement de l'ADR 0028 et la section B ajoutée avec la seconde PR.

## ING-017 (front, non fait)

Le front montre l'icône du rayon à côté d'un ingrédient quand deux ingrédients du compte portent le même nom (recherche d'ingrédient, ligne de recette, ligne de liste), règle l'icône sur l'écran des rayons de l'administrateur, et traduit `INGREDIENT_NAME_ALREADY_USED` (aujourd'hui, un `409` s'affiche comme « une autre modification est en cours »). Écrit après la seconde PR du back.
