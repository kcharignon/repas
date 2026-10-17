# DRV-206 — Plan d'envoi

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : M
- **Dépend de** : DRV-202, DRV-203, DRV-204, DRV-205
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 6, 7 (plan) ; ADR 0003, 0004, 0005 ; skill `repas-dev` § 3.1 et 3.2.

## Contexte

Quand l'utilisateur envoie sa liste, le front demande au back le **plan** : pour chaque ligne, ce que l'agent doit faire (produit connu avec son nombre de produits, nouvel ingrédient à chercher, ingrédient « j'en ai toujours »). Toute la logique (conditionnements) est ici, pas dans le front.

## Portée

1. Requête `Application/Query/GetDrivePlan/` : `GetDrivePlanQuery(userId, shoppingListId)` (`final readonly`, `implements QueryInterface`), `GetDrivePlanQueryHandler` (`#[AsMessageHandler(bus: 'query.bus')]`, délègue au loader, renvoie son résultat tel quel), DTO `DrivePlanDTO` / `DrivePlanLineDTO` / `DrivePlanProductDTO` / `DrivePlanStoreDTO` / `DrivePlanCountsDTO` (formes au § 7 de `architecture.md`).
2. Port `Application/Loader/DrivePlanLoader` ; implémentation `Infrastructure/Loader/DrivePlanPostgreSQLLoader` qui :
   - lit les réglages de l'utilisateur (`DRIVE_NOT_CONFIGURED`, 409, s'il n'y en a pas) ;
   - lit la liste par le port `Shared` `ShoppingListForDriveLoader` ; si son `status` n'est pas `SENT` : `SHOPPING_LIST_NOT_SENT` (409) ; si `ownerId` n'est pas `userId` : refus (le voter du contrôleur est la défense principale) ;
   - lit les choix de l'utilisateur pour ce magasin avec leurs produits (une requête, jointure `drive_choice` ↔ `drive_product`) ;
   - pour chaque ligne : `HAVE_AT_HOME` → statut `HAVE_AT_HOME` ; `PRODUCT` → `KNOWN` avec produit et `PackCalculator::packsFor` ; sinon `NEW` avec `searchQuery` = nom de l'ingrédient ; `needed` = quantité et symbole d'unité de la ligne ; `counts` ;
   - renvoie aussi `shoppingListId`, `listVersion`, `retailer`, `store`, `generatedAt` (horloge injectée).
3. Contrôleur `GET /api/v1/drive/plans/{shoppingListId}` : `#[IsGranted('ROLE_USER')]`, `#[IsGranted('SHOPPING_LIST_OWNER', 'shoppingListId')]`, `404 DRIVE_DISABLED` si coupé, réponse = le DTO, `#[OA\…]` (200, 403, 404, 409) ; `make openapi`.
4. L'ordre des lignes est celui de la liste (slug d'ingrédient croissant) ; stable.

## Hors portée

Surcharges par ligne (« autre produit », « passer ») : faites par le front au moment de construire le message (DRV-404). Suggestions de produits.

## Critères d'acceptation

- Pour une liste `SENT` avec 3 lignes (un produit connu en grammes, un ingrédient neuf, un « j'en ai toujours »), le plan renvoie `counts {known:1,new:1,haveAtHome:1}`, les `packs` et `packsStatus` calculés, `needed` correct.
- Liste non `SENT` : `409 SHOPPING_LIST_NOT_SENT` ; pas de réglage : `409 DRIVE_NOT_CONFIGURED` ; liste d'un autre utilisateur : `403` ; inconnue : `404`.
- Les choix d'un autre magasin ne produisent pas de ligne `KNOWN`.
- Nombre de requêtes SQL constant quelle que soit la taille de la liste (≤ 5).

## Tests exigés

Handler en unitaire avec `DrivePlanInMemoryLoader` (paramètres transmis, résultat tel quel) ; loader sur base réelle avec le port `Shared` doublé ou la vraie liste (les cas ci-dessus) ; contrôleur (clés JSON du plan, 200, 403, 404, 409) ; `QueryConventionTest` ; `make api-doc-validate api-doc-coverage openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4.

## Pièges connus

- Un `QueryHandler` n'appelle jamais un `Repository` : tout passe par le loader.
- Le loader peut utiliser le service de domaine `PackCalculator` (fonction pure) sans enfreindre l'architecture ; il ne manipule pas de modèle d'agrégat.
