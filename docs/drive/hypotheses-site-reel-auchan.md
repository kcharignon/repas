# Auchan : ce qui est mesuré, supposé et inconnu

Pendant de [`hypotheses-site-reel.md`](hypotheses-site-reel.md) (Leclerc). Source : les spikes du 2026-10-30 ([DRV-712](spikes/DRV-712-rapport.md), [713](spikes/DRV-713-rapport.md), [714](spikes/DRV-714-rapport.md)) et la décision [DRV-715](spikes/DRV-715-decision-auchan.md). Chez Leclerc, plusieurs hypothèses se sont révélées fausses à la recette : **tout ce qui n'est pas « mesuré » est faux jusqu'à preuve du contraire**. Chaque ticket de l'adaptateur (DRV-717, 724, 725, 726) met ce fichier à jour.

Légende : **M** mesuré sur le vrai site (Chrome du téléphone de test, une session, un magasin, 2026-10-30) · **S** supposé (déduit, jamais vérifié) · **I** inconnu.

## Magasin et parcours

| # | Fait | Statut |
|---|---|---|
| A-1 | Le magasin n'est pas dans l'adresse (`www.auchan.fr/courses` reste identique) ni dans `localStorage` ; il est gardé par la session (cookie `connect.sid`, `HttpOnly`, un jour). | M |
| A-2 | `GET /journey` rend le parcours sans article au panier ; `activeContexts[type=GROCERY].context` porte `seller.id` (UUID), `seller.storeReference.id` (3 chiffres), `channels` (`PICK_UP`), `pointOfService.{name, type: "DRIVE", url}`. | M |
| A-2b | Forme de l'élément de `activeContexts[]` : `{type, context}`, avec `context.seller.id`, `context.pointOfService.name` et `context.channels` : **mesurée** dans la WebView de l'application le 2026-10-30 (l'agent 0.51.0 lit le magasin : hôte, identifiant UUID, libellé présent, retrait au drive). | M |
| A-3 | `seller.id` du parcours = `offer.context.seller.id` du panier. | M |
| A-4 | Le libellé du drive (`pointOfService.name`) est celui de la barre « Retrait : … ». | M |
| A-4b | L'élément de la barre du magasin : le texte est mesuré (« Choisir vos courses » sans magasin, « Retrait : … » avec), **pas la balise** ; l'agent cherche un élément dont tout le texte est « Choisir vos courses » ou qui commence par « Retrait : » ; barre « Choisir vos courses » alors que le parcours donne un magasin : refus prudent. | S |
| A-5 | La déconnexion efface le magasin (barre « Choisir vos courses »). | M |
| A-6 | La connexion conserve le magasin choisi en visiteur. | M |
| A-7 | L'identifiant de magasin reste le même d'un jour à l'autre. | I |
| A-8 | La livraison à domicile a d'autres contextes (`channels`, vendeur) ; les stocks y diffèrent de ceux du drive. | I |
| A-9 | Un panier se vide ou se recalcule quand on change de magasin ou de mode. | I |
| A-10 | Où mène « Commencer mes achats » (page du panier vide) ; l'agent ne touche que le libellé exact et ne suit un lien que s'il reste sur l'origine de la page. | I |

## Recherche et produits

| # | Fait | Statut |
|---|---|---|
| B-1 | `GET /recherche?text=<terme>` rend une page HTML complète (390 à 700 Ko), les produits dans le DOM : `data-product-id`, `data-offer-id`, `data-seller-type` (`GROCERY`), `data-offer-type` (`DEFAULT`), `data-seller-id` (vide) et microdonnées schema.org (`name`, `image`, `brand`, `offers`, `price`, `priceCurrency`, `priceValidUntil`, `availability`). | M |
| B-1b | Sur quel élément se trouve chaque attribut `data-*` et chaque microdonnée : **les sélecteurs de DRV-724 sont mesurés bons** (essai réel du 2026-10-30 : « sel » 15 produits, « farine » 27, « beurre » 32, « lait » 30, tous avec identifiant, libellé, prix, stock et contenu net lus). Reste inconnu : la forme de la photo (microdonnée `image`) et plusieurs `availability` par carte. | M / I |
| B-2 | `productId` et `offerId` sont des UUID ; l'adresse d'un produit est `/<libellé>/pr-C<7 chiffres>` (nombre sans rapport avec l'UUID). | M |
| B-3 | Pas d'EAN sur la page de résultats ; `product.eans[]` dans la réponse du panier ; `data-flix-ean` sur la fiche. | M (forme) / I (contenu des `eans`) |
| B-4 | Contenu net : pastille « 1kg » et prix « 0,91 € / kg » affichés ; champ structuré inconnu. | M (affichage) / I (structure) |
| B-5 | Disponibilité : `offer.availability` (`AVAILABLE` vu), `offer.state` (`IMMEDIATE` vu), `offer.stockDetail.type` (`RT` vu), microdonnées `InStock` ; mention « Dans mon drive » sur la carte. | M (valeur disponible) / **I** (valeurs « indisponible », « bientôt ») |
| B-5b | Un vendeur autre que `GROCERY`, une offre autre que `DEFAULT` (promotions, autres vendeurs) sont traités comme indisponibles ; un produit non clairement disponible donne `stock: 0` et la règle commune du passage le tente quand même une fois (le panier tranche). | S |
| B-6 | Un `404` d'adresse inventée est un vrai `404` avec une page « Erreur 404 ». | M |
| B-6b | Une page « introuvable » se reconnaît au code HTTP du document, à défaut à un titre commençant par « 404 » (une page vue) ; le reste de la page est supposé. | S |
| B-7 | Pagination des résultats, tri, filtres. | I |
| B-7b | Seule la première page de résultats est lue ; la page « aucun résultat » est supposée (barre du magasin et une phrase) ; une page « aucun résultat » qui montrerait des produits suggérés serait lue comme `SEARCH_PRODUCTS_UNREADABLE`. | S / I |
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
| C-10 | Le parent exact de `desiredQuantity` et `actualQuantity` dans la ligne de panier (l'adaptateur les cherche dans `cartItem` puis `offering`). | S |
| C-11 | `desiredType` à l'écriture, et le fait que le corps reprenne `data-seller-type`, `data-offer-type` et `data-seller-id` de la page de résultats (défauts du code : `GROCERY`, `DEFAULT`, vide). | S |
| C-12 | Une ligne présente au panier avec une quantité déjà supérieure ou égale n'est pas réécrite (jamais de réduction) ; plafond du code : 99. | Règle de l'adaptateur, non mesurée |
| D-10 | Un 403 DataDome sans marqueur `captcha-delivery.com` n'est pas reconnu comme un défi (retenté une fois comme chez Leclerc). | S |

## Anti-robot et connexion

| # | Fait | Statut |
|---|---|---|
| D-1 | DataDome : cookie `datadome`, balise JS, collecte `dd.ecom.auchan.fr/js/`, `x-datadome: protected` sur pages et API. | M |
| D-1b | Le cookie `datadome` est lisible par `document.cookie` (`HttpOnly` faux, mesuré sur le téléphone de test) : l'agent n'en lit que la présence, en oui/non. | M |
| D-2 | Aucun défi visible sur une centaine de requêtes (15 écritures, 8 recherches à 2 s, création de compte, 2 connexions, 1 déconnexion). | M |
| D-3 | DataDome note les gestes (compteurs d'événements envoyés) : les appels depuis la page, **sans geste**, peuvent un jour être jugés autrement. | S |
| D-4 | reCAPTCHA Enterprise invisible à la connexion et à la création de compte ; aucun défi visible. | M |
| D-4b | Le marqueur d'une page de défi DataDome est `captcha-delivery.com`, supposé identique à celui de Leclerc ; aucun marqueur du reCAPTCHA n'est écrit avant DRV-725. | S |
| D-5 | Connexion : Keycloak (`compte.auchan.fr`), formulaire e-mail + mot de passe, « Se souvenir de moi », Apple, Google ; code d'autorisation de retour (`auth_callback`). | M |
| D-6 | `sessionStorage.tracking_authenticated` passe de `"false"` à `"true"` à la connexion. | M |
| D-7 | Connexion dans la WebView de l'application (essai du 2026-10-30, APK de débogage) : le **formulaire Keycloak s'affiche et le reCAPTCHA invisible ne bloque pas** (aucun défi) ; sur un appareil inconnu, Auchan demande en plus un **code de vérification à 6 chiffres envoyé par e-mail** (10 minutes) : **non terminé** (une personne le saisit une fois par appareil) ; la session après ce code, les cookies et le retour dans l'application restent à voir. | M (formulaire, reCAPTCHA) / **I** (suite : DRV-726) |
| D-8 | Apple et Google sont refusés en WebView. | S |
| D-11 | Sur un appareil inconnu (profil de WebView neuf), la connexion par e-mail et mot de passe est suivie d'un **code à 6 chiffres envoyé par e-mail** (« Renvoyer un code », 10 minutes) ; Chrome, déjà connu, ne le demandait pas. | M |
| D-12 | **Le pont `repasHost` est absent de `compte.auchan.fr` et présent sur `www.auchan.fr`** dans la WebView réelle (règle de sécurité de DRV-728 prouvée sur le vrai site). | M |
| D-9 | Cadence tolérée au-delà de 2 s entre requêtes ; comportement sur la durée. | I |

## Règles pour l'adaptateur

1. Ne jamais citer une valeur « I » ou « S » comme un fait dans le code, les tests ou les textes ; la marquer (« non mesuré »).
2. Jamais de HTML réel, de nom de magasin, de ville, d'identifiant, de cookie ni de jeton dans le dépôt : des formes et des valeurs inventées (`123456`, `Ville-Test`, UUID de test).
3. Les noms de cookies et de champs ci-dessus sont des **noms**, pas des secrets.
