# LST-004 — Plusieurs listes en cours (back)

- **Dépôt** : back — **Taille** : M — **Effort** : high — **Dépend de** : LST-002
- **Lire d'abord** : ADR 0036 (décisions 6 à 8, 12) ; ADR 0023 ; `CLAUDE.md` (« Liste envoyée au drive »).

## À faire

1. `ShoppingListRepository` : `findOneActiveByOwnerAndType(ownerId, ?typeId)`, `hasActiveShoppingList` par type ; `CreateShoppingList` accepte un type facultatif (absent = principale, comportement actuel), met en pause la liste en cours **du même type** seulement ; `ActivatedShoppingList` et `ReopenShoppingList` idem. Le plafond `MAX_PER_OWNER` et le nettoyage des listes terminées gardent leur sens.
2. **Ouverture à la demande** : un service `ActiveListForType` (Application) rend la liste en cours d'un type ou en ouvre une, nommée comme le type, sans toucher aux autres ni à une liste `SENT`. Pour la principale, comportement d'aujourd'hui.
3. **Lecture** : `GET /api/v1/shopping-lists/open` (`{member: [{id, name, typeId, rowCount, uncheckedCount, version}], totalItems}`, principale d'abord puis types par date de création) ; `GET /api/v1/shopping-lists` (« Mes listes ») gagne `typeId` (nullable, clé toujours écrite) ; `/active/summary` et `PUT /active` inchangés.
4. Contrat OpenAPI, budgets SQL (`creer-liste-de-type`, `ouvrir-liste-a-la-demande`).

## Critères d'acceptation

- Sans type, `CreateShoppingList`, activer, rouvrir se comportent exactement comme avant (tests existants inchangés).
- Avec Boucherie et Marché : créer une liste Boucherie ne met pas la principale en pause ; activer une liste Boucherie en pause met en pause la Boucherie en cours seulement.
- Un ajout vers un type dont la liste est `SENT` ouvre une liste neuve.
