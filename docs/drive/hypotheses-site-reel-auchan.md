# Auchan : ce qui est mesuré, supposé et inconnu

Pendant de [`hypotheses-site-reel.md`](hypotheses-site-reel.md) (Leclerc). Source : les spikes du 2026-10-30 ([DRV-712](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md)) et la décision [DRV-715](spikes/DRV-715-decision-auchan.md). Chez Leclerc, plusieurs hypothèses se sont révélées fausses à la recette : **tout ce qui n'est pas « mesuré » est faux jusqu'à preuve du contraire**. Chaque ticket de l'adaptateur (DRV-717, 724, 725, 726) met ce fichier à jour.

Légende : **M** mesuré sur le vrai site (Chrome du téléphone de test, une session, un magasin, 2026-10-30) · **S** supposé (déduit, jamais vérifié) · **I** inconnu.

## Magasin et parcours

| # | Fait | Statut |
|---|---|---|
| A-1 | Le magasin n'est pas dans l'adresse (`www.auchan.fr/courses` reste identique) ni dans `localStorage` ; il est gardé par la session (cookie `connect.sid`, `HttpOnly`, un jour). | M |
| A-2 | `GET /journey` rend le parcours sans article au panier ; `activeContexts[type=GROCERY].context` porte `seller.id` (UUID), `seller.storeReference.id` (3 chiffres), `channels` (`PICK_UP`), `pointOfService.{name, type: "DRIVE", url}`. | M |
| A-3 | `seller.id` du parcours = `offer.context.seller.id` du panier. | M |
| A-4 | Le libellé du drive (`pointOfService.name`) est celui de la barre « Retrait : … ». | M |
| A-5 | La déconnexion efface le magasin (barre « Choisir vos courses »). | M |
| A-6 | La connexion conserve le magasin choisi en visiteur. | M |
| A-7 | L'identifiant de magasin reste le même d'un jour à l'autre. | I |
| A-8 | La livraison à domicile a d'autres contextes (`channels`, vendeur) ; les stocks y diffèrent de ceux du drive. | I |
| A-9 | Un panier se vide ou se recalcule quand on change de magasin ou de mode. | I |

## Recherche et produits

| # | Fait | Statut |
|---|---|---|
| B-1 | `GET /recherche?text=<terme>` rend une page HTML complète (390 à 700 Ko), les produits dans le DOM : `data-product-id`, `data-offer-id`, `data-seller-type` (`GROCERY`), `data-offer-type` (`DEFAULT`), `data-seller-id` (vide) et microdonnées schema.org (`name`, `image`, `brand`, `offers`, `price`, `priceCurrency`, `priceValidUntil`, `availability`). | M |
| B-2 | `productId` et `offerId` sont des UUID ; l'adresse d'un produit est `/<libellé>/pr-C<7 chiffres>` (nombre sans rapport avec l'UUID). | M |
| B-3 | Pas d'EAN sur la page de résultats ; `product.eans[]` dans la réponse du panier ; `data-flix-ean` sur la fiche. | M (forme) / I (contenu des `eans`) |
| B-4 | Contenu net : pastille « 1kg » et prix « 0,91 € / kg » affichés ; champ structuré inconnu. | M (affichage) / I (structure) |
| B-5 | Disponibilité : `offer.availability` (`AVAILABLE` vu), `offer.state` (`IMMEDIATE` vu), `offer.stockDetail.type` (`RT` vu), microdonnées `InStock` ; mention « Dans mon drive » sur la carte. | M (valeur disponible) / **I** (valeurs « indisponible », « bientôt ») |
| B-6 | Un `404` d'adresse inventée est un vrai `404` avec une page « Erreur 404 ». | M |
| B-7 | Pagination des résultats, tri, filtres. | I |
| B-8 | La recherche de certains termes redirige vers une page de produit ou de rayon (clé `searchRedirects` du stockage local). | S |

## Panier

| # | Fait | Statut |
|---|---|---|
| C-1 | `POST /cart/update`, JSON, `X-Requested-With: XMLHttpRequest`, aucun jeton d'en-tête ; corps `{cartId, items:[{productId, offerId, sellerType, desiredQuantity, desiredType, sellerId, id?}], consentId, reservationId: null, mbaAvailabilityNeeded}`. | M |
| C-2 | `desiredQuantity` est un **total absolu** ; `0` retire ; la réponse est le panier complet (`cart.items[]`, `cart.prices.totalPrice.amount` en **centimes**, `cart.version`). | M |
| C-3 | Visiteur : `cartId` = cookie `lark-cart`, `consentId` = cookie `lark-consentId` (lisibles par la page). Connecté : `lark-cart` disparaît ; `GET /cart?consentId=…` rend `cart.id`. | M |
| C-4 | `GET /cart` lit le panier sans écrire. | M |
| C-5 | Plafond de quantité : `offering.maxQuantity` (397 vu, lié au stock) ; message de dépassement. | M (champ) / I (message) |
| C-6 | Une écriture sans `id` de ligne sur une ligne existante : un essai appliqué (4), un essai sans effet (2, `200`). | M (incohérent) : **toujours envoyer l'`id`** et relire |
| C-7 | Codes d'erreur du site : `401`, `99001` à `99007`, `default`, `mba-notification`, `use-by-date-notification` (clés de `GET /cart/config`). | M (clés) / I (sens) |
| C-8 | Le même produit dans le panier du visiteur et dans celui du compte à la connexion : somme ou maximum. | I |
| C-9 | Les écritures concurrentes (en parallèle) sont tolérées. | I (**ne jamais en faire**, Leclerc a bloqué un client) |

## Anti-robot et connexion

| # | Fait | Statut |
|---|---|---|
| D-1 | DataDome : cookie `datadome`, balise JS, collecte `dd.ecom.auchan.fr/js/`, `x-datadome: protected` sur pages et API. | M |
| D-2 | Aucun défi visible sur une centaine de requêtes (15 écritures, 8 recherches à 2 s, création de compte, 2 connexions, 1 déconnexion). | M |
| D-3 | DataDome note les gestes (compteurs d'événements envoyés) : les appels depuis la page, **sans geste**, peuvent un jour être jugés autrement. | S |
| D-4 | reCAPTCHA Enterprise invisible à la connexion et à la création de compte ; aucun défi visible. | M |
| D-5 | Connexion : Keycloak (`compte.auchan.fr`), formulaire e-mail + mot de passe, « Se souvenir de moi », Apple, Google ; code d'autorisation de retour (`auth_callback`). | M |
| D-6 | `sessionStorage.tracking_authenticated` passe de `"false"` à `"true"` à la connexion. | M |
| D-7 | La connexion par e-mail et mot de passe, le reCAPTCHA et les cookies fonctionnent dans la WebView de l'application. | **I** (DRV-726) |
| D-8 | Apple et Google sont refusés en WebView. | S |
| D-9 | Cadence tolérée au-delà de 2 s entre requêtes ; comportement sur la durée. | I |

## Règles pour l'adaptateur

1. Ne jamais citer une valeur « I » ou « S » comme un fait dans le code, les tests ou les textes ; la marquer (« non mesuré »).
2. Jamais de HTML réel, de nom de magasin, de ville, d'identifiant, de cookie ni de jeton dans le dépôt : des formes et des valeurs inventées (`123456`, `Ville-Test`, UUID de test).
3. Les noms de cookies et de champs ci-dessus sont des **noms**, pas des secrets.
