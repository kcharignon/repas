# DRV-204 — Produits, choix et priorités

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : L
- **Dépend de** : DRV-201, DRV-202 (noms d'ingrédients), DRV-203 (magasin courant)
- **Statut** : fait (PR [#138](https://github.com/kcharignon/repas/pull/138), 2026-10-18)
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

## Réalisation (2026-10-18, PR repas#138)

- **Domaine** (`Repas\Drive\Domain\`) : `Model\Product` (`create(Retailer, storeId, retailerProductId, ?ean, label, ?ProductContent, ?ProductPrice, now)`, `see(?ean, label, ?content, ?price, now)` : le libellé est remplacé, un champ que l'agent n'a pas su lire garde sa valeur connue ; `seePrice(?price, now)` ; `idFor()` et `keyOf()` ; **l'identifiant est un UUID v5 déduit de la clé naturelle**, donc sans doublon ni préférence orpheline si deux requêtes découvrent le même produit en même temps), `Model\ProductPrice(cents, ?PricePerMeasure)` et `Model\PricePerMeasure(cents, PriceUnit)` (`PriceUnit` : `kg|l|piece` ; 0 à 1 000 000 centimes, sinon `invalidChoice()`), `ProductContent::isPlausible()` (0,001 à 100 000), `Model\Choice` (`create`, `load`, `addPrimary`, `addFallback`, `reject`, `unreject`, `remove`, `reorder` (permutation des acceptés), `markHaveAtHome`, `resumeBuying`, `touch`, `getAccepted(): list<AcceptedProduct{productId, rank}>`, `getAcceptedProductIds()`, `getRejectedProductIds()`, `isAccepted()`), `ChoiceKind`, `AcceptedProduct`. Une méthode de `Choice` qui échoue ne change rien. `addPrimary`, `addFallback` **et `reject`** font redevenir une préférence de produits un ingrédient « j'en ai toujours ». `DriveException` : `invalidChoice()` (422), `choiceNotFound()` (404), `storeMismatch()` (409). Ports `ProductRepository` (`findByKeys`, `findByIds`, `saveAll`, `save`) et `ChoiceRepository` (`findByUserStoreAndSlugs`, `findOne`, `saveAll`, `save`, `delete`, `deleteProductsByUser`, `deleteByUser`).
- **Persistance** : migration `Version20261018110000` (3 tables du § 5.2 ; `drive_product` a en plus `price_cents`, `price_per_measure_cents`, `price_per_measure_unit`, `price_seen_at`), entités `Drive\Infrastructure\Entity\{Product,Choice,ChoiceProduct}` (mapping seulement : schéma et loaders), `ProductPostgreSQLRepository` et `ChoicePostgreSQLRepository` en **SQL direct** : un lot coûte une requête par table (`INSERT … ON CONFLICT` pour les produits sur la clé naturelle et pour les préférences sur l'identifiant ; les lignes `drive_choice_product` d'une préférence sont supprimées puis réinsérées, ce qui évite tout conflit de `UNIQUE (choice_id, rank)`).
- **Écritures** : `SaveProductChoicesCommand(userId, Retailer, storeId, list<ChoiceIntent>)` + handler ; `ChoiceIntent(ingredientSlug, ChoiceAction, ?Placement, ?ProductInput)`, `ProductInput(retailerProductId, ?label, ?ean, ?ProductContent, ?ProductPrice)`. Ordre des contrôles : lot et intentions (aucune lecture), réglages (`DRIVE_NOT_CONFIGURED`, `DRIVE_STORE_MISMATCH`), puis produits, préférences, écritures. Budget SQL : 7 requêtes, que le lot ait 1 ou 41 intentions (`choix-drive`, `choix-drive-lot`). `ForgetChoiceCommand(userId, Retailer, storeId, ingredientSlug)` : mêmes 409, `DRIVE_CHOICE_NOT_FOUND` (404) ; budget 4 (`oublier-choix-drive`).
- **Intention ajoutée, `REFRESH_PRICE`** (prix des lignes `ADDED`, décision du 2026-10-17) : `{ingredientSlug, action: "REFRESH_PRICE", product: {retailerProductId, price: {cents, perMeasure?: {cents, unit}}}}` (ni libellé ni placement). Met à jour `price*` et `priceSeenAt` du produit connu, sans toucher à la préférence ni à sa date ; **sans effet** (pas d'erreur) si le produit est inconnu ou n'est pas accepté pour cet ingrédient par l'utilisateur, car un résultat peut arriver après un changement de préférences, et un utilisateur ne doit pas pouvoir réécrire le prix d'un produit qu'il n'a pas choisi. Le front (DRV-405) l'envoie pour chaque ligne `ADDED` qui porte un `price`. Le prix d'un `CHOSEN` part avec le produit de l'`ADD_PRODUCT`.
- **Lecture** : `ListChoicesQuery(userId, Retailer)` → `list<ChoiceDTO>` (`ingredientSlug`, `ingredientName`, `haveAtHome`, `accepted: list<AcceptedProductDTO{rank, product: ChoiceProductDTO{productId, retailerProductId, ean, label, content: ?ProductContentDTO{value, unit}}}>`, `rejected: list<ChoiceProductDTO>`, `updatedAt` ATOM), triés par slug ; `ChoiceLoader` / `ChoicePostgreSQLLoader` (magasin courant = celui des réglages, 2 requêtes dont les noms par `IngredientNameLoader` ; le slug à défaut). **Le prix n'est pas renvoyé** (estimation de coût : DRV-611). L'alias public de test de `IngredientNameLoader` est supprimé (celui de `ShoppingListForDriveLoader` reste jusqu'à DRV-206).
- **API** (`#[RequiresDrive]`, utilisateur du jeton) : `PUT /api/v1/drive/choices` corps `{retailer, storeId, choices: [ChoiceIntentPayload]}` → `204` ; `GET /api/v1/drive/choices?retailer=leclerc` → `{member, totalItems}` ; `DELETE /api/v1/drive/choices/{ingredientSlug}?retailer=&storeId=` → `204`. Les identifiants de produit sont des **chaînes** (`"555111"`, pas `555111`). Erreurs : `404 DRIVE_DISABLED`, `409 DRIVE_NOT_CONFIGURED` / `DRIVE_STORE_MISMATCH`, `404 DRIVE_CHOICE_NOT_FOUND`, `422 DRIVE_CHOICE_INVALID` (valeur hors bornes, plus de 200 intentions, 6e produit accepté, 21e refusé, produit à la fois ajouté et refusé pour un même ingrédient dans un lot) ou `422` de validation de forme (type, champ absent, énumération inconnue).
- **Purge** (prise en charge ici car `UserOwnedDataCoverageTest` l'exigeait) : `PurgeUserDriveDataHandler` supprime aussi `drive_choice_product` puis `drive_choice` ; entrée `Choice` dans `UserOwnedDataCoverageTest` ; `AccountAdministrationFlowTest` ; budget `supprimer-compte` 16 → 18. **DRV-207** n'a plus que la phrase de l'ADR 0021 à écrire.
- **Écarts** : `findByKeys` prend des tableaux `{retailer: Retailer, storeId, retailerProductId}` ; `saveAll` en plus de `save` ; pas de `updatedAt` sur `Product` (`lastSeenAt` suffit) ; le lot est refusé (`422`) si un produit est à la fois ajouté et refusé pour le même ingrédient, même si l'ordre d'application l'autoriserait ; un `REJECT_PRODUCT` sur un ingrédient « j'en ai toujours » le fait redevenir une préférence de produits : pour qu'une ligne « j'en ai toujours » qui porte aussi des `rejected` reste « j'en ai toujours », le front envoie `HAVE_AT_HOME` **après** les `REJECT_PRODUCT` du même ingrédient (il efface alors tout).
