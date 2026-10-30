# DRV-739 — Recherche d'Auchan : un produit en vente en ligne seulement n'arrête plus tout l'envoi

- **Dépôt** : front (`drive-agent/src/auchan/`, `drive-agent/fake-auchan/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-734
- **Statut** : fait (PR front #269, agent 0.51.3, 2026-10-30). Rejoué avec succès à la recette réelle (DRV-726).
- **Lire d'abord** : [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) B-1, B-5b, B-9 ; [`DRV-734`](DRV-734-recherche-d-auchan-redirigee-vers-un-rayon.md) (même famille de défaut).

## Contexte

Recette réelle du 2026-10-30 (un vrai envoi depuis Repas, WebView de l'application de débogage, six articles) : les quatre premiers articles sont écrits au panier, puis la recherche du cinquième (un thé glacé) s'arrête en `CONTRACT_CHANGED` : « Auchan a changé son site, l'envoi automatique est arrêté ». La page (200, sans redirection) contient **un seul produit** dont les cartes portent **trois identifiants d'offre différents, tous `data-seller-type="ONLINE"`** : des vendeurs en ligne, pas le drive (le drive est `GROCERY`). L'agent exigeait un seul identifiant d'offre par produit ; ce produit était donc illisible, la page entière n'avait aucun produit lisible, et l'adaptateur concluait que le site avait changé.

## Portée

1. **Une offre n'est une offre du drive que si son vendeur est `GROCERY`** (B-9). Dans un produit, les offres d'un vendeur connu autre que `GROCERY` sont ignorées **avant** de compter.
2. Il reste **une** offre du drive : le produit est lu avec elle (ses attributs d'offre, pas ceux des autres offres). **Aucune** : le produit n'est pas du drive, il est écarté et compté à part (`PageReading.notDrive`, `SearchFacts.notDrive`, un nombre au diagnostic), ni lisible ni illisible. **Plusieurs** offres du drive différentes : ambigu, illisible comme avant. Aucune offre du tout, pas de carte ou pas de nom : illisible comme avant.
3. Une page dont **tous** les produits sont hors drive (et aucun illisible) est une page **sans résultat utilisable** : zéro produit, sans erreur, comme « aucun résultat » (`why: 'NOT_DRIVE'`) ; la ligne est dite « aucun produit trouvé » et l'envoi continue avec les autres lignes.
4. `CONTRACT_CHANGED` reste pour une vraie rupture : des produits du drive présents mais illisibles, ou aucune trace de produit ni page « aucun résultat » reconnue.
5. La règle est dans la lecture de la page (`readProducts`), donc commune à `searchProducts`, `readProductFromSearch` (phase A, essai au panier, rafraîchissement du prix).

## Critères d'acceptation

- Un produit à trois offres `ONLINE` : page sans produit, pas d'erreur, fait rapporté par un nombre (jamais un identifiant ni un libellé).
- Un produit en ligne et au drive : lu avec l'offre du drive, mémorisée pour le panier.
- Deux offres du drive : illisible ; seul produit de la page : `SEARCH_PRODUCTS_UNREADABLE`.
- Un produit du drive illisible à côté d'un produit en ligne : `CONTRACT_CHANGED`.
- Faux site d'Auchan : termes `enligne` et `mixte` ; scénario de bout en bout : la farine connue est écrite, la ligne en ligne seulement est passée, la suivante est cherchée, l'envoi se termine sans erreur.

## Points laissés

- Un vendeur dont le code est absent, contradictoire ou mal formé n'est pas écarté (comportement d'avant conservé) : seul un vendeur connu autre que `GROCERY` l'est.
- Non mesuré : un produit qui porte à la fois une offre en ligne et une offre du drive (le prix et la disponibilité de la carte décrivent-ils l'offre du drive ?). À regarder à la recette réelle (DRV-726).
