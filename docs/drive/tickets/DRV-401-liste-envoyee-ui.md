# DRV-401 — Liste envoyée dans l'interface

- **Dépôt** : front (`kcharignon/repas-front`)
- **Jalon** : M4 Front
- **Taille** : M
- **Dépend de** : DRV-102, DRV-104 (contrat du back fusionné)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 4, 9 ; `CLAUDE.md` du front ; `src/features/shopping-lists/` (`ShoppingListHeader.vue`, `ShoppingListContent.vue`, `ShoppingListsView.vue`, `ShoppingListView.vue`, `queries.ts`).

## Contexte

Le back sait maintenant qu'une liste peut être `SENT` (non modifiable). L'interface doit l'afficher sans permettre de modification, et proposer les trois actions autorisées. Aujourd'hui `ShoppingListHeader.vue` déduit le libellé par `isActive ? 'active' : isPaused ? 'paused' : 'completed'` et montre « Activer » pour toute liste non active : une liste envoyée serait mal étiquetée et activable.

## Portée

1. Contrat : `npm run api:update -- <commit du back>` (ou `make api-update ref=`), types régénérés ; relire le diff.
2. Libellés : statut « Envoyée au drive » (clé i18n `shoppingList.status.sent`), date d'envoi (`sentAt`) en français dans le bandeau.
3. `ShoppingListHeader.vue` : pour une liste `isSent` — pas de « Renommer », ni « Mettre en pause », ni « Activer » ; actions **« Reprendre l'envoi »** (route `/drive/envoi/:id`, DRV-404), **« Terminer »** (`complete`), **« Rouvrir la liste »** (avec confirmation : « Le panier Leclerc ne sera pas vidé. ») et « Supprimer » (confirmation existante). Mutations `send`/`reopen` ajoutées dans `queries.ts` avec `If-Match` (`ifMatch(list.version)`) et invalidation des requêtes de listes.
4. `ShoppingListContent.vue` : lecture seule pour `isSent` — pas de case à cocher (ou case désactivée), pas de `+`/`-`, pas de changement de portions, pas d'ajout/retrait de recette ; un bandeau explique « Cette liste est envoyée au drive : elle n'est plus modifiable ».
5. `ShoppingListsView.vue` (« Mes listes ») : badge « Envoyée » et date.
6. `ShoppingListView.vue` (`/liste`) : s'il n'y a pas de liste `ACTIVE`, le comportement actuel (bouton « Nouvelle liste ») est conservé ; **ne pas créer de liste automatiquement** quand il n'y a que des listes `SENT`.
7. Gestion des erreurs : `409` avec le message `SHOPPING_LIST_SENT` (liste envoyée depuis un autre appareil) → recharger la liste et informer (« Cette liste vient d'être envoyée au drive »), dans la logique d'échec existante (`notifications/failures.ts`).
8. Hors ligne : la lecture hors ligne de la liste en cours reste valable ; les actions `send`/`reopen` sont désactivées sans réseau.

## Hors portée

Le flux d'envoi lui-même (DRV-404), le récapitulatif (DRV-405).

## Critères d'acceptation

- Une liste `SENT` s'affiche avec le statut « Envoyée au drive » et sa date, en lecture seule, avec « Reprendre l'envoi », « Terminer », « Rouvrir la liste », « Supprimer ».
- « Rouvrir la liste » demande confirmation, rend la liste active et modifiable.
- Une erreur `409 SHOPPING_LIST_SENT` ne laisse pas l'interface dans un état incohérent.
- Aucun libellé `completed` n'apparaît pour une liste `SENT`.

## Tests exigés

Vitest (`ShoppingListView.spec.ts`, `ShoppingLists.spec.ts`, nouveaux tests du header et du contenu en lecture seule) ; e2e Pixel 7 : une liste envoyée est non modifiable puis rouverte (agent non nécessaire : `send` appelé directement) ; test visuel mobile et bureau.

## Definition of Done

`make check` vert, couverture non abaissée, textes dans `src/i18n/fr.ts`, test visuel Chromium.

## Pièges connus

- `checked` ne doit jamais être modifié par un envoi.
- Les clés i18n manquantes font échouer les tests (`console.warn` interdit).
