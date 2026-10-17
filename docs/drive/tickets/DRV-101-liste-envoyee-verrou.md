# DRV-101 — Liste envoyée : statut `SENT`, verrou et transitions

- **Dépôt** : back (`kcharignon/repas`)
- **Jalon** : M1 Back `Repas`
- **Taille** : L
- **Dépend de** : DRV-005 (décision G1 : Go)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 4, [ADR 0023](../../adr/0023-liste-envoyee-au-drive.md), ADR 0002, 0013, 0016 ; skill `.claude/skills/repas-dev/SKILL.md` ; `CLAUDE.md` du back.

## Contexte

Une liste envoyée au drive n'est plus modifiable. L'agrégat `ShoppingList` (`src/Repas/Domain/Model/ShoppingList.php`) n'a aujourd'hui aucun invariant sur le statut, et plusieurs handlers agissent par identifiant sans le regarder (liste au § 4.1 de `architecture.md`). Ce ticket pose le statut, les transitions, le verrou et leurs tests ; l'API est dans DRV-102.

## Portée

1. `ShoppingListStatus::SENT = 'SENT'`.
2. Colonne `sent_at` (timestamp, nullable) : entité `Repas\Repas\Infrastructure\Entity\ShoppingList` (`sentAt`, `fromModel`, `updateFromModel`), conversion entité → modèle dans `ShoppingListPostgreSQLRepository`, migration Doctrine (compatible avec la version précédente du code : colonne nullable).
3. Modèle `ShoppingList` : `sentAt` (`?DateTimeImmutable`, absent de `create()`), `getSentAt()`, `isSent()`, `send(DateTimeImmutable $now)`, `reopen()`, `assertEditable()`. `load()` lit `sent_at`.
   - `send` : lève `ShoppingListException::notSendable($id)` (409, message `SHOPPING_LIST_NOT_SENDABLE`) si le statut n'est pas `ACTIVE` et pas `SENT` (déjà `SENT` : sans effet, idempotent) ; lève `ShoppingListException::empty($id)` (422, `SHOPPING_LIST_EMPTY`) si `countRows() === 0`.
   - `reopen` : lève `ShoppingListException::notSent($id)` (409, `SHOPPING_LIST_NOT_SENT`) si le statut n'est pas `SENT` ; passe à `ACTIVE`, efface `sentAt`.
   - `assertEditable` : lève `ShoppingListException::sent($id)` (409, `SHOPPING_LIST_SENT`) si `SENT`.
   - Appeler `assertEditable()` dans `addMeal`, `addRow`, `subtractRow`, `addIngredient`, `removeIngredient`, `removeMeal`, `setName`, `pause()` et `activated()`. `completed()` reste permis depuis `SENT`.
   - Les messages d'exception sont des **constantes de classe** en majuscules (comme `UserException::PROTECTED_ACCOUNT`) pour que le front les distingue.
4. Port `ShoppingListRepository` : `isSent(string $shoppingListId): bool` (une requête, sans hydrater l'agrégat) ; implémentation PostgreSQL et `ShoppingListInMemoryRepository`.
5. Commandes et handlers (`src/Repas/Application/Command/SendShoppingList/` et `ReopenShoppingList/`) :
   - `SendShoppingListCommand(string $shoppingListId, ?int $expectedVersion = null)`, handler : charge la liste, `assertVersion()`, `send($clock->now())`, `save`. Horloge injectée (`Psr\Clock\ClockInterface`, comme `CreateShoppingListHandler`).
   - `ReopenShoppingListCommand(string $shoppingListId, ?int $expectedVersion = null)`, handler : charge la liste, `assertVersion()`, comme `ActivatedShoppingListHandler` met en pause la liste `ACTIVE` du propriétaire si elle existe (et si ce n'est pas la même), `reopen()`, `save`.
6. Gardes dans les handlers par identifiant :
   - `UpdateServingMealHandler` : `$shoppingList->assertEditable()` juste après le chargement (le handler modifie `Meal` sans passer par l'agrégat).
   - `UpdateShoppingListHandler`, `ActivatedShoppingListHandler`, `PausedShoppingListHandler` : couverts par les gardes de l'agrégat (`setName`, `activated`, `pause`) ; vérifier par test.
   - `TickLineOnShoppingListHandler` et `UncheckLineOnShoppingListHandler` : avant toute modification, `if ($this->shoppingListRepository->isSent($row->getShoppingListId())) throw ShoppingListException::sent(...)`. Ajouter `ShoppingListRepository` aux dépendances de `TickLine...`. `UncheckLine...` ne réactive jamais une liste `SENT`.
7. `PlannedMeal`, `RemoveMealFromPlan`, `AddIngredient...`, `RemoveIngredient...` n'agissent que sur la liste `ACTIVE` : rien à changer, mais un test vérifie qu'ils ne touchent pas une liste `SENT`.
8. `CreateShoppingListHandler` ne touche pas une liste `SENT` (test). `RemoveShoppingListCompletedEventListener` continue de ne supprimer que les `COMPLETED` (test).
9. Doubles et builders de test : `ShoppingListBuilder` accepte `sentAt` et le statut `SENT` ; `ShoppingListInMemoryRepository::isSent`.
10. Documentation : ADR 0023 passe à `Accepted` (même PR) ; `CLAUDE.md` du back (partie « Droits d'accès »/listes) mentionne le statut `SENT` et le verrou ; `docs/drive/roadmap.md` coché.

## Hors portée

Endpoints (DRV-102), interrupteur (DRV-103), DTO de lecture (DRV-102).

## Critères d'acceptation

- Étant donné une liste `ACTIVE` avec au moins une ligne, quand on l'envoie, alors son statut est `SENT`, `sentAt` vaut l'heure de l'horloge, sa version a augmenté.
- Étant donné une liste vide, quand on l'envoie, alors `SHOPPING_LIST_EMPTY` (422) et rien n'est enregistré.
- Étant donné une liste `PAUSED` ou `COMPLETED`, quand on l'envoie, alors `SHOPPING_LIST_NOT_SENDABLE` (409).
- Étant donné une liste déjà `SENT`, quand on l'envoie de nouveau, alors aucun effet et aucune erreur.
- Étant donné une liste `SENT`, quand on ajoute ou retire une recette, change les portions, fait `+`/`-` d'un ingrédient, renomme, coche, décoche, active ou met en pause, alors `SHOPPING_LIST_SENT` (409) et aucune modification.
- Étant donné une liste `SENT`, quand on la termine, alors elle devient `COMPLETED` et `ShoppingListCompletedEvent` est émis comme avant.
- Étant donné une liste `SENT` et une autre liste `ACTIVE`, quand on rouvre la première, alors la seconde est mise en pause, la première est `ACTIVE` et `sentAt` est effacé.
- Étant donné une liste non `SENT`, quand on la rouvre, alors `SHOPPING_LIST_NOT_SENT` (409).
- Étant donné `If-Match` périmé, quand on envoie ou rouvre, alors `412`.
- Créer une nouvelle liste ne modifie pas une liste `SENT`.

## Tests exigés

Domaine (`tests/Repas/Domain/…` selon la structure existante) : chaque mutateur refuse `SENT` ; transitions valides et invalides. Handlers en unitaire avec `ShoppingListInMemoryRepository` et `SpyEventDispatcher` (jamais de base) : `SendShoppingListHandler`, `ReopenShoppingListHandler`, gardes de `UpdateServingMealHandler`, `TickLine…`, `UncheckLine…`. Dépôt : `isSent` et `sent_at` dans `tests/Repas/OutputAdapter/` (base réelle). Scénarios `WriteBudget` (`tests/Repas/WriteBudget/scenarios.php` et `budgets.php`) pour `SendShoppingList` et `ReopenShoppingList` ; `make sql-budget-update` si un budget baisse.

## Definition of Done

Celle du skill `repas-dev` § 4 : `make test`, `make quality`, `make openapi` (aucun changement attendu ici), budgets SQL, `UserOwnedDataCoverageTest` inchangé (aucune nouvelle colonne utilisateur), ADR 0023 `Accepted`, `CLAUDE.md` mis à jour, roadmap cochée.

## Pièges connus

- `ShoppingListStatus::from()` d'un ancien code ne connaît pas `SENT` : ne rien activer en production avant DRV-103/502.
- `Meal::setServing` est appelé depuis le handler, pas depuis l'agrégat : la garde doit être explicite dans le handler.
- `UncheckLine...` réactive aujourd'hui une liste `COMPLETED` ; ne pas changer ce comportement pour `COMPLETED`.
- Les écritures chargent un modèle copie : ne pas enregistrer un modèle chargé avant un événement qui peut modifier la même liste (ADR 0013 règle 7).
