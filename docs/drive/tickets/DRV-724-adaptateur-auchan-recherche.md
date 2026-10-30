# DRV-724 — Adaptateur Auchan : recherche et lecture des produits

- **Dépôt** : front (`drive-agent/src/retailers/auchan/`, `drive-agent/fake-auchan/`, `drive-agent/fixtures/auchan/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-717
- **Statut** : à faire. Effort recommandé : `high`.
- **Lire d'abord** : [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) § Recherche et produits ; [`../spikes/DRV-714-rapport.md`](../spikes/DRV-714-rapport.md) ; DRV-717 ; `drive-agent/src/leclerc/search-*.ts` (modèle) ; skill `repas-agent-dev`.

## Portée

1. **`searchProducts`, `readProductFromSearch`, `lastSearchFacts`, `exploreSearchVariants`** d'après les formes mesurées : `GET /recherche?text=<terme>` (page HTML complète, lue par `DOMParser` ; 400 à 700 Ko), produits dans le DOM par `data-product-id`, `data-offer-id`, `data-seller-type`, `data-offer-type`, et microdonnées schema.org (`name`, `brand`, `image`, `price` décimal en euros, `priceCurrency`, `availability`) ; **un produit = un identifiant `productId` et un `offerId`** (DRV-722) ; libellé = marque + nom ; prix en euros convertis en centimes ; disponibilité (`AVAILABLE`, mention « Dans mon drive », microdonnées `InStock`) ; **valeurs « indisponible » non mesurées** : tout ce qui n'est pas clairement disponible est `UNAVAILABLE`, jamais ajouté.
2. **Contenu net et prix à l'unité** : la pastille (« 1kg ») et le prix au kilo ou au litre sont affichés ; en extraire le contenu comme chez Leclerc (`PackCalculator` ne change pas) ; un contenu illisible reste `null` (jamais deviné).
3. **EAN** : absent de la page de résultats (`ean: null` en phase A) ; il est mémorisé **après l'ajout** par l'adaptateur du panier (DRV-725).
4. **Cadence** : une requête de recherche passe par le `pacer` de l'adaptateur (DRV-715 : pause 2 000 ms, gigue 1 000 ms), jamais en parallèle.
5. **Faux site** : `fake-auchan/` sert des pages de résultats synthétiques (plusieurs produits, un indisponible, un sans prix, aucun résultat, un terme redirigé) ; fixtures synthétiques.
6. Tests unitaires sur fixtures, e2e contre le faux site ; `hypotheses-site-reel-auchan.md` mis à jour.

## Hors portée

Le panier (DRV-725), la pagination et les filtres de la recherche (non mesurés : `I`), tout appel au vrai site.

## Critères d'acceptation

- Étant donné le faux site, alors la phase A (recherche, propositions, choix) rend les mêmes **types** de résultats que contre le faux Leclerc, avec un `offerId` pour chaque produit.
- Étant donné une page de résultats qui ne ressemble à aucune forme connue, alors `CONTRACT_CHANGED` avec une cause explicite (jamais un produit inventé).

## Tests exigés

`make agent-verify`, `make agent-e2e`, `make check` ; test d'architecture inchangé.

## Definition of Done

Celle du dépôt front et du skill `repas-agent-dev` ; empreinte de l'agent ; **front avant application** ; aucune étiquette.

## Pièges connus

- La page de résultats est lourde : lire le DOM par sélecteurs, ne pas l'analyser par expressions régulières sur 600 Ko.
- Un terme peut **rediriger** vers une page de produit ou de rayon (clé `searchRedirects` : supposé, `S`) : une redirection n'est pas une page de résultats.
- Ne pas confondre le nombre de balises `data-product-id` (plusieurs par produit) avec le nombre de produits.
