# DRV-204 — Produits et choix

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : L
- **Dépend de** : DRV-201, DRV-202 (noms d'ingrédients), DRV-203 (magasin courant)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 7 ; skill `repas-dev` ; ADR 0003, 0007, 0013 ; `src/Repas/Infrastructure/Repository/*PostgreSQLRepository.php` pour les patterns (`preload()`).

## Contexte

Chaque ingrédient d'un utilisateur peut avoir un produit mémorisé par magasin, ou être marqué « j'en ai toujours ». Le `Produit` est un fait public sans propriétaire ; le `Choix` est privé. Ils sont alimentés par le résultat de l'agent (lot de choix) et gérés depuis l'écran « Mes produits ».

## Portée

1. Domaine : `ContentUnit`, `ProductContent`, `Product`, `Choice` (voir `architecture.md` § 5.1). `Product::create` (id et date injectés), `Product::refresh(label, ean, content, seenAt)` ; `Choice::chooseProduct(Product)`, `Choice::haveAtHome()`. Règles : `PRODUCT` exige un produit, `HAVE_AT_HOME` n'en a pas ; `DriveException` (en plus de DRV-203) : `invalidChoice()` (422, `DRIVE_CHOICE_INVALID`), `choiceNotFound()` (404, `DRIVE_CHOICE_NOT_FOUND`), `storeMismatch()` (409, `DRIVE_STORE_MISMATCH`).
2. Ports et dépôts : `ProductRepository` (`findByKeys(list<array{retailer,storeId,retailerProductId}>)` en **une** requête, `save`), `ChoiceRepository` (`findByUserStoreAndSlugs(...)` en une requête, `findOne(...)`, `save`, `delete`, `deleteByUser`) ; implémentations PostgreSQL, entités et migration (tables `drive_product` et `drive_choice` du § 5.2, compatibles avec la version précédente), doubles `ProductInMemoryRepository` et `ChoiceInMemoryRepository`, builders de test.
3. Écriture : `Application/Command/SaveProductChoices/` (`SaveProductChoicesCommand(userId, retailer, storeId, list<ChoiceInput>)`, handler dans la transaction du `command.bus`) :
   - le `storeId` doit être celui des réglages de l'utilisateur (`DriveSettingsRepository`), sinon `DRIVE_STORE_MISMATCH` (409) ; pas de réglage : `DRIVE_NOT_CONFIGURED` (409) ;
   - ≤ 200 éléments ; `ingredientSlug` `^[a-z0-9-]{1,255}$` ; `kind` ∈ `PRODUCT|HAVE_AT_HOME` ; produit : `id` `^[0-9]{1,12}$`, `label` 1..255, `ean` `^[0-9]{8,14}$` ou nul, `content` `{value>0 et ≤ 100000, unit ∈ g|kg|ml|cl|l|piece}` ou nul ;
   - upsert des `Product` (clé `(retailer, storeId, retailerProductId)`), puis des `Choice` (clé `(userId, retailer, storeId, ingredientSlug)`), `chosenAt` et `lastSeenAt` par l'horloge ;
   - **requêtes en nombre constant** (une lecture des produits, une des choix, puis les écritures) : scénario `WriteBudget` ;
   - l'existence de l'ingrédient n'est pas vérifiée (`Drive` ne connaît pas `Repas`) : un choix orphelin est inoffensif.
   `Application/Command/ForgetChoice/` : supprime le choix de l'utilisateur pour un ingrédient du magasin courant (`DRIVE_CHOICE_NOT_FOUND` sinon). Le produit reste.
4. Lecture : `Application/Query/ListChoices/` (`ListChoicesQuery(userId, retailer)` → `{member: [ChoiceDTO{ingredientSlug, ingredientName, kind, product: {id, ean, label, content}|null, chosenAt}], totalItems}` pour le magasin courant ; sans réglage : liste vide), `ChoiceLoader`/`ChoicePostgreSQLLoader` (utilise le port `IngredientNameLoader` de DRV-202 ; nom absent : le slug).
5. API : `PUT /api/v1/drive/choices` (lot, `204`), `GET /api/v1/drive/choices?retailer=leclerc`, `DELETE /api/v1/drive/choices/{ingredientSlug}?retailer=&storeId=` (`204`) ; `404 DRIVE_DISABLED` quand coupé ; identité par le JWT ; `#[OA\…]` ; `make openapi`.
6. Purge : DRV-207.

## Hors portée

Calcul des conditionnements (DRV-205), plan (DRV-206), suggestions.

## Critères d'acceptation

- Un lot de 3 choix (2 `PRODUCT`, 1 `HAVE_AT_HOME`) est enregistré ; un second lot avec le même ingrédient remplace le choix précédent et met à jour le produit (libellé, contenu, `lastSeenAt`).
- Deux utilisateurs qui choisissent le même produit partagent une seule ligne `drive_product`.
- Un lot avec un `storeId` différent du magasin courant : `409 DRIVE_STORE_MISMATCH`, rien d'enregistré (atomique).
- Un élément invalide (id non numérique, `PRODUCT` sans produit, `HAVE_AT_HOME` avec produit, plus de 200 éléments) : `422`, rien d'enregistré.
- `GET /drive/choices` renvoie `{member, totalItems}` avec nom d'ingrédient et produit ; `DELETE` d'un choix inexistant : `404`.
- Un utilisateur ne voit ni ne supprime les choix d'un autre.

## Tests exigés

Domaine ; handlers en unitaire (doubles en mémoire, `SpyEventDispatcher` si utile) ; dépôts et loader sur base réelle (upsert, clé unique, nombre de requêtes) ; contrôleurs (clés JSON, 204, 409, 422, 404) ; parcours de bout en bout avec les vrais bus ; scénarios `WriteBudget` pour `SaveProductChoices` (avec 1 et avec plusieurs éléments : nombre de requêtes constant) et `ForgetChoice`.

## Definition of Done

Celle du skill `repas-dev` § 4 ; `UserOwnedDataCoverageTest` étendu (DRV-207).

## Pièges connus

- Pas de clé étrangère : l'intégrité vient du code (un `Choice` `PRODUCT` a un `productId` existant).
- Les agents des utilisateurs alimentent `drive_product` : ne jamais faire confiance au contenu (bornes, regex), pas de HTML.
- Un modèle chargé est une copie : charger une fois, enregistrer une fois.
