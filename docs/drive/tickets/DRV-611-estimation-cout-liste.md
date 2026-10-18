# DRV-611 — Estimation du coût d'une liste à partir des prix mémorisés

- **Dépôt** : back (`kcharignon/repas`), contexte `Drive` ; puis un affichage dans le front (ticket séparé)
- **Jalon** : Backlog devenu M2b (demande de l'utilisateur : « je veux pouvoir donner une estimation dans le futur »)
- **Taille** : M
- **Dépend de** : DRV-204 (prix mémorisés), DRV-205 (`PackCalculator`), DRV-206 (plan)
- **Statut** : fait (PR #148, 2026-10-18)
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

## Réalisation (2026-10-18, PR repas#148)

- **Lecture** : `GET /api/v1/drive/estimates/{shoppingListId}` (`GetDriveEstimateApiController`, `#[RequiresDrive]`, `ROLE_USER` + voter `SHOPPING_LIST_OWNER`), `GetDriveEstimateQuery(userId, shoppingListId)` → `GetDriveEstimateQueryHandler` → port `Drive\Application\Loader\DriveEstimateLoader` → `DriveEstimatePostgreSQLLoader`. Erreurs : `404 DRIVE_DISABLED`, `403` (liste d'un autre utilisateur : voter, le loader refuse aussi), `404` (liste inconnue), `409 DRIVE_NOT_CONFIGURED`. **Aucun contrôle de statut** : `ACTIVE`, `PAUSED`, `SENT` et `COMPLETED` répondent tous `200`.
- **Calcul** : service de domaine pur `Drive\Domain\Service\ListCostEstimator::estimate(list<EstimateLineInput>): CostEstimate` (appelle `PackCalculator`, aucune lecture, aucune horloge) ; valeurs `EstimateLineInput`, `PreferredProduct`, `EstimatedLine`, `CostEstimate`, enum `EstimateLineStatus`. Test à table `tests/Drive/Domain/ListCostEstimatorTest.php`.
- **Règles de statut** (ordre) : `HAVE_AT_HOME` (préférence « j'en ai toujours ») ; `NO_PRODUCT` (aucun produit accepté dans le magasin courant) ; `NO_PRICE` (le produit **de rang 1** n'a pas de prix mémorisé : on ne retombe pas sur un produit de secours) ; `TO_CHECK` (`PackCalculator` rend `TO_CHECK`, y compris le plafond de 99 : rien n'est deviné) ; sinon `ESTIMATED`, `subtotalCents = packs × unitPriceCents`. Un prix de 0 centime reste un prix.
- **Réponse** (ordre des clés) : `{shoppingListId, retailer, store:{id,label,baseUrl}, estimatedTotalCents, estimatedLines, unestimatedLines, coverage, oldestPriceAt, newestPriceAt, lines, disclaimer}` ; `lines[]` triées par slug d'ingrédient = `{ingredientSlug, ingredientName, productLabel|null, packs|null, unitPriceCents|null, subtotalCents|null, priceSeenAt|null, status}`. `estimatedLines` = lignes `ESTIMATED` ; `unestimatedLines` = lignes à acheter non estimées (`NO_PRODUCT`, `NO_PRICE`, `TO_CHECK`) ; `HAVE_AT_HOME` n'est dans aucun des deux. `coverage` = `estimatedLines / (estimatedLines + unestimatedLines)`, **nombre de 0 à 1 arrondi à 4 décimales** (8 sur 12 donne `0.6667`), `0` quand il n'y a rien à acheter. `oldestPriceAt` / `newestPriceAt` (ISO 8601, `null` sans ligne estimée) portent **sur les lignes du total seulement** (pas sur les lignes `TO_CHECK`). `disclaimer` = « Estimation d'après les derniers prix vus ; le prix réel est celui du drive. ».
- **Champs par statut** : `productLabel` pour tout produit connu (`ESTIMATED`, `NO_PRICE`, `TO_CHECK`), `null` pour `NO_PRODUCT` et `HAVE_AT_HOME` ; `unitPriceCents` et `priceSeenAt` dès qu'un prix est mémorisé (`ESTIMATED`, `TO_CHECK`) ; `packs` seulement pour `ESTIMATED` (le nombre par défaut d'un résultat à vérifier n'est pas exposé) ; `subtotalCents` pour `ESTIMATED` seulement.
- **Requêtes** : 4 au plus, quelle que soit la taille de la liste (réglages par `DriveSettingsLoader`, liste et lignes par le port `Shared` `ShoppingListForDriveLoader`, puis une requête de préférences du magasin courant avec le contenu et le prix des produits acceptés ; le rang 1 est le premier par rang). Test de base avec une vraie liste de 12 ingrédients (8 estimés, 4 non), `DriveEstimatePostgreSQLLoaderTest`.
- Tests : handler (`DriveEstimateInMemoryLoader`), loader, controller (`GetDriveEstimateApiControllerTest`, clés JSON), parcours réel `DriveEstimateApiFlowTest` (préférences et prix posés par `PUT /drive/choices`) ; `docs/api/openapi.json` régénéré.
