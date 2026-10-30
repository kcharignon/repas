# DRV-725 — Adaptateur Auchan : panier (quantité absolue, lecture, défi, cadence)

- **Dépôt** : front (`drive-agent/src/retailers/auchan/`, `drive-agent/fake-auchan/`, `drive-agent/fixtures/auchan/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-717 (et DRV-724 pour le passage complet)
- **Statut** : à faire. Effort recommandé : `high` (`xhigh` pour la détection d'un défi et la cadence).
- **Lire d'abord** : [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) § Panier et § Anti-robot ; [`../spikes/DRV-714-rapport.md`](../spikes/DRV-714-rapport.md) § Panier ; [`../spikes/DRV-713-rapport.md`](../spikes/DRV-713-rapport.md) ; DRV-717 ; `drive-agent/src/leclerc/cart.ts`, `pacer.ts`, `errors.ts` (modèle) ; skill `repas-agent-dev`.

## Portée

1. **`setCartQuantity`** (quantité **absolue**) : lire d'abord le panier (`GET /cart`, avec `cartId` du cookie `lark-cart` pour un visiteur, sans `cartId` pour un client connecté ; `consentId` du cookie `lark-consentId`), prendre `cart.id`, l'`id` de la ligne si elle existe, puis `POST /cart/update` avec `{cartId, items:[{productId, offerId, sellerType, desiredQuantity, desiredType, sellerId, id?}], consentId, reservationId: null, mbaAvailabilityNeeded: false}`, en-tête `X-Requested-With: XMLHttpRequest`, **aucun jeton d'en-tête**. Toujours **relire** la ligne dans la réponse (`desiredQuantity` = `actualQuantity`) ; une quantité qui n'est pas celle demandée est un échec, pas un succès. **Jamais d'écriture en parallèle.**
2. **Invariants** : quantité plancher (le panier n'est jamais réduit : la quantité absolue envoyée est `max(demandée, déjà présente)`), jamais de `desiredQuantity: 0`, aucune validation de commande, aucun créneau.
3. **`cartQuantity`, `pageCartTotal`** : lecture de `GET /cart` (total en **centimes** : `cart.prices.totalPrice.amount`) et du panier visible ; **EAN** : `product.eans[0]` de la réponse d'ajout est rendu au résultat (mémorisé par le web).
4. **Plafond** : `offering.maxQuantity` borne la quantité ; au-delà, résultat `UNAVAILABLE` avec la cause (message de dépassement : **non mesuré**).
5. **Défi et cadence** : détection d'un défi DataDome et d'un reCAPTCHA comme chez Leclerc (pause, bandeau, reprise) ; marqueurs **non mesurés** (aucun défi n'a été vu) : écrits comme tels, avec test d'une page de défi **inventée** ; cadence du registre (2 000 ms, gigue 1 000 ms), `pacer` partagé ; **ne jamais répéter une action qui a déclenché un défi**.
6. **Erreurs du site** : les codes `401` et `99001` à `99007` de `GET /cart/config` sont **inconnus** : tout statut ou corps inattendu devient `CONTRACT_CHANGED` / `FAILED` avec une cause explicite, jamais un succès.
7. **Visiteur** : un client non connecté est détecté par `readSession` (DRV-717) ; le comportement (remplir quand même, ou demander de se connecter) suit la règle du plan existante pour Leclerc ; le panier de visiteur étant **fusionné** à la connexion, le danger est moindre, mais l'agent ne conclut jamais « connecté » parce qu'une écriture réussit.
8. **Faux site** : `POST /cart/update` absolu, lecture, plafond, ligne sans `id`, `401`, page de défi inventée ; passage complet (phases A et B) e2e ; `hypotheses-site-reel-auchan.md` mis à jour.

## Hors portée

Allumer Auchan en production, la recette réelle (DRV-726), toute mesure sur le vrai site.

## Critères d'acceptation

- Étant donné le faux site, alors un passage complet donne les mêmes types de résultats que contre le faux Leclerc (`ADDED`, `CHOSEN`, `UNAVAILABLE`, `FAILED`) avec les garde-fous des invariants.
- Étant donné une ligne déjà présente à une quantité supérieure à celle demandée, alors elle **n'est jamais réduite**.
- Étant donné une réponse d'écriture dont la quantité diffère de la demande, alors l'agent échoue au lieu de réussir.

## Tests exigés

`make agent-verify`, `make agent-e2e`, `make android-test`, `make android-e2e` (sous le verrou), `make check`.

## Definition of Done

Celle du dépôt front et du skill `repas-agent-dev` ; empreinte de l'agent ; **front avant application** ; aucune étiquette ; rapport court des hypothèses non mesurées.

## Pièges connus

- Un essai du 2026-10-30 (écriture **sans** `id` sur une ligne existante) a laissé la quantité inchangée : toujours envoyer l'`id` et relire.
- Chez Leclerc, des écritures en parallèle ont fait bloquer un client : une à la fois, espacées.
- Le cookie `lark-cart` disparaît après la connexion : ne pas le supposer présent.
