# DRV-204 — Produits, choix et priorités

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : L
- **Dépend de** : DRV-201, DRV-202 (noms d'ingrédients), DRV-203 (magasin courant)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 7 ; skill `repas-dev` ; ADR 0003, 0007, 0013 ; `src/Repas/Infrastructure/Repository/*PostgreSQLRepository.php` pour les patterns (`preload()`).

## Contexte

Pour chaque ingrédient, un utilisateur garde une **préférence** par magasin : une liste ordonnée de produits qu'il accepte (le premier est le préféré, les suivants servent de secours si ceux qui précèdent sont indisponibles), une liste de produits qu'il refuse (jamais proposés ni mis au panier), ou « j'en ai toujours ». Exemple : « je prends la farine Marque Repère si elle est disponible ; sinon une autre farine T45 me va ; mais pas la farine complète ». Le `Produit` est un fait public sans propriétaire ; la préférence est privée.

## Portée

1. Domaine : `ContentUnit`, `ProductContent`, `Product`, `Choice` et `AcceptedProduct` (voir `architecture.md` § 5.1). Invariants testés : rangs 1..n contigus, **5 produits acceptés au plus, 20 refusés au plus**, un produit n'est jamais à la fois accepté et refusé, aucun doublon, `HAVE_AT_HOME` n'a ni accepté ni refusé. Comportements : `addPrimary`, `addFallback`, `reject`, `unreject`, `remove`, `reorder`, `markHaveAtHome`, `resumeBuying`. `DriveException` (en plus de DRV-203) : `invalidChoice()` (422, `DRIVE_CHOICE_INVALID`), `choiceNotFound()` (404, `DRIVE_CHOICE_NOT_FOUND`), `storeMismatch()` (409, `DRIVE_STORE_MISMATCH`).
2. Ports et dépôts : `ProductRepository` (`findByKeys(list<array{retailer,storeId,retailerProductId}>)` en **une** requête, `findByIds(list<string>)`, `save`), `ChoiceRepository` (`findByUserStoreAndSlugs(...)` en une requête avec ses lignes de produits, `findOne(...)`, `save` qui réécrit les lignes `drive_choice_product`, `delete`, `deleteByUser`) ; implémentations PostgreSQL, entités et migration (tables `drive_product`, `drive_choice` et `drive_choice_product` du § 5.2, compatibles avec la version précédente), doubles `ProductInMemoryRepository` et `ChoiceInMemoryRepository`, builders de test.
3. Écriture `Application/Command/SaveProductChoices/` (`SaveProductChoicesCommand(userId, retailer, storeId, list<ChoiceIntent>)`, handler dans la transaction du `command.bus`) :
   - le `storeId` doit être celui des réglages de l'utilisateur, sinon `DRIVE_STORE_MISMATCH` (409) ; pas de réglage : `DRIVE_NOT_CONFIGURED` (409) ;
   - ≤ 200 intentions ; `ingredientSlug` `^[a-z0-9-]{1,255}$` ; `action` ∈ `ADD_PRODUCT` (`product` + `placement` ∈ `PRIMARY|FALLBACK`), `REJECT_PRODUCT` (`product`), `HAVE_AT_HOME` ; produit : `retailerProductId` `^[0-9]{1,12}$`, `label` 1..255, `ean` `^[0-9]{8,14}$` ou nul, `content` `{value>0 et ≤ 100000, unit ∈ g|kg|ml|cl|l|piece}` ou nul ;
   - fusion dans la préférence existante (créée si absente), intentions appliquées **dans l'ordre reçu** pour un même ingrédient : `ADD_PRODUCT PRIMARY` (rang 1), `ADD_PRODUCT FALLBACK` (à la fin ; `422` si déjà 5 acceptés), `REJECT_PRODUCT` (retiré des acceptés, ajouté aux refusés), `HAVE_AT_HOME` ;
   - upsert des `Product` (clé `(retailer, storeId, retailerProductId)`), `updatedAt` et `lastSeenAt` par l'horloge ;
   - **requêtes en nombre constant** (une lecture des produits, une des préférences, puis les écritures) : scénario `WriteBudget` ;
   - l'existence de l'ingrédient n'est pas vérifiée (`Drive` ne connaît pas `Repas`) : une préférence orpheline est inoffensive.
4. Écriture `Application/Command/ForgetChoice/` : supprime la préférence d'un ingrédient du magasin courant (`DRIVE_CHOICE_NOT_FOUND` sinon) avec ses lignes de produits ; les produits restent.
5. Lecture `Application/Query/ListChoices/` : `ListChoicesQuery(userId, retailer)` → `{member: [ChoiceDTO{ingredientSlug, ingredientName, haveAtHome, accepted:[{rank, product}], rejected:[product], updatedAt}], totalItems}` pour le magasin courant, avec `product = {productId, retailerProductId, ean, label, content}` ; sans réglage : liste vide ; `ChoiceLoader`/`ChoicePostgreSQLLoader` (nombre de requêtes constant ; noms par le port `IngredientNameLoader` de DRV-202, le slug à défaut).
6. API : `PUT /api/v1/drive/choices` (lot, `204`), `GET /api/v1/drive/choices?retailer=leclerc`, `DELETE /api/v1/drive/choices/{ingredientSlug}?retailer=&storeId=` (`204`) ; `404 DRIVE_DISABLED` quand coupé ; identité par le JWT ; `#[OA\…]` ; `make openapi`.
7. Le remplacement complet d'une préférence depuis l'écran de gestion est dans DRV-208. La purge (y compris `drive_choice_product`) est dans DRV-207.

## Hors portée

Calcul des conditionnements (DRV-205), plan (DRV-206), remplacement complet (DRV-208), suggestions automatiques.

## Critères d'acceptation

- Un lot `ADD_PRODUCT PRIMARY` sur un ingrédient neuf crée une préférence avec ce produit au rang 1.
- Un second lot `ADD_PRODUCT FALLBACK` ajoute un produit au rang 2 ; un `ADD_PRODUCT PRIMARY` ensuite met le nouveau produit en rang 1 et décale les autres ; redemander un produit déjà présent le remonte (PRIMARY) ou ne change rien (FALLBACK).
- `REJECT_PRODUCT` retire le produit des acceptés (rangs recompactés) et l'ajoute aux refusés ; `ADD_PRODUCT` d'un produit refusé le retire des refusés.
- Un sixième produit accepté, un vingt et unième refusé, un produit à la fois accepté et refusé dans une même demande : `422 DRIVE_CHOICE_INVALID`, rien d'enregistré.
- `HAVE_AT_HOME` efface les produits de l'ingrédient ; un `ADD_PRODUCT` ensuite le fait redevenir une préférence de produits.
- Deux utilisateurs qui choisissent le même produit partagent une seule ligne `drive_product`.
- Un lot avec un `storeId` différent du magasin courant : `409 DRIVE_STORE_MISMATCH`, rien d'enregistré (atomique).
- `GET /drive/choices` renvoie `{member, totalItems}` avec nom d'ingrédient, produits classés par rang et produits refusés ; `DELETE` d'une préférence inexistante : `404`.
- Un utilisateur ne voit ni ne supprime les préférences d'un autre.

## Tests exigés

Domaine (chaque invariant et comportement de `Choice`) ; handlers en unitaire (doubles en mémoire) ; dépôts et loader sur base réelle (upsert, clés uniques, rangs, nombre de requêtes) ; contrôleurs (clés JSON, 204, 409, 422, 404) ; parcours de bout en bout avec les vrais bus ; scénarios `WriteBudget` pour `SaveProductChoices` (avec 1 et avec plusieurs intentions : nombre de requêtes constant) et `ForgetChoice`.

## Definition of Done

Celle du skill `repas-dev` § 4 ; `UserOwnedDataCoverageTest` étendu (DRV-207).

## Pièges connus

- Pas de clé étrangère : l'intégrité vient du code (un `ACCEPTED`/`REJECTED` désigne un `productId` existant ; réécrire les lignes d'une préférence dans la même transaction).
- Les agents des utilisateurs alimentent `drive_product` : ne jamais faire confiance au contenu (bornes, regex), pas de HTML.
- Un modèle chargé est une copie : charger une fois, enregistrer une fois.
- La contrainte `UNIQUE (choice_id, rank)` est sensible à l'ordre des écritures lors d'un décalage de rangs : réécrire toutes les lignes de la préférence plutôt que mettre à jour une à une.
- **Prix et garde-fous (décisions D22 et D23)** : mémoriser pour un produit **choisi par un utilisateur** l'identifiant, le libellé, le contenu net **et le prix** (`priceCents`, `pricePerMeasure`, `priceSeenAt`, voir `architecture.md`) ; pas de photos ; aucune route de l'API qui liste ou cherche dans la table `drive_product` en dehors des produits des propres choix ou plans de l'utilisateur connecté.

- **Prix des lignes `ADDED` (décision du 2026-10-17)** : le résultat de l'agent peut porter un `price` sur une ligne `ADDED` (produit connu) ; `SaveProductChoices` met à jour `priceCents`, `pricePerMeasure` et `priceSeenAt` du produit sans toucher aux choix.

- Précision (2026-10-18) : le champ `price` d'une ligne `ADDED` a la même forme que `product.price` d'une ligne `CHOSEN` (`{cents, perMeasure?: {cents, unit}}`) ; la validation côté back en tient compte.
