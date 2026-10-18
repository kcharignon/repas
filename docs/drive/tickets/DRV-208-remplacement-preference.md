# DRV-208 — Remplacer la préférence d'un ingrédient (ordre, refus)

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : M
- **Dépend de** : DRV-204
- **Statut** : fait (PR [#143](https://github.com/kcharignon/repas/pull/143), 2026-10-18)
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

## Réalisation (2026-10-18, PR repas#143)

- **Écriture** : `ReplaceIngredientPreferenceCommand(userId, Retailer retailer, storeId, ingredientSlug, haveAtHome, list<string> acceptedProductIds, list<string> rejectedProductIds)` et son handler (`Application/Command/ReplaceIngredientPreference/`). Ordre : bornes (plus de 5 acceptés ou 20 refusés : `422` sans aucune lecture), réglages (`409 DRIVE_NOT_CONFIGURED`, `409 DRIVE_STORE_MISMATCH`), produits (`ProductRepository::findByIds`, une requête : chaque identifiant doit désigner un produit **de ce magasin**, sinon `422 DRIVE_CHOICE_INVALID`), préférence (`findOne`). Aucune lecture de plus que ces trois ; l'écriture est celle de `ChoiceRepository::save()`. Budget `remplacer-preference-drive` : **6 requêtes** (réglages, produits, préférence, upsert, effacement et réinsertion des lignes).
- **Domaine** : nouvelle méthode `Choice::replace(bool $haveAtHome, list<string> $accepted, list<string> $rejected)` (au lieu de composer `markHaveAtHome`, `addFallback` et `reject`, qui auraient laissé passer un doublon ou un produit dans les deux listes sans erreur) : 5 acceptés et 20 refusés au plus, aucun doublon dans l'ensemble des deux listes (un produit à la fois accepté et refusé est un doublon), « j'en ai toujours » sans produit, sinon `DriveException::invalidChoice()` et rien ne change ; l'ordre de `accepted` donne les rangs. Le handler met la date à jour (`touch`).
- **Effets** : préférence absente : créée ; listes vides et `haveAtHome` faux : préférence supprimée (sans erreur si elle n'existait pas) ; `haveAtHome` vrai : produits effacés. Les produits ne sont jamais supprimés.
- **API** : `PUT /api/v1/drive/choices/{ingredientSlug}` (`ReplaceIngredientPreferenceApiController`, `#[RequiresDrive]`), corps `{retailer, storeId, haveAtHome, accepted: [productId…], rejected: [productId…]}` (les cinq champs sont requis ; `productId` = identifiant Repas lu dans `GET /drive/choices`, chaîne de 1 à 36 caractères) → `204`. Erreurs : `404 DRIVE_DISABLED`, `404` si le slug n'est pas un slug, `409 DRIVE_NOT_CONFIGURED` / `DRIVE_STORE_MISMATCH`, `422 DRIVE_CHOICE_INVALID` (produit inconnu ou d'un autre magasin, doublon, produit dans les deux listes, 6 acceptés, 21 refusés, `haveAtHome` avec des produits), `422` de forme (champ absent, type, énumération). L'utilisateur vient du jeton (un `userId` dans le corps est ignoré). `docs/api/openapi.json` régénéré.
- **Tests** : `ChoiceTest` (`replace`), `ReplaceIngredientPreferenceHandlerTest` (doubles en mémoire), `ChoiceRepositoryTest` (réécriture des lignes sans conflit de rang), `ReplaceIngredientPreferenceApiControllerTest` (commande construite, corps invalides), parcours réel dans `DriveChoicesApiFlowTest` (lecture par `GET`, remplacement, refus, « j'en ai toujours », suppression, un utilisateur ne change pas la préférence d'un autre), scénario `WriteBudget`.
