# DRV-008 — Corriger l'ajout du banc d'essai et tester les quantités

- **Dépôt** : front (`kcharignon/repas-front`), dossier `drive-agent/lab/`
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : DRV-007 (fait)
- **Statut** : fait et validé sur le site (PR repas-front#62, #63 et #64, 2026-10-17 : ajout et retrait HTTP 200 depuis le banc, sur ordinateur)
- **Lire d'abord** : [`../spikes/DRV-007-requete-ajout-panier.md`](../spikes/DRV-007-requete-ajout-panier.md), [`../recherche.md`](../recherche.md) (lignes « Forme exacte de la requête du site »), `drive-agent/lab/src/cart.ts`, `drive-agent/lab/src/essais/cartFlow.ts`, `drive-agent/lab/src/http.ts`, `drive-agent/lab/PROCEDURE.md`, `CLAUDE.md` du front.

## Contexte

L'essai 3 du banc (DRV-001) a reçu un **HTTP 404** à l'ajout au panier sur le site réel, alors que la requête envoyée par le site lui-même reçoit 200 (DRV-007). Deux écarts : le banc envoie l'identifiant de produit en **nombre** (le site : en **texte**) et il n'envoie **pas** l'objet `objContexteProvenanceArticle` à l'ajout. Le banc a en outre présenté ce 404 comme un « blocage probable » alors que ce n'est pas un 403/429 d'anti-robot. Il faut corriger l'essai 3 pour que l'utilisateur le refasse sur son ordinateur, et profiter du passage pour mesurer le sens des quantités.

Forme exacte relevée sur le site (2026-10-17) du champ de formulaire `d` (le point de livraison vient de la page, comme aujourd'hui) :

- ajout : `{"eTypeAction":1,"iIdProduit":"1955","iQuantite":1,"sNoPointLivraison":"…","objContexteProvenanceArticle":{"eOrigine":4,"eTypePage":3,"sTexteRecherche":"farine","eVue":0,"sInformationsComplementaires":"uni-2"}}`
- retrait : `{"eTypeAction":2,"iIdProduit":"1955","iQuantite":0,"sNoPointLivraison":"…"}` (sans contexte)

## Portée

1. `cartPayload` : l'identifiant du produit est écrit **en texte**. À l'ajout, joindre par défaut l'objet de contexte ci-dessus, avec `sTexteRecherche` = le dernier mot cherché à l'essai 2 (ou vide s'il n'y en a pas). Retirer la case à cocher « objet d'analytique vide » (remplacée par ce comportement) ou la garder comme variante « sans contexte » pour le diagnostic.
2. Un code **404** (ou toute réponse non JSON hors 403/429) n'est plus présenté comme un blocage : message distinct « La requête n'a pas été comprise par Leclerc (HTTP 404). Ce n'est pas un blocage de l'anti-robot. » Le message de blocage reste réservé à 403/429.
3. Résumé de la réponse : lire `lstProduitsLight` de l'évènement `Panier…` (par produit : `iIdProduit`, `iQtePanier`, `iQteDisponible`, `fQuantiteMax`), `iQuantitePanier` et `sTotalAPayer`, et afficher la quantité obtenue pour le produit testé.
4. **Nouvel essai 3b « Quantités »** (après l'essai 3 réussi, un produit peu cher, confirmation avant **chaque** écriture, une écriture à la fois) : fixer la quantité à 3 (`eTypeAction: 1`, `iQuantite: 3`), puis à 2 avec `eTypeAction: 1` et `iQuantite: 2` ; lire la quantité obtenue ; si elle n'est pas 2, proposer l'essai avec `eTypeAction: 2` et `iQuantite: 2` ; puis retirer (`eTypeAction: 2`, `iQuantite: 0`). Afficher, pour chaque étape, l'action, la quantité demandée, le code HTTP et la quantité obtenue, de façon copiable. But : savoir si `iQuantite` est bien absolu et quelle action fait diminuer à une quantité non nulle.
5. Essai 10 (contenu net) : si les produits de la recherche portent `sUniteMesure`, `nrContenanceUnitaire` ou `nrContenanceTotale`, les afficher à côté des deux méthodes existantes et dire s'ils s'accordent avec elles ; sinon l'écrire.
6. `PROCEDURE.md` : mettre à jour la description des essais 3 et 3b (en français simple, une étape par numéro).
7. Tests unitaires Vitest sur des données **synthétiques** (corps de la requête, résumé de réponse, classement des codes). Jamais de réponse Leclerc réelle dans le dépôt.

## Hors portée

Le vrai agent. Toute boucle d'écriture ou écriture sans confirmation. Toute copie de cookie, jeton, numéro de magasin.

## Critères d'acceptation

- Étant donné le corps d'ajout construit pour le produit `1955` et la recherche « farine », quand on le décode, alors il est égal, champ par champ, à la forme relevée (identifiant en texte, contexte joint).
- Étant donné le corps de retrait, quand on le décode, alors il n'a pas de contexte et l'identifiant est en texte.
- Étant donné une réponse 404 HTML, quand le banc la résume, alors il n'affiche pas « blocage » mais le message de requête non comprise.
- Étant donné une réponse d'écriture simulée avec `lstProduitsLight`, quand le banc la résume, alors la quantité du produit testé est affichée.
- `make check` est vert.

## Definition of Done

Celle du dépôt front (`make check`, PR concise, fusion si la CI est verte) ; `make lab` reconstruit le favori, taille sous 60 Ko ; mettre à jour la case DRV-008 de `docs/drive/roadmap.md` dans le dépôt back (petite PR docs).

## Pièges connus

- Le champ `sNoPointLivraison` est le numéro du point de livraison : ne jamais l'écrire dans un test, un message d'erreur ou un journal copiable.
- Le favori reste un outil jetable : ne pas y investir plus que nécessaire (il disparaît après la décision G1).

## Suite (2026-10-19) : le banc d'essai est retiré du dépôt front

Le banc d'essai du favori `drive-agent/lab/` (dont `published/lab.js`, servi par jsDelivr) a été retiré du dépôt front par la PR [repas-front#134](https://github.com/kcharignon/repas-front/pull/134) (constat C6 de la revue de sécurité du front, la recette réelle étant faite), avec ses cibles `make lab*` et `lab:*`. L'historique Git le garde. Voir [`roadmap.md`](../roadmap.md) § M3c.
