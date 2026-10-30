# DRV-721 — Identifiant d'offre facultatif du produit (colonne, plan, contrat)

- **Dépôt** : back (`src/Drive/`, `migrations/`, `docs/api/openapi.json`, `docs/drive/samples/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701
- **Statut** : à faire. Effort recommandé : `high` (migration additive, contrat du plan).
- **Lire d'abord** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) § 2 point 3 ; [`../spikes/DRV-714-rapport.md`](../spikes/DRV-714-rapport.md) § Recherche ; [`../architecture.md`](../architecture.md) § 8 (le plan) ; [`../consignes-agents.md`](../consignes-agents.md) (« Modifier le plan ou un message du protocole ») ; skills `repas-dev`, `repas-drive-back`.

## Contexte

Un produit d'Auchan a **deux** identifiants : `productId` (le produit, déjà mémorisé comme `retailer_product_id`, `VARCHAR(40)` : un UUID de 36 caractères y tient) et `offerId` (l'offre du produit **dans le magasin**), nécessaire à l'ajout au panier. L'agent le relit sur la carte de recherche (`data-offer-id`), mais le mémoriser évite une recherche au prochain passage. Leclerc n'a pas d'offre : le champ doit rester **absent** pour lui.

## Portée

1. **Colonne** `drive_product.retailer_offer_id VARCHAR(40) NULL` (migration additive, compatible avec la version précédente du code) ; `Product` gagne `?string $offerId` ; `RetailerProfile` gagne `offerIdPattern` (`null` pour Leclerc : un `offerId` fourni pour une enseigne sans offre est refusé `422`).
2. **Écriture** : `ProductInput` / `ProductPayload` et les dépôts (`ProductPostgreSQLRepository`, `ON CONFLICT … DO UPDATE` : l'offre peut changer, elle se met à jour). Aucune requête de plus (les budgets ne bougent pas).
3. **Lecture** : le plan (`DrivePlanDTO`, produits connus et propositions) et le choix mémorisé (`ListChoices`) portent `offerId` **seulement s'il a une valeur** (clé absente sinon : les plans de Leclerc restent identiques octet pour octet).
4. **Contrat** : `retailers.sample.json` gagne `offerIdPattern` (`null` pour Leclerc) ; les exemples `plan.sample.json` / `choices.sample.json` restent inchangés pour Leclerc ; un exemple **avec** `offerId` (enseigne de test) est ajouté et comparé par `DriveContractSamplesTest`. OpenAPI régénéré.
5. `docs/drive/architecture.md` § 8 (le plan) et le skill `repas-drive-back` mis à jour.

## Hors portée

L'entrée d'Auchan (DRV-716), le protocole et l'agent (DRV-722), l'identifiant de magasin (DRV-720).

## Critères d'acceptation

- Étant donné Leclerc, alors `plans`, `choices`, `estimate` sont **identiques** aux exemples d'avant.
- Étant donné une enseigne de test avec `offerIdPattern`, alors un produit enregistré avec son offre la rend dans le plan et dans les choix ; une offre hors motif est refusée.

## Tests exigés

Modèle et dépôts (base réelle), `GetDrivePlan` et `ListChoices` (clés JSON), contrat, budgets SQL inchangés, `make test`, `make quality`, `make openapi`.

## Definition of Done

Celle de `repas-dev` / `repas-drive-back` ; **dire dans le rapport l'ordre de publication** (le site tolérant le champ avant le back : règle du 2026-10-20) ; roadmap et statut.

## Pièges connus

- Un champ ajouté par le back est **toléré par le site avant d'être servi** (incident du 2026-10-20) : DRV-722 doit être publié avant que le back serve `offerId`, et tant qu'Auchan est éteint le champ n'est jamais servi.
- Ne pas confondre `offerId` et `retailer_product_id` dans les clés d'unicité : la clé reste `(retailer, store_id, retailer_product_id)`.
