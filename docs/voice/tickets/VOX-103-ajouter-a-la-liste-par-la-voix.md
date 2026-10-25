# VOX-103 — Port `Shared\VoiceShoppingList` : ajouter à la liste en cours, la créer si besoin

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : fait (PR #260, 2026-10-25)
- **Lire d'abord** : [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décision 5 ; [ADR 0023](../../adr/0023-liste-envoyee-au-drive.md) ; `EnsureActiveShoppingListHandler`, `AddIngredientToShoppingListHandler`, `CreateShoppingListHandler` (met l'ancienne liste en pause) ; `tests/Repas/WriteBudget`.

## Contexte

La voix ajoute une ligne à la liste `ACTIVE` ; sans liste `ACTIVE` (aucune, ou la dernière est envoyée au drive), on en crée une. Aujourd'hui `AddIngredientToShoppingListHandler` lève `activeShoppingListNotFound` sans liste active.

## Portée

1. `Shared\Application\Interface\VoiceShoppingList::addItem(string $userId, string $ingredientSlug): AddedItem` et DTO `AddedItem` (`listCreated: bool`, `previousListSent: bool`).
2. Cas d'usage `Repas\Application\Command\AddSpokenIngredient\AddSpokenIngredientCommand` + `Handler` : `findOneActivateByOwner` ; absent → `CreateShoppingListHandler` (une seule fois, sans réentrer dans `EnsureActive…`), puis ajout comme `AddIngredientToShoppingListHandler` (alias résolus par `IngredientSlugResolver` ; ingrédient d'un autre → `IngredientException` `404`). Le résultat dit si la liste a été créée et si une liste `SENT` précédait (`isSent` sur la dernière liste de l'utilisateur).
3. Adaptateur `Repas\Infrastructure\Voice\VoiceShoppingListAdapter` : dispatch par `CommandBusInterface`. Le résultat revient par un objet de résultat, pas par la commande (modèle : `RotateRefreshToken`, ADR 0015 §12).
4. Scénario `WriteBudget` `ajouter-ingredient-par-la-voix` (liste existante) et `…-sans-liste` ; `make sql-budget-update` si besoin.

## Critères d'acceptation

- Liste `ACTIVE` présente : une ligne ajoutée, `listCreated = false`, version +1.
- Aucune liste : une liste `ACTIVE` créée, la ligne dedans, `listCreated = true`.
- Seule une liste `SENT` : nouvelle liste `ACTIVE`, la `SENT` intacte, `previousListSent = true`.
- Même ingrédient dit deux fois : la ligne existante augmente (comportement de `addRow`), pas de doublon de ligne.
- Ingrédient inexistant ou à un autre : `404`, rien n'est écrit.

## Tests exigés

Handler en unitaire (`ShoppingListInMemoryRepository`, `SpyEventDispatcher`) ; budget SQL ; adaptateur avec `MockCommandBus`.

## Pièges connus

- `CreateShoppingListHandler` met la liste active en pause : ne l'appeler que s'il n'y en a pas.
- Un handler n'appelle jamais un Loader : la question « une liste `SENT` précédait-elle ? » passe par un port de repository (`ShoppingListRepository::hasSentShoppingList` à ajouter, avec son InMemory).
