# LST-002 — Types de liste (back)

- **Dépôt** : back — **Taille** : L — **Effort** : **xhigh** (migration, droits, purge) — **Dépend de** : LST-001
- **Lire d'abord** : ADR 0036 (décisions 1, 4, 6, 13 à 16, 18) ; `CLAUDE.md` ; skill `repas-dev`.

## À faire

1. **Une migration** additive, rejouable (`IF NOT EXISTS`), qui n'écrit aucune donnée : tables `shopping_list_type` (id, owner_id, name, slugged_name, created_at) et `department_list_type` (owner_id, department_slug, list_type_id ; clé = owner_id + department_slug) ; colonnes facultatives `shopping_list.list_type_id`, `ingredient.list_destination` (`VARCHAR(36)`) et `meal.planned_group_id`. Les modèles `ShoppingList`, `Ingredient` et `Meal` les portent (`load()`, entités Doctrine, builders, InMemory) mais **rien d'autre ne les lit encore**.
2. **Agrégat `ShoppingListType`** (`MAX_PER_OWNER` = 10, nom de 1 à 40 caractères, `capitalizeFirst`), `ShoppingListTypeRepository` + InMemory, `ShoppingListTypeException` (`LIST_TYPE_LIMIT_REACHED` 422, `LIST_TYPE_NAME_ALREADY_USED` 409, introuvable 404).
3. **Cas d'usage** : créer, modifier (`name` et `departmentSlugs`, le jeu complet : un rayon déjà pris par un autre type est déplacé ; un rayon inconnu est `404`), supprimer (ADR 0036 décision 15 : rattachements effacés, `ingredient.list_destination` remis à vide, listes du type détachées, la liste `ACTIVE` passe `PAUSED` version +1, en une transaction).
4. **Lectures** : `GET /api/v1/shopping-list-types` (`{member: [{id, name, departmentSlugs, ingredientCount}], totalItems}`, triés par date de création) et `GET /api/v1/shopping-list-types/{id}` (rayons avec leur nom, produits à part avec nom et rayon) ; loaders SQL en un nombre constant de requêtes.
5. **Droits** : attribut `LIST_TYPE_OWNER` dans `OwnerVoter` (port `OwnershipLoader`), aucun passe-droit `ROLE_ADMIN` ; `ContextVotersDecisionTest` à jour.
6. **Compte** : `PurgeUserData` efface types et rattachements ; `UserOwnedDataCoverageTest` vert. Un rayon supprimé efface ses rattachements.
7. Contrat : OpenAPI sur chaque controller, `make openapi`. Budgets SQL `creer-type-de-liste`, `modifier-type-de-liste`, `supprimer-type-de-liste`.

## Critères d'acceptation

- Un compte sans type : aucune réponse existante ne change.
- Créer au-delà de 10 types, ou deux types du même nom normalisé : refusé sans écriture.
- Le type d'un autre : `404` en lecture, `403` en écriture, administrateur compris.
- Supprimer un type : ses listes n'ont plus de type, la liste en cours est en pause, aucune ligne perdue (test sur vraie base).
- Migration testée (compatible avec le code précédent : colonnes facultatives).
