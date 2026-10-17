# DRV-303 — Adaptateur Leclerc (magasin, recherche, panier, erreurs, cadence)

- **Dépôt** : front, `drive-agent/src/leclerc/`
- **Jalon** : M3 Agent
- **Taille** : L
- **Dépend de** : DRV-301, DRV-302
- **Statut** : fait (PR [repas-front#78](https://github.com/kcharignon/repas-front/pull/78), 2026-10-17)
- **Lire d'abord** : [`../recherche.md`](../recherche.md) §§ 2, 3 ; [`../architecture.md`](../architecture.md) § 8 ; rapport `docs/drive/spikes/DRV-002-rapport.md` (ce qui a réellement marché).

## Contexte

Tout ce qui est spécifique à Leclerc est isolé ici, derrière une interface, pour qu'une autre enseigne ou un changement du site ne touche que ce dossier. L'agent s'exécute dans la page : tous les appels sont **de même origine**, avec les cookies de la session de l'utilisateur, jamais lus par le code.

## Portée

1. Interface `RetailerAdapter` : `readStore(): StoreInfo`, `searchProducts(query): Promise<Candidate[]>`, `setCartQuantity(productId, quantity): Promise<CartResult>`, `readProductFromSearch(query, productId): Promise<Candidate | null>` ; implémentation `LeclercAdapter`. `fetch` et l'horloge sont injectés (tests).
2. `readStore` : identifiant et hôte lus dans `location` (`/magasin-<id>-<id>`), libellé lu dans la page s'il existe ; page hors `*.leclercdrive.fr` ou sans magasin : `UNSUPPORTED_PAGE`.
3. `searchProducts` : `GET recherche.aspx?TexteRecherche=<requête encodée>` sur l'hôte courant (`credentials: 'same-origin'`), analyse du JSON d'initialisation embarqué (champs : `iIdProduit`, `sLibelleLigne1`, `sLibelleLigne2`, `nrPVUnitaireTTC`, `sPrixUnitaire`, `sPrixPromo`, `nrPVParUniteDeMesureTTC`, `sPrixParUniteDeMesure`, `iQteDisponible`, `iQuantitePanier`, `sUrlVignetteProduit`) par un analyseur **tolérant** (pas d'expression régulière unique fragile ; extraire les blocs JSON, ignorer les champs inconnus). `Candidate` = `{id, label, label2, price, pricePerMeasure, promo, stock, inCart, thumbnailUrl, content}`.
4. `parseContent(label2, unitPrice, pricePerMeasure)` : déduit `{value, unit}` normalisé (`g|ml|piece`) du libellé (« 1 kg », « 6 x 125 g », « lot de 4 », « 75 cl ») ou de `prix unitaire ÷ prix par unité de mesure` ; `null` si incertain. Tests à table sur au moins 40 libellés **inventés**.
5. `setCartQuantity` : `POST panier.aspx?op=1`, `Content-Type: application/x-www-form-urlencoded; charset=UTF-8`, `Accept: */*`, `X-Requested-With: XMLHttpRequest`, `zcwb: ajax-manager`, un champ `d` = JSON encodé. **Forme relevée sur le site le 2026-10-17 (DRV-007)** : ajout `{"eTypeAction":1,"iIdProduit":"<id en TEXTE>","iQuantite":<n>,"sNoPointLivraison":"<point de livraison lu dans la page>","objContexteProvenanceArticle":{"eOrigine":4,"eTypePage":3,"sTexteRecherche":"<texte cherché>","eVue":0,"sInformationsComplementaires":"uni-2"}}` ; retrait `{"eTypeAction":2,"iIdProduit":"<id en TEXTE>","iQuantite":0,"sNoPointLivraison":"…"}` (sans contexte). Sans le contexte et avec l'identifiant en nombre, le site répondait 404 au banc ; **en-tête obligatoire `zcwb: ajax-manager`** et adresse **`/magasin-<id>-<id>/panier.aspx?op=1` sans suffixe de ville** (les pages portent `-<Ville>` après les deux identifiants, pas la requête du panier) : voir DRV-007. `iQuantite` est **absolue** (jamais un delta) ; lire `lstProduitsLight` de la réponse pour confirmer la quantité. **Confirmé (essai 3b, 2026-10-17)** : `eTypeAction: 1` avec `iQuantite` = n fixe la quantité à n, aussi bien en augmentant qu'en diminuant (3 puis 2 : quantités obtenues 3 puis 2, HTTP 200) ; le retrait complet se fait avec `eTypeAction: 2` et `iQuantite: 0`. L'évènement produit de la réponse vaut 101 (ajout), 104 (changement de quantité) ou 102 (retrait). Quantité plafonnée à 99 et au stock connu.
6. Taxonomie d'erreurs (`LeclercError`) : `NOT_LOGGED_IN` (réponse de connexion ou 401), `BLOCKED` (403/429 ou page de défi DataDome reconnue), `CONTRACT_CHANGED` (réponse 200 mais aucun produit analysable alors que la page n'est pas « aucun résultat », ou réponse du panier d'un format inconnu), `NETWORK`, `UNSUPPORTED_PAGE`. Aucune erreur ne contient de cookie, d'en-tête ni de contenu de page.
7. `Pacer` : attente ≥ 800 ms entre deux requêtes + gigue aléatoire jusqu'à +700 ms ; **jamais deux requêtes en parallèle** (file d'attente) ; arrêt après 2 réponses 403/429 consécutives ; une seule reprise sur erreur réseau (délai 2 s).
8. Fixtures synthétiques dans `drive-agent/fixtures/` reproduisant la **structure** décrite (jamais du HTML Leclerc réel) : page de résultats avec 5 produits (dont un indisponible et un en promotion), page vide, page changée, réponse de panier valide et invalide.

## Hors portée

Interface (DRV-304), enchaînement des phases (DRV-305, 306).

## Critères d'acceptation

- Sur les fixtures, `searchProducts('farine')` renvoie les candidats attendus (prix, stock, contenu) ; sur une page changée, `CONTRACT_CHANGED` ; sur 403, `BLOCKED` après la deuxième réponse consécutive.
- `setCartQuantity` construit le corps attendu (champ `d`, quantité absolue, magasin) et lève `NOT_LOGGED_IN`/`BLOCKED` selon la réponse simulée.
- Aucune requête n'est envoyée vers un autre hôte que celui de la page ; deux appels simultanés sont sérialisés.
- `parseContent` réussit sur ≥ 90 % des 40 libellés de test et renvoie `null` (jamais une valeur fausse) sur les ambigus.

## Tests exigés

Vitest avec `fetch` simulé et horloge fausse (aucun appel réseau) : analyseur, contenu, panier, cadence (temps simulé), erreurs.

## Definition of Done

Celle du front. Aucun HTML brut de Leclerc dans le dépôt.

## Pièges connus

- Le panier n'a pas de lecture propre : ne pas tenter de le lire ; utiliser `iQuantitePanier` de la recherche quand c'est utile.
- **Constat du 2026-10-17** ([DRV-007](../spikes/DRV-007-requete-ajout-panier.md)) : chaque écriture renvoie l'état complet du panier (`lstProduitsLight`, `iQuantitePanier`, `sTotalAPayer`) : l'utiliser pour vérifier la quantité obtenue au lieu de lire une page. Le produit porte aussi un contenu net structuré (`sUniteMesure`, `nrContenanceUnitaire`, `nrContenanceTotale`) à préférer au libellé quand il existe. Un 404 à l'ajout n'est pas un blocage anti-robot : ne pas le classer `BLOCKED` (requête mal formée → `CONTRACT_CHANGED`).
- Les écritures parallèles déclenchent DataDome : la file sérialisée n'est pas une optimisation, c'est une exigence.

- **Prix (décision D22)** : `searchProducts` doit renvoyer aussi le prix unitaire (`nrPVUnitaireTTC`) et le prix à l'unité de mesure (`nrPVParUniteDeMesureTTC` et son unité) en centimes, quand ils sont lus ; ils partent dans le résultat (`price`).

## Réalisation (2026-10-17, PR repas-front#78)

`drive-agent/src/leclerc/` : `RetailerAdapter` et `LeclercAdapter` (`fetch` et horloge injectés), `browserDeps(window)`, `LeclercError` (code), `Pacer`, `parseContent`, `Candidate` (avec `contentSource` et `promo`), `CartResult { productId, requested, sent, obtained, ok, items }`, `StoreInfo { id, host, baseUrl, label }`. Le premier 403/429 est retenté une fois après la pause de cadence, le second de suite lève `BLOCKED`. Devinés, **à confirmer à la recette réelle (DRV-505)** : la phrase « aucun résultat » d'une page vide, la réponse de connexion (401 ou redirection vers `connexion`, `identif`, `login`, `authent`), le libellé du magasin (`label` null par défaut). Branché, l'adaptateur ajoute environ 13 Ko au bundle.
