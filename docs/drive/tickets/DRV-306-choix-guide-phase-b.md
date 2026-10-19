# DRV-306 — Choix guidé, phase B (nouveaux ingrédients)

- **Dépôt** : front, `drive-agent/src/flow/`
- **Jalon** : M3 Agent
- **Taille** : L
- **Dépend de** : DRV-305 (variante retenue à G1 par DRV-005)
- **Statut** : fait (PR [repas-front#81](https://github.com/kcharignon/repas-front/pull/81), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 ; décision G1 `docs/drive/spikes/DRV-005-decision-g1.md` (P1 ou P2) ; rapports DRV-002 (lignes 7 et 8) et DRV-004.

## Contexte

Pour chaque nouvel ingrédient, l'utilisateur choisit le produit qu'il préfère sur Leclerc. Un favori ne vit que le temps d'une page : deux variantes ont été étudiées. **Ce ticket implémente la variante décidée à G1** ; si G1 retient les deux (P1 avec P2 en repli), créer un second ticket pour la variante de repli plutôt que de tout faire ici.

## Portée

Commun aux deux variantes, pour chaque ligne `NEW` : ingrédient jamais acheté, **ou tous les produits mémorisés indisponibles** (bandeau « Ces produits ne sont plus disponibles : <libellés> »), **ou « autre produit » demandé** par l'utilisateur sur l'écran du plan (rappel « remplace <produit> ») :
- Écran hôte (DRV-304) avec le nom de l'ingrédient, la quantité demandée (« 300 g »), le compteur, et trois actions : **« Passer cette fois »** (issue `SKIPPED`), **« J'en ai toujours »** (issue `HAVE_AT_HOME`, à mémoriser), **« Annuler le passage »** (résultat `aborted`).
- Quand un produit est choisi : l'agent récupère ses données (`id`, libellé, libellé 2, prix par unité de mesure, contenu via `parseContent`), calcule le nombre de produits (règle de `architecture.md` § 6 appliquée côté agent seulement quand le contenu est connu : même formule que `PackCalculator` ; sinon écran « Quantité à vérifier »), montre « 800 g demandés, 500 g par produit : 2 », fixe la quantité absolue au panier (`setCartQuantity`), et enregistre l'issue `CHOSEN` avec `product`, `quantity` et `placement`.
- **Rôle du produit choisi** (`placement`) : si l'ingrédient n'avait aucun produit mémorisé, il devient le préféré **sans question** (`PRIMARY`). Si des produits étaient mémorisés (ils sont tous indisponibles, ou l'utilisateur a demandé un autre produit), l'écran demande « Que fais-tu de <produit> ? » avec trois choix : **« Mon nouveau produit préféré »** (`PRIMARY` : les autres restent derrière, utilisables en secours), **« Produit de secours »** (`FALLBACK` : après les autres), **« Seulement cette fois »** (`ONCE` : rien n'est mémorisé).
- **Produits à ne plus jamais prendre** : pour chaque produit mémorisé devenu indisponible, une case « Ne plus jamais prendre <produit> » ; cochée, le produit part dans `rejected` du résultat (avec libellé et contenu). Les produits déjà refusés (`rejected` du plan) ne sont jamais proposés comme choix : grisés et non sélectionnables en P2 ; en P1, si l'utilisateur en ajoute un au panier, l'agent prévient (« Tu as refusé ce produit ») et propose de le retirer (quantité 0) ou de le garder cette fois.
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
- Étant donné un ingrédient dont l'unique produit mémorisé est indisponible, quand l'utilisateur choisit un autre produit, alors l'écran demande son rôle (préféré, secours, seulement cette fois) et le résultat porte le `placement` choisi et `unavailableIds`.
- Étant donné un ingrédient jamais acheté, quand l'utilisateur choisit un produit, alors aucune question de rôle n'est posée et le placement est `PRIMARY`.
- Étant donné la case « Ne plus jamais prendre » cochée pour un produit indisponible, alors le résultat contient ce produit dans `rejected`.
- Un produit présent dans `rejected` du plan n'est pas sélectionnable (P2) ; ajouté par l'utilisateur dans le cadre (P1), l'agent le signale et propose de le retirer.

## Tests exigés

Vitest avec adaptateur et interface simulés (variante P2 complète ; variante P1 : repérage sur un `iframe` de test jsdom + tests e2e de DRV-309 contre le faux site, y compris le cas « cadre refusé »).

## Definition of Done

Celle du front. Rapport de G1 cité dans la PR.

## Pièges connus

- Le contenu d'un produit vient de Leclerc : le traiter comme une donnée non fiable (bornes, `null` si incertain).
- Ne pas ajouter de produit au panier à la place de l'utilisateur sans confirmation : l'ajout vient de lui (P1) ou d'un bouton « Choisir » (P2).

## Points laissés par DRV-304 et DRV-305 (2026-10-18)

La phase B se branche par `FlowDeps.phaseB(context)` (voir DRV-305, « Réalisation ») et l'écran `chooser` existe déjà (`ui.show('chooser', { ingredient, position, total, mount(zone, answer) })`). Textes d'erreur : parler de l'application Repas Drive, pas du favori.

## Réalisation (2026-10-18, PR repas-front#81)

Variante P2 : `drive-agent/src/flow/{phase-b,packs}.ts`, `drive-agent/src/ui/picker.ts`. L'agent cherche le nom (ou `searchQuery`), affiche 8 produits au plus (libellé, contenu, prix, prix à l'unité de mesure, promotion, calcul « 800 g demandés, 500 g par produit : 2 »), n'ajoute au panier qu'après « Choisir ». Issue `CHOSEN` avec rôle : `PRIMARY` sans question quand rien n'est mémorisé ; sinon écran `placement` (« Mon nouveau produit préféré », « Produit de secours », « Seulement cette fois »). Boutons « Passer cette fois », « J'en ai toujours », « Annuler le passage », case « Ne plus jamais prendre » par produit indisponible. Produit refusé ou en rupture grisé, quantité plafonnée au stock, erreur réseau affichée dans la liste (réessayer ou passer), `BLOCKED` met les lignes restantes en `FAILED`. Pas de vignettes. Exports : `guidedChoice`, `packsFor`, `labelOf`. **Protocole : `needed` ajouté** à une ligne du plan (voir `architecture.md` § 8).

## Réalisation : corrections de la recette réelle (2026-10-18 et 19)

- [repas-front#130](https://github.com/kcharignon/repas-front/pull/130) (agent 0.11.0, ticket D) : la recherche faite par l'agent (nom ou `searchQuery`) essaie les formes dérivées du nom quand le moteur ne répond rien ; la liste dit quelle recherche a répondu. Jamais pour ce que l'utilisateur tape lui-même.
- [repas-front#135](https://github.com/kcharignon/repas-front/pull/135) (agent 0.12.0, ticket M) : la quantité fixée après un choix ne baisse jamais ce que le panier contient déjà.
- [repas-front#136](https://github.com/kcharignon/repas-front/pull/136) (agent 0.13.0, ticket K) : « Reprendre » après un défi refait les lignes qui attendent.
- Lignes du jalon : [`roadmap.md`](../roadmap.md) § M3c.
