# DRV-732 — Quantité déjà au panier d'un produit connu d'Auchan : ne plus afficher « illisible »

- **Dépôt** : front (`drive-agent/src/auchan/`, flux commun si nécessaire)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-724, DRV-725
- **Statut** : en cours le 2026-10-30. Effort recommandé : `high`.
- **Lire d'abord** : les rapports de DRV-724 et 725 ; `../hypotheses-site-reel-auchan.md` § Panier.

## Contexte

La page de résultats d'Auchan ne dit pas la quantité déjà au panier (`inCart: null`) : `putInCart` affiche « Quantité au panier illisible » sur l'écran de fin pour chaque produit connu d'Auchan (vu en e2e) tant que le panier n'a pas été lu.

## Portée

Lire la quantité déjà présente par `GET /cart` (par le `Pacer`, une requête à la fois, un `GET /cart` par passage, rafraîchi après chaque écriture), sans changer Leclerc ; l'invariant « le panier n'est jamais réduit » reste prouvé.

## Critères d'acceptation

Plus de « Quantité au panier illisible » pour un produit connu d'Auchan quand le panier est lisible (e2e) ; Leclerc inchangé ; `make check`, `make agent-verify`, `make agent-e2e` verts.
