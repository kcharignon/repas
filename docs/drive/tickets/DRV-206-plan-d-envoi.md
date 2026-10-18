# DRV-206 — Plan d'envoi

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : M
- **Dépend de** : DRV-202, DRV-203, DRV-204, DRV-205
- **Statut** : fait (PR [#PRNUM](https://github.com/kcharignon/repas/pull/PRNUM), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 6, 7 (plan) ; ADR 0003, 0004, 0005 ; skill `repas-dev` § 3.1 et 3.2.

## Contexte

Quand l'utilisateur envoie sa liste, le front demande au back le **plan** : pour chaque ligne, ce que l'agent doit faire (produit connu avec son nombre de produits, nouvel ingrédient à chercher, ingrédient « j'en ai toujours »). Toute la logique (conditionnements) est ici, pas dans le front.

## Portée

1. Requête `Application/Query/GetDrivePlan/` : `GetDrivePlanQuery(userId, shoppingListId)` (`final readonly`, `implements QueryInterface`), `GetDrivePlanQueryHandler` (`#[AsMessageHandler(bus: 'query.bus')]`, délègue au loader, renvoie son résultat tel quel), DTO `DrivePlanDTO` / `DrivePlanLineDTO` / `DrivePlanProductDTO` / `DrivePlanStoreDTO` / `DrivePlanCountsDTO` (formes au § 7 de `architecture.md`).
2. Port `Application/Loader/DrivePlanLoader` ; implémentation `Infrastructure/Loader/DrivePlanPostgreSQLLoader` qui :
   - lit les réglages de l'utilisateur (`DRIVE_NOT_CONFIGURED`, 409, s'il n'y en a pas) ;
   - lit la liste par le port `Shared` `ShoppingListForDriveLoader` ; si son `status` n'est pas `SENT` : `SHOPPING_LIST_NOT_SENT` (409) ; si `ownerId` n'est pas `userId` : refus (le voter du contrôleur est la défense principale) ;
   - lit les préférences de l'utilisateur pour ce magasin avec leurs produits, acceptés et refusés (deux requêtes au plus : `drive_choice` ↔ `drive_choice_product` ↔ `drive_product`) ;
   - pour chaque ligne : préférence `HAVE_AT_HOME` → statut `HAVE_AT_HOME` ; au moins un produit accepté → `KNOWN` avec `products` classés par rang croissant (5 au plus), **chacun avec ses propres `packs` et `packsStatus`** par `PackCalculator::packsFor` (les contenus diffèrent d'un produit à l'autre) et `rejected` (produits refusés, 20 au plus) ; sinon `NEW` avec `products` vide, `rejected` éventuel et `searchQuery` = nom de l'ingrédient ; `needed` = quantité et symbole d'unité de la ligne ; `counts` ;
   - renvoie aussi `shoppingListId`, `listVersion`, `retailer`, `store`, `generatedAt` (horloge injectée).
3. Contrôleur `GET /api/v1/drive/plans/{shoppingListId}` : `#[IsGranted('ROLE_USER')]`, `#[IsGranted('SHOPPING_LIST_OWNER', 'shoppingListId')]`, `404 DRIVE_DISABLED` si coupé, réponse = le DTO, `#[OA\…]` (200, 403, 404, 409) ; `make openapi`.
4. L'ordre des lignes est celui de la liste (slug d'ingrédient croissant) ; stable.

## Hors portée

Surcharges par ligne (« autre produit », « passer ») : faites par le front au moment de construire le message (DRV-404). Suggestions de produits.

## Critères d'acceptation

- Pour une liste `SENT` avec 4 lignes (un ingrédient connu avec deux produits classés et un produit refusé, un ingrédient neuf, un ingrédient neuf avec un produit refusé seulement, un « j'en ai toujours »), le plan renvoie `counts {known:1,new:2,haveAtHome:1}`, pour la ligne connue les deux produits dans l'ordre avec leurs `packs` et `packsStatus` calculés séparément, le produit refusé dans `rejected`, et `needed` correct.
- Liste non `SENT` : `409 SHOPPING_LIST_NOT_SENT` ; pas de réglage : `409 DRIVE_NOT_CONFIGURED` ; liste d'un autre utilisateur : `403` ; inconnue : `404`.
- Les préférences d'un autre magasin ne produisent ni ligne `KNOWN` ni produit refusé.
- Nombre de requêtes SQL constant quelle que soit la taille de la liste (≤ 5).

## Tests exigés

Handler en unitaire avec `DrivePlanInMemoryLoader` (paramètres transmis, résultat tel quel) ; loader sur base réelle avec le port `Shared` doublé ou la vraie liste (les cas ci-dessus) ; contrôleur (clés JSON du plan, 200, 403, 404, 409) ; `QueryConventionTest` ; `make api-doc-validate api-doc-coverage openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4.

## Pièges connus

- Un `QueryHandler` n'appelle jamais un `Repository` : tout passe par le loader.
- Le loader peut utiliser le service de domaine `PackCalculator` (fonction pure) sans enfreindre l'architecture ; il ne manipule pas de modèle d'agrégat.

## Précision (2026-10-18, DRV-306)

Chaque ligne du plan renvoie `needed : {quantity, unitSymbol, dimension, baseFactor}` (l'agent s'en sert pour calculer le nombre de produits en phase B). Voir `architecture.md` (ajout du 2026-10-18) et `drive-agent/protocol/plan.schema.json`.

## Réalisation (2026-10-18, PR repas#PRNUM)

- **Lecture** : `GET /api/v1/drive/plans/{shoppingListId}` (`GetDrivePlanApiController`, `#[RequiresDrive]`, `ROLE_USER` + voter `SHOPPING_LIST_OWNER`), `GetDrivePlanQuery(userId, shoppingListId)` → `GetDrivePlanQueryHandler` → port `Drive\Application\Loader\DrivePlanLoader` → `DrivePlanPostgreSQLLoader`. DTO dans `Application/Query/GetDrivePlan/` : `DrivePlanDTO{shoppingListId, listVersion, retailer, store, generatedAt, counts, lines}`, `DrivePlanCountsDTO{known, new, haveAtHome}`, `DrivePlanLineDTO{ingredientSlug, ingredientName, needed, status, products, rejected, searchQuery}`, `DrivePlanNeededDTO{quantity, unitSymbol, dimension, baseFactor}`, `DrivePlanProductDTO{rank, retailerProductId, ean, label, content, packs, packsStatus}`, `DrivePlanRejectedDTO{retailerProductId, label}`. **Écart** : le magasin réutilise `DriveStoreDTO` (`GetDriveSettings`) et le contenu `ProductContentDTO` (`ListChoices`), de forme identique, au lieu d'un `DrivePlanStoreDTO` dédié.
- **Contrat que DRV-404 recopie** (message `plan`) : `lines[]` triées par slug d'ingrédient ; `needed` = `{quantity (nombre), unitSymbol, dimension (WEIGHT|VOLUME|COUNT|PACKAGE|null), baseFactor (nombre|null)}` ; `status` ∈ `KNOWN|NEW|HAVE_AT_HOME` ; `products[]` (vide hors `KNOWN`, 5 au plus, par rang) = `{rank, retailerProductId, ean|null, label, content: {value, unit}|null, packs: 1..99|null, packsStatus: COMPUTED|TO_CHECK}` avec `packs` calculé **pour chaque produit** (`PackCalculator`, un appel par produit) ; `rejected[]` = `{retailerProductId, label}` (20 au plus, aussi sur une ligne `NEW`, vide pour `HAVE_AT_HOME`) ; `searchQuery` = **le nom de l'ingrédient tel quel** (casse conservée : « Farine », pas « farine ») sur **toutes** les lignes, `HAVE_AT_HOME` comprise ; `counts` = `{known, new, haveAtHome}`.
- **Erreurs** : `404 DRIVE_DISABLED` (coupé) ; `403` (liste d'un autre utilisateur : voter ; le loader refuse aussi, `SHOPPING_LIST_FORBIDDEN`, y compris pour un administrateur) ; `404` (liste inconnue) ; `409 DRIVE_NOT_CONFIGURED` ; `409 SHOPPING_LIST_NOT_SENT` (nouvelles fabriques `DriveException::shoppingListNotSent()` et `shoppingListForbidden()` : un contexte n'importe pas l'exception de `Repas`). Ordre des contrôles du loader : réglages, liste (404), propriétaire, statut `SENT`.
- **Requêtes** : 4 au plus, quelle que soit la taille de la liste (réglages, liste, lignes par le port `Shared` `ShoppingListForDriveLoader`, puis une requête de préférences du magasin courant pour les slugs de la liste, avec leurs produits) ; test de base avec 12 ingrédients. Les préférences d'un autre magasin ou d'un autre utilisateur ne comptent pas (ligne `NEW`, aucun produit refusé) ; un produit absent du catalogue est ignoré. `generatedAt` vient de l'horloge injectée (`Psr\Clock\ClockInterface`). L'alias public de test de `ShoppingListForDriveLoader` est supprimé de `config/services.yaml`.
- Tests : handler (`DrivePlanInMemoryLoader`), loader (`DrivePlanPostgreSQLLoaderTest`), controller (`GetDrivePlanApiControllerTest`, clés JSON), parcours réel `DrivePlanApiFlowTest` (préférences posées par `PUT /drive/choices` puis plan d'une vraie liste `SENT`) ; `docs/api/openapi.json` régénéré.
