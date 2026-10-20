# ING-005 — Fusionner deux ingrédients : aperçu et écriture (back)

- **Dépôt** : back
- **Jalon** : I4 Fusion
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (réécrit des références dans quatre tables et le contexte `Drive`, droits, listes envoyées)
- **Dépend de** : ING-001 (alias) ; ING-002 recommandé (la résolution des alias `merge`)
- **Statut** : à faire
- **Lire d'abord** : **[`fusion.md`](../fusion.md) §§ 1 à 7 et 10, 11** ; [`analyse.md`](../analyse.md) §§ 4, 7, 11 ; [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision g ; ADR 0016 (versions), 0023 (liste `SENT`), 0021 (purge) ; skills `/repas-dev`, `/repas-drive-back`.

## Contexte

L'utilisateur doit pouvoir fusionner deux de ses ingrédients (une copie faite par erreur, un doublon) : toutes ses recettes, listes et choix du drive retombent sur un seul. Ce ticket livre la logique et l'API ; l'écran est ING-007.

## Portée

1. **Cas d'usage `MergeIngredients`** (`Application/Command/MergeIngredients/`, `MergeIngredientsCommand(userId, absorbedSlug, keptSlug)` + `MergeIngredientsHandler`) : règles 1 à 15 de `fusion.md` § 2, étapes 0 à 9 de § 3, rejeu idempotent de § 6. Le handler vérifie lui-même `creator = userId` pour les deux ingrédients (**pas de passe-droit `ROLE_ADMIN`**). Un domaine : `Ingredient::assertOwnedBy(userId)`, `IngredientException::{cannotMergeIntoItself, cannotMergeCommon, mergeUnitMissing, inSentList, usedByAnotherUser}` (factories statiques nommées, messages = codes).
2. **Service de domaine `IngredientMergePlanner`** (pur) : à partir des unités utilisées par `B` et du graphe de conversion de `A` (`ConversionService`), calcule `missingUnits`, le facteur `f` de conversion des listes et le drapeau `listQuantitiesConverted` ([`fusion.md`](../fusion.md) § 5). Utilisé par l'aperçu et par l'écriture.
3. **Port d'écriture** `Domain/Interface/IngredientMergeRepository` (implémentation SQL en masse, nombre de requêtes **constant** ; verrou `SELECT … FOR UPDATE` des deux ingrédients) : `lockBoth`, `usageOf(slug)` (recettes, listes, choix, listes `SENT`), `hasRowsOfAnotherUser`, `repoint(...)` qui fait les étapes 1 à 8 de `fusion.md` § 3 (fusion des lignes de liste avec `checked = A ET B`, `ceil` des compteurs, choix du drive « A gagne »), versions `+1` par `UPDATE … RETURNING`.
4. **Lecture** `GetMergePreview` (Query + QueryHandler + `IngredientMergeLoader`, port `Application/Loader`) : l'aperçu JSON de `fusion.md` § 7 (compte des recettes, listes, choix, `departmentChanges`, `listQuantitiesConverted`, `missingUnits`, `blockedBy`, `canMerge`) ; `ListMergeCandidates` : les candidats de `fusion.md` § 7 (même rayon d'abord, classés par `IngredientNameMatcher::similar`, ING-004 ; **si ING-004 n'est pas livré, classement alphabétique** avec la même forme de réponse et un `veryClose` à `false`).
5. **Controllers** (`Infrastructure/Http/Api/{MergeIngredients,GetMergePreview,ListMergeCandidates}/`) : `POST /api/v1/ingredients/{slug}/merge` (`204`, `#[Payload] {into}`), `GET …/merge-preview?into=`, `GET …/merge-candidates?q=` ; `#[IsGranted('ROLE_USER')]` seulement (le contrôle de propriété est métier, [`fusion.md`](../fusion.md) § 10) ; `#[OA\…]` complets, codes de `fusion.md` § 7 ; `make openapi` et relecture du diff.
6. **Alias `merge`** : écriture de `B -> A`, repointage des alias qui désignaient `B`, rejeu idempotent (`204`), nettoyage à la création d'un ingrédient (déjà en ING-001).
7. **Statistiques** : `IngredientRemovedEvent` si `B` n'est pas une adoption. **Purge de compte** : rien de plus (les alias le sont déjà, ING-001).
8. **Contrat** : `IngredientDTO` de la fiche (`GetIngredient`) gagne `recipeCount` et `listCount` (pour la ligne « Utilisé dans 7 recettes · 2 listes » de la fiche, ING-007).

## Hors portée

Les doublons proposés et « Pas le même » (ING-006) ; l'écran (ING-007) ; reporter les conversions de `B` sur `A` ; fusionner des communs ou des ingrédients d'utilisateurs différents ; une limite de débit.

## Critères d'acceptation

(Chaque critère existe en test, base réelle pour les écritures.)

- **Cas nominal.** `A` « Tomate » (12 recettes, 3 listes), `B` « Tomates » (7 recettes, 2 listes), même rayon et unités : après la fusion, `B` n'existe plus, toutes les lignes de `recipe_row`, `shopping_list_row`, `shopping_list_ingredient` qui étaient sur `B` sont sur `A`, les versions des 7 recettes et des 2 listes montent de 1 et aucune autre, les quantités sont inchangées.
- **Recette qui contient les deux** : elle garde **deux lignes** sur `A`. **Liste qui contient les deux** : une ligne `A` avec la somme, `checked` seulement si les deux l'étaient ; une liste qui n'a que `B` : la ligne devient `A`, `checked` inchangé.
- **Unités d'achat différentes** : `B` en « kilo », `A` en « gramme » (conversion connue) -> quantités de liste converties, `listQuantitiesConverted: true`, compteurs en `ceil`.
- **Unité manquante** : une ligne de recette de `B` en « tranche » que `A` ne sait pas convertir -> `GET …/merge-preview` : `canMerge: false`, `missingUnits: [tranche]`, `POST` : `422 MERGE_UNIT_MISSING` et **aucune ligne modifiée** (comptage).
- **Liste `SENT`** contenant `A` ou `B` -> `409 INGREDIENT_IN_SENT_LIST`, rien modifié ; après `reopen`, la fusion passe.
- **Choix du drive.** `A` et `B` ont chacun un choix pour le même magasin : celui de `A` reste, celui de `B` et ses produits sont supprimés ; magasin où seul `B` a un choix : le choix passe sur `A` avec ses produits et leurs `pack_size_*` (DRV-652) ; si l'unité d'achat de `B` diffère de celle de `A`, la contenance reste en base mais le plan n'en tient pas compte (unité différente de celle de la ligne) ; un second magasin/enseigne est traité séparément. `GetDrivePlan` relu donne les produits de `A`.
- **Rayons différents** : le rayon de `A` l'emporte, `departmentChanges: true`.
- **Sécurité.** `B` à un autre utilisateur -> `404` ; `B` commun -> `422 CANNOT_MERGE_COMMON_INGREDIENT` ; `A = B` -> `422 CANNOT_MERGE_INTO_ITSELF` ; **un administrateur** qui tente les ingrédients d'un autre -> `404` ; une ligne héritée d'un autre utilisateur sur `B` -> `409 INGREDIENT_USED_BY_ANOTHER_USER`.
- **Idempotence.** Un second `POST` identique -> `204` sans rien changer ; `POST` vers un autre ingrédient que celui de l'alias -> `404`.
- **Alias.** Un alias d'adoption qui désignait `B` désigne `A` ; une requête qui envoie encore le slug `B` (recette, liste, choix du drive) atterrit sur `A` (via ING-002).
- **Statistiques.** Fusionner un ingrédient créé par l'utilisateur décrémente `statistics.ingredients` ; fusionner une copie d'adoption ne le fait pas.
- **Budget** `fusionner-ingredients` figé, **indépendant du volume** (même budget avec 2 ou 20 recettes, mesuré).
- **Concurrence.** Une écriture de recette qui a lu la version avant la fusion reçoit `409`/`412` au lieu de recréer une ligne sur `B` supprimé.

## Tests exigés

`IngredientMergePlannerTest` (table des unités) ; `MergeIngredientsHandlerTest` en mémoire (tous les critères) ; `IngredientMergeRepositoryTest` base réelle avec données de tous types (recette double, liste double, `SENT`, plusieurs magasins et enseignes, produits mémorisés, donnée d'un autre utilisateur, alias) ; `GetMergePreviewQueryHandlerTest`, `…LoaderTest` ; trois controllers (`MockCommandBus`/`MockQueryBus`, clés JSON, tous les statuts) ; `UserOwnedDataCoverageTest` inchangé ; `WriteBudget` ; parcours complet avec les vrais bus (créer, planifier, fusionner, relire recette et liste, `412` d'un ancien `If-Match`). `make test quality openapi-check`.

## Definition of Done

`repas-dev` § 4 ; le contrat `docs/api/openapi.json` régénéré et relu fait foi pour le front (ING-007).

## Pièges

- **Ne rien faire via `RemoveIngredient`** (il refuse un ingrédient utilisé et émet des événements, ADR 0021 décision 9) : suppression en masse dédiée.
- Une fusion de lignes de liste est une **écriture hors de `ShoppingList::assertEditable`** : les listes `SENT` sont exclues **avant** (règle 8), les autres statuts (pause, terminée) sont modifiables.
- Les compteurs `shopping_list_ingredient` sont des entiers de pas : `ceil` et non `round`, pour ne pas faire disparaître une unité.
- Les `UPDATE` en masse ne passent pas par Doctrine : `clear()` si le test charge un agrégat avant et après.
- Ne pas laisser un ingrédient « fantôme » : toute table qui référence un ingrédient doit être dans la liste de `fusion.md` § 3 ; si une nouvelle table apparaît (`grep ingredient` dans les entités), l'ajouter et un test.
