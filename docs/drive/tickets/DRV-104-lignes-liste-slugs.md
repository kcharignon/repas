# DRV-104 — Lignes de liste : `ingredientSlug` et `unitSlug`

- **Dépôt** : back
- **Jalon** : M1 Back `Repas`
- **Taille** : S
- **Dépend de** : —
- **Statut** : fait (PR [#119](https://github.com/kcharignon/repas/pull/119), 2026-10-17 ; `ingredientSlug` et `unitSlug` ajoutés à `ShoppingListRowDTO`, aucune clé existante modifiée)
- **Lire d'abord** : ADR 0004, 0005 ; skill `repas-dev` § 3.1 ; `src/Repas/Application/Query/GetShoppingList/ShoppingListRowDTO.php` ; `ShoppingListPostgreSQLLoader::loadDepartments`.

## Contexte

`ShoppingListRowDTO` n'expose que `id`, `ingredientName`, `quantity`, `unitSymbol`, `checked`. Le front et le plan d'envoi ont besoin de l'identifiant stable de l'ingrédient et de l'unité pour associer une ligne à un choix de produit. Changement additif du contrat.

## Portée

1. Ajouter à `ShoppingListRowDTO` : `string $ingredientSlug` et `string $unitSlug` (avec `#[OA\Property]` et exemples), à la fin de la liste d'arguments.
2. `ShoppingListPostgreSQLLoader::loadDepartments` renseigne `ingredientSlug` (déjà sélectionné : `i.slug`) et `unitSlug` (sélectionner `u.slug AS unitSlug`).
3. Mettre à jour les builders de DTO (`tests/Helper/Builder/DTO/`), les tests du loader et des contrôleurs qui vérifient les clés JSON.
4. `make openapi` et relecture du diff de `docs/api/openapi.json`.

## Hors portée

Statut `SENT` (DRV-102). Le front régénérera ses types (DRV-401).

## Critères d'acceptation

- `GET /api/v1/shopping-lists/{id}` renvoie, pour chaque ligne, `ingredientSlug` et `unitSlug`.
- Les clés JSON existantes ne changent pas.
- Le loader fait le même nombre de requêtes qu'avant.

## Tests exigés

Test du loader sur base réelle (valeurs attendues) ; test de contrôleur (clés JSON) ; `make api-doc-validate api-doc-coverage openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4. Contrat commité.

## Pièges connus

- Le front génère ses types depuis `docs/api/openapi.json` à un commit précis : un ajout de champ est compatible, mais ne retirer ni renommer aucune clé.
