# DRV-611 — Estimation du coût d'une liste à partir des prix mémorisés

- **Dépôt** : back (`kcharignon/repas`), contexte `Drive` ; puis un affichage dans le front (ticket séparé)
- **Jalon** : Backlog devenu M2b (demande de l'utilisateur : « je veux pouvoir donner une estimation dans le futur »)
- **Taille** : M
- **Dépend de** : DRV-204 (prix mémorisés), DRV-205 (`PackCalculator`), DRV-206 (plan)
- **Statut** : à faire
- **Lire d'abord** : [`DRV-204`](DRV-204-produits-et-choix.md), [`DRV-205`](DRV-205-pack-calculator.md), [`DRV-206`](DRV-206-plan-d-envoi.md), [`../analyse.md`](../analyse.md) (D22), skill `repas-dev` et `docs/drive/consignes-agents.md`.

## Contexte

Le prix de chaque produit choisi est mémorisé avec sa date (`priceCents`, `pricePerMeasure`, `priceSeenAt`, D22). Cela permet d'**estimer** le coût d'une liste de courses avant de l'envoyer au drive. C'est une estimation **datée**, jamais un prix garanti : le prix réel est celui du panier Leclerc.

## Portée

1. Lecture `GET /api/v1/drive/estimates/{shoppingListId}` (derrière `#[RequiresDrive]`, droits propriétaire de la liste) : pour chaque ligne de la liste, le produit préféré (rang 1) de l'utilisateur dans son magasin courant, le nombre de produits (`packs`, par `PackCalculator`), le prix unitaire mémorisé, le sous-total en centimes ; lignes sans produit mémorisé ou sans prix : listées comme **non estimées** (jamais devinées) ; lignes « j'en ai toujours » : exclues du total.
2. Réponse : `{shoppingListId, retailer, store, estimatedTotalCents, estimatedLines, unestimatedLines, coverage (rapport lignes estimées / lignes à acheter), oldestPriceAt, newestPriceAt, lines:[{ingredientSlug, ingredientName, productLabel|null, packs|null, unitPriceCents|null, subtotalCents|null, priceSeenAt|null, status:"ESTIMATED|NO_PRODUCT|NO_PRICE|TO_CHECK|HAVE_AT_HOME"}], disclaimer}` ; `disclaimer` = texte court fixe (« Estimation d'après les derniers prix vus ; le prix réel est celui du drive. »).
3. Accessible pour une liste `ACTIVE`, `PAUSED`, `SENT` ou `COMPLETED` (lecture seule) ; erreurs : 404 `DRIVE_DISABLED`, 403, 404, 409 `DRIVE_NOT_CONFIGURED`.
4. Calcul au niveau du Loader (une poignée de requêtes SQL, jamais une par ligne) ; le calcul du total et des statuts vit dans un service de domaine pur testé en tableau ; aucun appel réseau ; aucune donnée d'un autre utilisateur.
5. `docs/api/openapi.json` régénéré, tests de handler (double en mémoire), de Loader sur base réelle, de contrôleur (clés JSON), parcours de bout en bout avec des préférences et des prix posés par `PUT /drive/choices`.

## Hors portée

L'écran du front (ticket à part, après DRV-404), l'historique des prix, la comparaison entre magasins, les promotions.

## Critères d'acceptation

- Étant donné une liste de 12 ingrédients dont 8 ont un produit préféré avec un prix, quand on demande l'estimation, alors le total est la somme des sous-totaux des 8 lignes, les 4 autres sont listées comme non estimées et la couverture vaut 8/12.
- Étant donné deux prix de dates différentes, quand on lit l'estimation, alors `oldestPriceAt` et `newestPriceAt` les donnent.
- Étant donné la liste d'un autre utilisateur, alors 403.
- Étant donné un produit sans prix, alors `NO_PRICE` et il n'entre pas dans le total.

## Definition of Done

Celle du dépôt back (`make test`, `make quality`, `make openapi`, budgets) ; PR concise ; fusion si CI verte ; coche DRV-611 dans la roadmap ; ferme l'issue.
