# DRV-208 — Remplacer la préférence d'un ingrédient (ordre, refus)

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : M
- **Dépend de** : DRV-204
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5.3, 7 ; DRV-204.

## Contexte

L'écran « Mes produits » (DRV-410) doit permettre de réordonner les produits acceptés d'un ingrédient, d'en retirer, de refuser ou de ré-accepter un produit, et de passer en « j'en ai toujours » ou d'en sortir. Le plus simple et le plus sûr est de **remplacer d'un coup** la préférence d'un ingrédient.

## Portée

1. Écriture `Application/Command/ReplaceIngredientPreference/` : `ReplaceIngredientPreferenceCommand(userId, retailer, storeId, ingredientSlug, bool haveAtHome, list<string> acceptedProductIds, list<string> rejectedProductIds)` ; handler : vérifie le magasin courant (`DRIVE_STORE_MISMATCH` / `DRIVE_NOT_CONFIGURED`), charge les produits par leurs identifiants Repas (`ProductRepository::findByIds`, une requête ; identifiant inconnu : `DRIVE_CHOICE_INVALID`, 422), construit la préférence dans l'ordre donné (domaine de `Choice` : limites 5/20, pas de doublon, pas de produit dans les deux listes) et l'enregistre ; préférence absente : créée ; listes vides et `haveAtHome` faux : la préférence est supprimée.
2. API `PUT /api/v1/drive/choices/{ingredientSlug}` : corps `{retailer, storeId, haveAtHome, accepted:[productId…], rejected:[productId…]}` (`#[MapRequestPayload]`), `204` ; `404 DRIVE_DISABLED` quand coupé ; identité par le JWT ; erreurs `409` et `422` de DRV-204 ; `#[OA\…]` ; `make openapi`.
3. Scénario `WriteBudget` pour `ReplaceIngredientPreference` (nombre de requêtes constant).

## Hors portée

L'écran (DRV-410).

## Critères d'acceptation

- Remplacer `[A, B]` par `[B, A]` change les rangs ; remplacer par `[B]` retire A ; ajouter C aux refusés le retire des acceptés s'il y était (rejeté : un produit ne peut pas figurer dans les deux listes de la demande).
- `haveAtHome: true` avec des produits dans la demande : `422`.
- Un identifiant de produit inconnu, un doublon, 6 acceptés, 21 refusés : `422 DRIVE_CHOICE_INVALID`, rien d'enregistré.
- Un utilisateur ne modifie que ses propres préférences (l'identité vient du jeton).

## Tests exigés

Handler en unitaire (doubles en mémoire) ; dépôt sur base réelle ; contrôleur ; budget.

## Definition of Done

Celle du skill `repas-dev` § 4.
