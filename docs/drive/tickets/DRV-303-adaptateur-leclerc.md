# DRV-303 — Adaptateur Leclerc (magasin, recherche, panier, erreurs, cadence)

- **Dépôt** : front, `drive-agent/src/leclerc/`
- **Jalon** : M3 Agent
- **Taille** : L
- **Dépend de** : DRV-301, DRV-302
- **Statut** : à faire
- **Lire d'abord** : [`../recherche.md`](../recherche.md) §§ 2, 3 ; [`../architecture.md`](../architecture.md) § 8 ; rapport `docs/drive/spikes/DRV-002-rapport.md` (ce qui a réellement marché).

## Contexte

Tout ce qui est spécifique à Leclerc est isolé ici, derrière une interface, pour qu'une autre enseigne ou un changement du site ne touche que ce dossier. L'agent s'exécute dans la page : tous les appels sont **de même origine**, avec les cookies de la session de l'utilisateur, jamais lus par le code.

## Portée

1. Interface `RetailerAdapter` : `readStore(): StoreInfo`, `searchProducts(query): Promise<Candidate[]>`, `setCartQuantity(productId, quantity): Promise<CartResult>`, `readProductFromSearch(query, productId): Promise<Candidate | null>` ; implémentation `LeclercAdapter`. `fetch` et l'horloge sont injectés (tests).
2. `readStore` : identifiant et hôte lus dans `location` (`/magasin-<id>-<id>`), libellé lu dans la page s'il existe ; page hors `*.leclercdrive.fr` ou sans magasin : `UNSUPPORTED_PAGE`.
3. `searchProducts` : `GET recherche.aspx?TexteRecherche=<requête encodée>` sur l'hôte courant (`credentials: 'same-origin'`), analyse du JSON d'initialisation embarqué (champs : `iIdProduit`, `sLibelleLigne1`, `sLibelleLigne2`, `nrPVUnitaireTTC`, `sPrixUnitaire`, `sPrixPromo`, `nrPVParUniteDeMesureTTC`, `sPrixParUniteDeMesure`, `iQteDisponible`, `iQuantitePanier`, `sUrlVignetteProduit`) par un analyseur **tolérant** (pas d'expression régulière unique fragile ; extraire les blocs JSON, ignorer les champs inconnus). `Candidate` = `{id, label, label2, price, pricePerMeasure, promo, stock, inCart, thumbnailUrl, content}`.
4. `parseContent(label2, unitPrice, pricePerMeasure)` : déduit `{value, unit}` normalisé (`g|ml|piece`) du libellé (« 1 kg », « 6 x 125 g », « lot de 4 », « 75 cl ») ou de `prix unitaire ÷ prix par unité de mesure` ; `null` si incertain. Tests à table sur au moins 40 libellés **inventés**.
5. `setCartQuantity` : `POST panier.aspx?op=1`, `Content-Type: application/x-www-form-urlencoded; charset=UTF-8`, `X-Requested-With: XMLHttpRequest`, un champ `d` = JSON encodé. **Forme relevée sur le site le 2026-10-17 (DRV-007)** : ajout `{"eTypeAction":1,"iIdProduit":"<id en TEXTE>","iQuantite":<n>,"sNoPointLivraison":"<point de livraison lu dans la page>","objContexteProvenanceArticle":{"eOrigine":4,"eTypePage":3,"sTexteRecherche":"<texte cherché>","eVue":0,"sInformationsComplementaires":"uni-2"}}` ; retrait `{"eTypeAction":2,"iIdProduit":"<id en TEXTE>","iQuantite":0,"sNoPointLivraison":"…"}` (sans contexte). Sans le contexte et avec l'identifiant en nombre, le site répondait 404 au banc. `iQuantite` est **absolue** (jamais un delta) ; lire `lstProduitsLight` de la réponse pour confirmer la quantité. À confirmer (DRV-008) : le sens de `eTypeAction` pour diminuer à une quantité non nulle ; si `1` avec une quantité inférieure ne diminue pas, utiliser `2`. Quantité plafonnée à 99 et au stock connu.
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
