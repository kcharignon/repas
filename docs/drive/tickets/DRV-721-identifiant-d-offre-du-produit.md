# DRV-721 — Identifiant d'offre facultatif du produit (colonne, plan, contrat)

- **Dépôt** : back (`src/Drive/`, `migrations/`, `docs/api/openapi.json`, `docs/drive/samples/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701
- **Statut** : fait (PR #XXX, 2026-10-30). Effort recommandé : `high` (migration additive, contrat du plan).
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

## Réalisation (back, 2026-10-30)

Rien n'est publié ni activé. Aucun nom ni aucune règle d'Auchan : les tests utilisent des profils fictifs (`FictionalRetailerProfiles::explicitStore()`, qui a des offres en forme d'UUID). Leclerc rend exactement les mêmes plans, choix et estimations qu'avant (`plan.sample.json`, `choices.sample.json`, `estimate.sample.json` : aucun changement) ; `tests/Repas/WriteBudget/budgets.php` ne bouge pas.

**Contrat**

- `retailers.sample.json` : `offerIdPattern` (`null` pour Leclerc), champ **additif** ; même syntaxe que les autres motifs (sans délimiteur, ancré, `[0-9]` et jamais `\d`). `RetailerTest` et `RetailerProfileTest` parcourent aussi ce motif.
- `PUT /drive/choices` : `product.offerId` (facultatif, `null` accepté, 40 caractères au plus par la validation du corps, puis la forme de l'enseigne par le cas d'usage). Pour une enseigne sans offre, toute valeur est `422 DRIVE_CHOICE_INVALID` et le lot entier est refusé (rien d'enregistré). Une offre reçue **remplace** celle de la fiche ; `null` ou la clé absente la gardent.
- `GET /drive/plans/{id}` (`lines[].products[]`) et `GET /drive/choices` (`accepted[].product` et `rejected[]`) : `offerId` **seulement s'il a une valeur** (clé absente sinon, jamais `null`). Les produits refusés du **plan** (`rejected`, deux champs : identifiant et libellé) ne la portent pas : l'agent n'ajoute jamais un produit refusé au panier. L'estimation n'est pas touchée.
- Nouveaux exemples, comparés par `DriveContractSamplesTest` : `plan-with-offer.sample.json` et `choices-with-offer.sample.json` (la farine préférée et son secours ont une offre, le lait non ; identifiants inventés, offres posées en SQL puisqu'aucune enseigne du registre n'a d'offre). Le front peut les valider contre le schéma du plan quand DRV-722 sera publié.
- `docs/api/openapi.json` : `offerId` ajouté à `ProductPayload`, `DrivePlanProductDTO` et `ChoiceProductDTO`, rien de retiré.

**Migration** `Version20261030140000` : `ALTER TABLE drive_product ADD retailer_offer_id VARCHAR(40) DEFAULT NULL`. Additive : la version précédente du code n'écrit ni ne lit la colonne (son `INSERT … ON CONFLICT` nomme ses colonnes), aucun index (la clé reste `(retailer, store_id, retailer_product_id)`), `down` la retire. `RetailerOfferIdMigrationTest` le prouve sur la vraie base (même colonne que le mapping, fiches conservées, aller-retour).

**Modèle** : `RetailerProfile::$offerIdPattern` (dernier argument, `null` par défaut), `hasOffers()`, `isValidOfferId()` ; `Retailer::isValidOfferId()` ; `Product::$offerId`, `getOfferId()`, `Product::OFFER_ID_MAX_LENGTH` (40), `Product::createFor(Retailer, RetailerProfile, …)` (comme `Store::createFor` : le corps de `create`, pour juger avec un profil fictif) et `complete(…, offerId, profile)`. `ProductBuilder::withOfferId()` charge une offre sans la juger (aucune enseigne du registre n'en a).

**Pour DRV-716 (déclarer Auchan)** : `offerIdPattern` (le motif d'UUID) dans son profil suffit ; l'API accepte alors `product.offerId` pour elle et le plan le rend. **Ordre de publication** (incident du 2026-10-20) : le site tolérant `offerId` (DRV-722) **avant** le back qui le sert. Tant qu'aucune enseigne à offre n'est déclarée et allumée, le back ne sert jamais la clé : publier ce ticket seul ne change rien pour personne.

**Limite connue** : les tests de bout en bout par l'API (`PUT /drive/choices` avec une offre acceptée, puis plan) ne sont possibles qu'une fois le cas d'enum d'une enseigne à offres ajouté (DRV-716) ; d'ici là, l'acceptation est prouvée au niveau du modèle (`ProductTest`), du dépôt (`ProductRepositoryTest`) et des chargeurs, le refus par l'API (`DriveChoicesApiFlowTest`).
