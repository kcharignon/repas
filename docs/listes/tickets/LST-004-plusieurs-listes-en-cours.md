# LST-004 — Plusieurs listes en cours (back)

- **Dépôt** : back — **Taille** : M — **Effort** : high — **Dépend de** : LST-002
- **Statut** : fait (PR de la branche `feat/lst-004-plusieurs-listes-en-cours`)
- **Lire d'abord** : ADR 0036 (décisions 6 à 8, 12) ; ADR 0023 ; `CLAUDE.md` (« Liste envoyée au drive »).

## À faire

1. `ShoppingListRepository` : `findOneActiveByOwnerAndType(ownerId, ?typeId)`, `hasActiveShoppingList` par type ; `CreateShoppingList` accepte un type facultatif (absent = principale, comportement actuel), met en pause la liste en cours **du même type** seulement ; `ActivatedShoppingList` et `ReopenShoppingList` idem. Le plafond `MAX_PER_OWNER` et le nettoyage des listes terminées gardent leur sens.
2. **Ouverture à la demande** : un service `ActiveListForType` (Application) rend la liste en cours d'un type ou en ouvre une, nommée comme le type, sans toucher aux autres ni à une liste `SENT`. Pour la principale, comportement d'aujourd'hui.
3. **Lecture** : `GET /api/v1/shopping-lists/open` (`{member: [{id, name, typeId, rowCount, uncheckedCount, version}], totalItems}`, principale d'abord puis types par date de création) ; `GET /api/v1/shopping-lists` (« Mes listes ») gagne `typeId` (nullable, clé toujours écrite) ; `/active/summary` et `PUT /active` inchangés.
4. Contrat OpenAPI, budgets SQL (`creer-liste-de-type`, `ouvrir-liste-a-la-demande`).

## Critères d'acceptation

- [x] Sans type, `CreateShoppingList`, activer, rouvrir se comportent exactement comme avant (tests existants inchangés).
- [x] Avec Boucherie et Marché : créer une liste Boucherie ne met pas la principale en pause ; activer une liste Boucherie en pause met en pause la Boucherie en cours seulement.
- [x] Un ajout vers un type dont la liste est `SENT` ouvre une liste neuve.

## Réalisé

`ShoppingListRepository::findOneActiveByOwnerAndType(ownerId, ?typeId)` (la plus ancienne si deux listes d'un même type sont en cours, ADR 0036 « Conséquences ») et `hasActiveShoppingList(ownerId, ?typeId = null)` ; **`findOneActivateByOwner` et `hasActiveShoppingList` sans type visent désormais la liste principale seulement** (`list_type_id` vide) : un compte sans type voit exactement l'ancien comportement, et les ajouts d'aujourd'hui (produit, recette, voix) ne tombent jamais sur la liste d'un type avant LST-005. `CreateShoppingList` accepte `listTypeId` (le type est jugé avant tout : `404 LIST_TYPE_NOT_FOUND` s'il est inconnu ou à un autre, rien d'écrit ; la liste porte le nom du type ; seule la liste en cours du même type passe en pause ; le handler **rend** la liste créée) ; `ActivatedShoppingList` et `ReopenShoppingList` ne mettent en pause que la liste en cours du même type que la liste activée ou rouverte. Le plafond de listes et le nettoyage des listes terminées (écouteur de `NewShoppingListCreatedEvent`) sont inchangés, par propriétaire. Service `Repas\Application\Service\ActiveListForType::forType(ownerId, ?typeId): OpenedList` (liste, `opened`) pour LST-005 : la liste en cours du type, sinon une liste neuve nommée comme lui (par `CreateShoppingListHandler`, qui ne met en pause aucune autre liste puisque le type n'en a pas en cours ; une liste `SENT` n'est jamais touchée) ; pour la principale sans liste en cours il lève `activeShoppingListNotFound` (pas d'ouverture silencieuse, c'est l'appelant qui décide, comme la commande vocale). Lectures : `GET /api/v1/shopping-lists/open` (`ListOpenShoppingLists`, `priority: 10` avant `/shopping-lists/{id}` ; une seule requête SQL quel que soit le nombre de types ; `name` est le nom de la liste ou à défaut sa date en français, comme « Mes listes ») ; `GET /api/v1/shopping-lists` gagne `typeId` (clé toujours écrite) ; `GET /shopping-lists/active/summary` ne lit que la principale et la lecture « liste en cours » (`ShoppingListLoader::getCurrent`) préfère la principale en cours. `POST /api/v1/shopping-lists` lit un corps facultatif `{typeId}` (texte de 1 à 36 caractères ou `null`, `422` sinon). Budgets `creer-liste-de-type` (15) et `ouvrir-liste-a-la-demande` (8, scénario qui exécute le service dans une transaction : `WriteBudgetTestCase::measure` accepte une fonction), aucun budget existant ne bouge. Tests : `ActiveListForTypeTest`, handlers, `ShoppingListRepositoryTest`, `ShoppingListPostgreSQLLoaderTest`, `OpenShoppingListsApiFlowTest` (vraie base).
