# DRV-404 — Flux d'envoi (bouton, confirmation, plan, ouverture de Leclerc, attente)

- **Dépôt** : front, `src/features/drive/` et `src/features/shopping-lists/`
- **Jalon** : M4 Front
- **Taille** : L
- **Dépend de** : DRV-102, DRV-206, DRV-401, DRV-402
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 3, 7, 8, 9 ; DRV-402 (module `driveBridge`).

## Contexte

C'est le parcours central : de « Envoyer au drive » à l'ouverture du drive avec le plan, puis l'attente du retour.

## Portée

1. **Bouton « Envoyer au drive »** dans `ShoppingListHeader.vue` : visible si la liste est `ACTIVE`, a au moins une ligne et `GET /drive/settings` renvoie `enabled: true` ; si aucun magasin n'est enregistré, il mène aux réglages (DRV-403) avec un message.
2. **Confirmation** (`UiConfirmDialog`) : « Envoyer cette liste au drive ? Elle ne sera plus modifiable. Tu pourras la rouvrir. » → `POST /shopping-lists/{id}/send` avec `If-Match` (`list.version`) ; `412` : recharger et informer ; succès : navigation vers `/drive/envoi/:listId`.
3. **Écran `/drive/envoi/:listId`** :
   - charge le plan (`GET /drive/plans/{id}`) ; `409 DRIVE_NOT_CONFIGURED` → vers les réglages ; liste pas encore `SENT` → message ;
   - affiche **toute la liste** : pour chaque ligne l'ingrédient, la quantité demandée et les produits **dans l'ordre de priorité** (« 1. Farine Marque Repère 1 kg · 2. Farine T45 1 kg (secours) »), chacun avec son nombre de produits (`packs`, avec « à vérifier » si `TO_CHECK`) ; les produits refusés sont indiqués (« refusés : … ») ; « Nouveau » (à choisir sur Leclerc) pour une ligne sans produit ; « Tu en as déjà » pour les autres ;
   - par ligne : **« Choisir un autre produit »** (la ligne devient `NEW` pour ce passage, avec `replaces`), **« Passer cette fois »** (`SKIP`) et leur annulation ; compteurs récapitulatifs ;
   - bouton principal « Ouvrir Leclerc » : génère un `runId` (uuid), construit le message (`buildPlanMessage` avec les surcharges), enregistre le brouillon, appelle `openLeclerc` ;
   - instruction visible : « Sur Leclerc, connecte-toi si besoin, puis touche le favori Repas (tape son nom dans la barre d'adresse). » avec lien vers l'aide (DRV-408) et vers l'installation du favori si l'agent n'a jamais répondu.
4. **État d'attente** : si un brouillon existe pour cette liste, l'écran affiche « En attente du retour de Leclerc… » avec « Rouvrir Leclerc » (même `runId`), « Recommencer » (nouveau `runId`) et « Rouvrir la liste » (annule l'envoi, avec confirmation) ; le brouillon survit à un rechargement du service worker.
5. Une liste déjà `SENT` ouverte par « Reprendre l'envoi » suit le même écran (plan recalculé, choix mémorisés pris en compte).
6. Hors ligne : bouton principal désactivé avec explication.
7. Aucune logique de conditionnement dans le front : on affiche `packs`/`packsStatus` du plan.

## Hors portée

Réception du résultat (DRV-405).

## Critères d'acceptation

- Un utilisateur avec une liste de 12 lignes (8 connues dont une avec deux produits classés et un refusé, 3 nouvelles, 1 « tu en as déjà ») voit les 12 lignes classées avec l'ordre de priorité de chaque produit, peut changer une ligne connue en « autre produit » et en passer une autre, puis « Ouvrir Leclerc » ouvre l'adresse du magasin avec un fragment qui décode en un plan de 12 lignes dont ces deux surcharges, la ligne à deux produits gardant ses deux produits dans l'ordre et son refusé.
- Sans magasin enregistré, l'envoi mène aux réglages.
- Le brouillon est enregistré avant l'ouverture et retrouvé après rechargement.
- Une erreur `409 SHOPPING_LIST_SENT` ou `412` est gérée sans état incohérent.

## Tests exigés

Vitest (écran, surcharges, brouillon, erreurs) ; e2e Pixel 7 avec un agent simulé (page de test à l'origine du magasin qui lit le fragment) ; accessibilité ; test visuel.

## Definition of Done

Celle du front.

## Pièges connus

- L'envoi verrouille la liste **avant** l'ouverture de Leclerc : si l'utilisateur abandonne, la liste reste `SENT` ; prévoir clairement « Rouvrir la liste ».
- Le plan peut changer si l'utilisateur modifie ses choix entre deux ouvertures : recharger le plan à chaque ouverture de l'écran.

## Précision (2026-10-18, DRV-306)

`buildPlanMessage` doit recopier `needed` de chaque ligne du plan du back dans le message `plan` (schéma `drive-agent/protocol/plan.schema.json`).

## Contrat du plan du back (2026-10-18, DRV-206, PR back #141)

`GET /api/v1/drive/plans/{shoppingListId}` renvoie `{shoppingListId, listVersion, retailer:"leclerc", store:{id, label|null, baseUrl}, generatedAt, counts:{known,new,haveAtHome}, lines:[…]}` ; lignes triées par `ingredientSlug` : `{ingredientSlug, ingredientName, needed:{quantity, unitSymbol, dimension:"WEIGHT|VOLUME|COUNT|PACKAGE"|null, baseFactor|null}, status:"KNOWN|NEW|HAVE_AT_HOME", products:[{rank, retailerProductId, ean|null, label, content:{value, unit}|null, packs:1..99|null, packsStatus:"COMPUTED|TO_CHECK"}], rejected:[{retailerProductId, label}], searchQuery}`. `products` : vide hors `KNOWN`, 5 au plus ; `rejected` : 20 au plus, peut être non vide sur une ligne `NEW`. `searchQuery` = nom de l'ingrédient tel quel (casse conservée). Erreurs : 404 `DRIVE_DISABLED`, 403 `SHOPPING_LIST_FORBIDDEN`, 404 liste inconnue, 409 `DRIVE_NOT_CONFIGURED`, 409 `SHOPPING_LIST_NOT_SENT`. Traduction vers le message `plan` de l'agent : `retailerProductId` → `id` (produits et refusés), `ingredientName` → `name`, `needed` recopié tel quel, `store.baseUrl` → `store:{id, host}` (hôte déduit de `baseUrl`), `SKIP` et `replaces` ajoutés par le front. `PUT /api/v1/drive/choices/{ingredientSlug}` (DRV-208) : `{retailer, storeId, haveAtHome, accepted:[productId], rejected:[productId]}`, tous requis, 204 ; `productId` = identifiant Repas lu dans `GET /drive/choices`.
