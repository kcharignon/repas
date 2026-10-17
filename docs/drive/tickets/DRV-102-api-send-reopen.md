# DRV-102 — API `send` et `reopen`, DTO de lecture

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : M
- **Dépend de** : DRV-101, DRV-103
- **Statut** : fait (PR [#129](https://github.com/kcharignon/repas/pull/129), 2026-10-18 ; routes `send` et `reopen`, `isSent` et `sentAt` en lecture)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 4, 7 ; ADR 0007 (§ 11 à 13, 15), 0016 ; skill `repas-dev` § 3.2.

## Contexte

DRV-101 pose le statut et les commandes. Ce ticket les expose et rend le statut lisible par le front.

## Portée

1. `Infrastructure/Http/Api/SendShoppingList/SendShoppingListApiController.php` : `POST /api/v1/shopping-lists/{id}/send`, `#[IsGranted('ROLE_USER')]` + `#[IsGranted('SHOPPING_LIST_OWNER', 'id')]`, `If-Match` lu avec `EntityVersion::expected($request)`, dispatch de `SendShoppingListCommand`, réponse `204`. Documentation `#[OA\…]` (réponses 204, 403, 404 `DRIVE_DISABLED` ou liste inconnue, 409, 412, 422 ; paramètre `ref: '#/components/parameters/IfMatch'`).
2. `Infrastructure/Http/Api/ReopenShoppingList/ReopenShoppingListApiController.php` : `POST /api/v1/shopping-lists/{id}/reopen`, même sécurité, `204`, 409 `SHOPPING_LIST_NOT_SENT`, 412.
3. Les deux contrôleurs répondent `404` avec le message `DRIVE_DISABLED` quand `DriveFeature::isEnabled()` est faux (DRV-103). Jamais d'accès à un `Repository` dans un contrôleur.
4. Lecture : `ShoppingListDTO` (`ListShoppingLists`) et `ShoppingListDetailDTO` (`GetShoppingList`) gagnent `bool $isSent` et `?string $sentAt` (ATOM, nullable) ; l'énumération `status` des annotations OpenAPI passe à `['ACTIVE', 'PAUSED', 'SENT', 'COMPLETED']` ; `ShoppingListPostgreSQLLoader` (`listByOwner`, `buildDetail`) les renseigne (sélectionner `l.sentAt`). `getCurrent` continue de préférer la liste `ACTIVE`, sinon la plus récente (donc une `SENT` peut être « courante »).
5. Les erreurs `SHOPPING_LIST_SENT` (409) remontent telles quelles par `ApiExceptionListener` pour les écritures existantes ; compléter les annotations OpenAPI de ces contrôleurs avec la réponse 409 (`SHOPPING_LIST_SENT`).
6. `make openapi` et relecture du diff de `docs/api/openapi.json`.

## Hors portée

Champs `ingredientSlug` et `unitSlug` des lignes (DRV-104). Tout le contexte `Drive`.

## Critères d'acceptation

- `POST /send` sur sa propre liste `ACTIVE` non vide : `204` ; la lecture de la liste renvoie `status: "SENT"`, `isSent: true`, `sentAt` non nul, un `ETag` plus grand.
- `POST /send` sur la liste d'un autre utilisateur : `403` ; liste inconnue : `404` ; liste vide : `422` `SHOPPING_LIST_EMPTY` ; `If-Match` périmé : `412` ; liste en pause : `409` `SHOPPING_LIST_NOT_SENDABLE`.
- Toute écriture de contenu sur la liste envoyée : `409` `SHOPPING_LIST_SENT`.
- `POST /reopen` : `204` et la liste est `ACTIVE`, `sentAt` nul ; sur une liste non envoyée : `409`.
- Quand `DRIVE_ENABLED` est faux : `send` et `reopen` répondent `404` `DRIVE_DISABLED`.
- Les clés JSON de `isSent` et `sentAt` apparaissent dans les deux DTO ; la doc OpenAPI les décrit.

## Tests exigés

Contrôleurs avec `QueryBusWebTestCase::mockCommandBus()` (commande construite, statut, 401, 403 propriétaire, 404, 412, 422) ; extension de `ShoppingListWriteApiFlowTest` (vrais bus) : jeton → envoyer → tenter de modifier (409) → rouvrir → modifier (204) ; loader : `isSent` et `sentAt` sur base réelle ; `make api-doc-validate api-doc-coverage openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4. `docs/api/openapi.json` commité. Roadmap cochée.

## Pièges connus

- Le client de test redémarre le noyau entre deux requêtes : le double de bus ne vaut que pour une requête par test.
- Ne pas oublier `sentAt` dans `ListShoppingListsQuery` : l'écran « Mes listes » l'affichera.

## Réalisation (2026-10-18, PR repas#129)

`SendShoppingListApiController` et `ReopenShoppingListApiController` (`#[RequiresDrive]`, `ROLE_USER` + `SHOPPING_LIST_OWNER`, `EntityVersion::expected($request)`, `204`) ; `ShoppingListDTO` et `ShoppingListDetailDTO` gagnent `isSent` et `sentAt` (juste après `isCompleted`) ; `ShoppingListPostgreSQLLoader` sélectionne `l.sentAt` ; le `409 SHOPPING_LIST_SENT` est documenté sur `PATCH /shopping-lists/{id}`, `PUT /meals/{id}/serving`, `POST …/tick`, `…/uncheck`, `…/activate`, `…/pause` ; `docs/api/openapi.json` régénéré. Contrat pour le front (DRV-401) :

- Lecture : `status` ∈ `ACTIVE | PAUSED | SENT | COMPLETED`, `isSent: bool`, `sentAt: string | null` (ATOM). `GET /shopping-lists/current` peut renvoyer une liste `SENT` (aucune liste active) ; `ETag` plus grand après l'envoi et après la réouverture.
- `POST …/send` : `204` (aussi si déjà envoyée, sans effet, version inchangée) ; `404` `DRIVE_DISABLED` (interrupteur coupé) ou liste inconnue ; `403` autre utilisateur ; `409` `SHOPPING_LIST_NOT_SENDABLE` (liste en pause ou terminée) ; `412` `If-Match` périmé ; `422` `SHOPPING_LIST_EMPTY`. Message d'erreur = code : `{"error":{"message":"SHOPPING_LIST_EMPTY"}}`.
- `POST …/reopen` : `204` ; `409` `SHOPPING_LIST_NOT_SENT` ; `404`, `403`, `412` comme ci-dessus ; l'éventuelle autre liste active de l'utilisateur passe en `PAUSED`.
- Sur une liste `SENT` : `409` `SHOPPING_LIST_SENT` pour renommer, changer les portions, cocher, décocher, `activate`, `pause`. Terminer (`complete`), rouvrir et supprimer restent permis. Planifier une recette, retirer un repas et `+`/`-` d'un ingrédient passent par la liste **active** : si l'utilisateur n'a que la liste `SENT`, ils répondent `404` (aucune liste active), pas `409`.
- Test de bout en bout : `ShoppingListWriteApiFlowTest` (`testASentListIsLockedUntilItIsReopened` et suivants).
