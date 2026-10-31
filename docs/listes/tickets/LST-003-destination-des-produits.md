# LST-003 — Destination des produits (back)

- **Dépôt** : back — **Taille** : M — **Effort** : high — **Dépend de** : LST-002
- **Statut** : fait (PR de la branche `feat/lst-003-destination-des-produits`)
- **Lire d'abord** : ADR 0036 (décisions 3, 5, 13, 16) ; `CLAUDE.md` (puces ingrédients, alias, fusion).

## À faire

1. **`ListDestinationResolver`** (service de domaine pur) et port `ListRoutingRepository::destinationsFor(ownerId, slugs)` rendant, pour N produits, leur destination (`null` = principale, sinon l'id du type) **en une seule lecture SQL** : le choix du produit, sinon le type de son rayon, sinon la principale. Tests purs du résolveur (priorités, rayon sans type, produit à part « liste principale » dans un rayon typé).
2. **`PUT /api/v1/ingredients/{slug}/list-destination`** `{destination: "default"|"main"|<typeId>}` : `INGREDIENT_OWNER` (aucun passe-droit administrateur), slug résolu par les alias (`IngredientSlugWritesAreResolvedTest`), type inconnu ou d'un autre `404`, `204`. Valeur réservée `main` dans `ingredient.list_destination`.
3. **Lectures** : `GET /api/v1/ingredients/{slug}` gagne `listDestination` (`{mode: "DEFAULT"|"MAIN"|"TYPE", typeId, typeName}`, clé toujours écrite) ; la lecture des rayons (`GET /api/v1/departments`) gagne `listTypeId` (nullable, clé toujours écrite).
4. **Copie, amorçage, fusion** : une copie de produit ne porte jamais `list_destination` (`Ingredient::adopt`, `copyFromOriginal`, `copyIngredients` en masse, `SeedUserIngredients`) ; la fusion garde celui du produit conservé (`IngredientReferencesAreAllRepointedTest` à jour).
5. Contrat OpenAPI, budget SQL `choisir-liste-d-un-produit`.

## Critères d'acceptation

- [x] Les priorités de l'ADR décision 3 sont prouvées sur la vraie base (un compte, un rayon typé, un produit à part, un produit « liste principale »).
- [x] Un compte sans type : `listDestination` vaut `DEFAULT` partout, aucune autre réponse ne change.

## Réalisé

`ListDestinationResolver` (`Domain/Service`, pur : `resolve(choix, type du rayon, types qui existent)` ; un identifiant périmé retombe sur la règle suivante) et `ListRoutingRepository::destinationsFor(ownerId, slugs)` (`ListRoutingPostgreSQLRepository` : une seule requête, qui joint le choix du produit et le type de son rayon aux types DU compte ; un slug inconnu ou à un autre est rendu `null`, la liste principale ; `ListRoutingInMemoryRepository` pour les tests). `PUT /api/v1/ingredients/{slug}/list-destination` (`ChooseIngredientList`, déclaré dans `IngredientSlugWritesAreResolvedTest`, `INGREDIENT_OWNER` sans passe-droit, type inconnu ou d'un autre `404` sans écriture, un choix identique n'écrit rien). Lectures : `GET /ingredients/{slug}` gagne `listDestination` (`ListDestinationDTO`, un `LEFT JOIN` dans la requête existante) ; `GET /departments` gagne `listTypeId` par un nouveau DTO `DepartmentListItemDTO` (la liste des rayons est maintenant propre au compte : `ListDepartmentsQuery` porte l'utilisateur ; `DepartmentDTO` ne change pas pour les autres lectures). Copie, amorçage, fusion : rien à changer dans le code (une copie ne copie jamais la colonne, la fusion ne réécrit pas le produit gardé), prouvé par des tests (`IngredientTest`, `CopiedIngredientsKeepTheirConversionsTest`, `IngredientSeedingRepositoryTest`, `IngredientListDestinationApiFlowTest`). Budget SQL `choisir-liste-d-un-produit` (9, aucun autre budget ne bouge). Rien n'est encore branché sur les ajouts (LST-005).
