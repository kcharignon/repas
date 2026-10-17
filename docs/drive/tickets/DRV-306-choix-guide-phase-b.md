# DRV-306 — Choix guidé, phase B (nouveaux ingrédients)

- **Dépôt** : front, `drive-agent/src/flow/`
- **Jalon** : M3 Agent
- **Taille** : L
- **Dépend de** : DRV-305 (variante retenue à G1 par DRV-005)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 ; décision G1 `docs/drive/spikes/DRV-005-decision-g1.md` (P1 ou P2) ; rapports DRV-002 (lignes 7 et 8) et DRV-004.

## Contexte

Pour chaque nouvel ingrédient, l'utilisateur choisit le produit qu'il préfère sur Leclerc. Un favori ne vit que le temps d'une page : deux variantes ont été étudiées. **Ce ticket implémente la variante décidée à G1** ; si G1 retient les deux (P1 avec P2 en repli), créer un second ticket pour la variante de repli plutôt que de tout faire ici.

## Portée

Commun aux deux variantes, pour chaque ligne `NEW` (et chaque ligne `KNOWN` devenue indisponible, avec le rappel « remplace <produit> ») :
- Écran hôte (DRV-304) avec le nom de l'ingrédient, la quantité demandée (« 300 g »), le compteur, et trois actions : **« Passer cette fois »** (issue `SKIPPED`), **« J'en ai toujours »** (issue `HAVE_AT_HOME`, à mémoriser), **« Annuler le passage »** (résultat `aborted`).
- Quand un produit est choisi : l'agent récupère ses données (`id`, libellé, libellé 2, prix par unité de mesure, contenu via `parseContent`), calcule le nombre de produits (règle de `architecture.md` § 6 appliquée côté agent seulement quand le contenu est connu : même formule que `PackCalculator` ; sinon écran « Quantité à vérifier »), montre « 800 g demandés, 500 g par produit : 2 », fixe la quantité absolue au panier (`setCartQuantity`), et enregistre l'issue `CHOSEN` avec `product` et `quantity`.
- Cadence et arrêt sur blocage identiques à la phase A.

**Variante P1 (cadre natif)** :
- Un `iframe` plein écran vers `recherche.aspx?TexteRecherche=<searchQuery>` du même hôte, sous la barre de l'écran hôte ; l'utilisateur utilise l'interface Leclerc (recherche, filtres, « Ajouter »).
- Repérage du produit ajouté : méthode retenue au banc d'essai (DRV-002 ligne 8) — observation du DOM du cadre ou enveloppe de `XMLHttpRequest`/`fetch` du cadre pour lire le corps de `panier.aspx?op=1` (`iIdProduit`, `iQuantite`) ; ne lire que cela. Plusieurs produits ajoutés pour un même ingrédient : proposer de choisir lequel mémoriser ; les autres restent au panier.
- Si le cadre est refusé (page vide, redirection du cadre), basculer sur un message « Ce magasin refuse l'affichage intégré » et proposer le repli P2 si implémenté, sinon « Passer ».

**Variante P2 (liste de l'agent)** :
- L'agent appelle `searchProducts(searchQuery)` et affiche jusqu'à 8 candidats (libellé, libellé 2, prix, prix par unité de mesure, promotion, indisponible grisé, vignette Leclerc du même site) avec un bouton « Choisir ».
- Champ « Chercher autre chose » qui relance `searchProducts` (cadence respectée).

## Hors portée

Suggestion automatique du meilleur candidat (backlog DRV-601).

## Critères d'acceptation

- Étant donné un nouvel ingrédient et un utilisateur qui choisit un produit de 500 g pour 800 g demandés, alors la quantité au panier est 2 et le résultat contient `CHOSEN` avec le produit, son contenu `{500, g}` et `quantity: 2`.
- « Passer cette fois » ne change rien au panier et ne mémorise rien ; « J'en ai toujours » ne change rien au panier et sera mémorisé.
- « Annuler le passage » renvoie un résultat `aborted` avec ce qui a déjà été fait.
- Contenu inconnu : l'écran « Quantité à vérifier » apparaît ; la quantité saisie est fixée.
- Un libellé hostile ne s'exécute pas.

## Tests exigés

Vitest avec adaptateur et interface simulés (variante P2 complète ; variante P1 : repérage sur un `iframe` de test jsdom + tests e2e de DRV-309 contre le faux site, y compris le cas « cadre refusé »).

## Definition of Done

Celle du front. Rapport de G1 cité dans la PR.

## Pièges connus

- Le contenu d'un produit vient de Leclerc : le traiter comme une donnée non fiable (bornes, `null` si incertain).
- Ne pas ajouter de produit au panier à la place de l'utilisateur sans confirmation : l'ajout vient de lui (P1) ou d'un bouton « Choisir » (P2).
