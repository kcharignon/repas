# DRV-732 — Quantité déjà au panier d'un produit connu d'Auchan : ne plus afficher « illisible »

- **Dépôt** : front (`drive-agent/src/auchan/`, flux commun si nécessaire)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-724, DRV-725
- **Statut** : fait (PR front #261, 2026-10-30, agent 0.51.1) : méthode facultative `readCartQuantity` du contrat d'adaptateur ; un `GET /cart` par passage (+ un avant chaque écriture) ; plus de « Quantité au panier illisible » pour un produit connu d'Auchan ; sans cette mention un passage qui n'a rien d'autre à dire n'a plus d'écran de fin (règle DRV-625, comme Leclerc).
- **Lire d'abord** : les rapports de DRV-724 et 725 ; `../hypotheses-site-reel-auchan.md` § Panier.

## Contexte

La page de résultats d'Auchan ne dit pas la quantité déjà au panier (`inCart: null`) : `putInCart` affiche « Quantité au panier illisible » sur l'écran de fin pour chaque produit connu d'Auchan (vu en e2e) tant que le panier n'a pas été lu.

## Portée

Lire la quantité déjà présente par `GET /cart` (par le `Pacer`, une requête à la fois, un `GET /cart` par passage, rafraîchi après chaque écriture), sans changer Leclerc ; l'invariant « le panier n'est jamais réduit » reste prouvé.

## Critères d'acceptation

Plus de « Quantité au panier illisible » pour un produit connu d'Auchan quand le panier est lisible (e2e) ; Leclerc inchangé ; `make check`, `make agent-verify`, `make agent-e2e` verts.
