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
| A-4c | **La première configuration attend le choix du magasin, sans écran bloquant** (DRV-740) : l'application envoie `setup` dès l'ouverture d'Auchan (l'adresse ne change jamais), avant que la personne ait choisi son magasin. Tant que la barre dit « Choisir vos courses », l'agent laisse la page entièrement utilisable sous un petit bandeau (« Choisis ton magasin sur Auchan. Repas le reprend ensuite. »), ne fait **aucune requête** (il ne lit que la barre, toutes les 2 s), puis lit le parcours **une seule fois** quand la barre dit « Retrait : … » et propose « Utiliser ce magasin ? ». Au bout de 15 minutes : « Réessayer » ou « Retourner dans Repas ». Un envoi réel (plan) sans magasin reste un arrêt avec son message. | S |
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
| B-5b | Une offre autre que `DEFAULT` (promotions) est traitée comme indisponible ; un produit non clairement disponible donne `stock: 0` et la règle commune du passage le tente quand même une fois (le panier tranche). **Un vendeur autre que `GROCERY` n'est plus « indisponible » : l'offre n'est pas du drive (B-9, DRV-739).** | S |
| B-6 | Un `404` d'adresse inventée est un vrai `404` avec une page « Erreur 404 ». | M |
| B-6b | Une page « introuvable » se reconnaît au code HTTP du document, à défaut à un titre commençant par « 404 » (une page vue) ; le reste de la page est supposé. | S |
| B-7 | Pagination des résultats, tri, filtres. | I |
| B-7b | La page « aucun résultat » est **mesurée** (essai réel du 2026-10-30) : `200`, non redirigée, titre « Votre recherche <terme> sur Auchan.fr », `h1` « Votre recherche : <terme> », la phrase « Oups, on n'a rien trouvé pour : », aucune carte produit ; sa barre de magasin n'est pas mesurée. Seule la première page de résultats est lue. | M / I (pagination) |
| B-8 | **Les termes courants redirigent vers une page de rayon** (mesuré le 2026-10-30 : `fetch` `200`, `redirected: true`, adresse finale `/<rayon>/<sous-rayons…>/ca-n<chiffres>`, titre « <Catégorie> pas cher - Auchan.fr », `h1` le nom de la catégorie) qui porte **les mêmes cartes** que `/recherche` (30, 30, 32 et 12 produits sur la première page pour riz, sucre, oeufs, poivre ; farine ne redirige pas : 27 produits) ; DRV-734 la lit comme une page de résultats. Une redirection vers une **fiche produit** (`/pr-C…`) n'a pas été vue : l'adaptateur la traite comme zéro produit. La clé `searchRedirects` du stockage local n'est pas mesurée. | M (rayon) / I (fiche) |
| B-9 | **Une offre n'est une offre du drive que si son vendeur est `GROCERY` ; `ONLINE` n'est pas du drive** (mesuré le 2026-10-30, recette réelle de l'envoi depuis Repas, WebView de l'application de débogage). Un même produit peut porter **plusieurs offres** (la page de résultats d'un thé glacé : un seul produit `data-product-id`, trois identifiants d'offre différents, tous `data-seller-type="ONLINE"`, le canal de livraison de la carte `SHIPPING`) ; certaines balises du produit (boutons) n'ont aucune offre ; la page de résultats d'un soda tonic (4 produits) a une offre `GROCERY` par produit et le canal `PICK_UP`. Ce que fait l'agent (DRV-739, 0.51.3) : les offres d'un vendeur connu autre que `GROCERY` sont ignorées AVANT de compter ; une seule offre du drive : le produit est lu avec elle ; aucune : le produit n'est pas du drive, il est écarté et compté à part (jamais « illisible ») ; plusieurs offres du drive différentes : ambigu, illisible ; une page dont tous les produits sont hors drive est sans résultat utilisable (la ligne est sans produit, l'envoi continue). **Non mesuré** : un produit qui porte à la fois une offre en ligne et une offre du drive (le prix et la disponibilité de la carte décrivent-ils l'offre du drive ?), les valeurs du vendeur autres que `GROCERY` et `ONLINE`. | M / I (produit mixte) |

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
| C-10 | Le parent exact de `desiredQuantity` et `actualQuantity` dans la ligne de panier : **mesuré** dans la WebView de l'application, connecté, le 2026-10-30 : `cart.items[].cartItem` porte `desiredQuantity` et `deltaQuantity`, et `cartItem.offering` porte `actualQuantity` et `maxQuantity` ; l'adaptateur les lit sans erreur (quatre lignes écrites, quantités 1, 1, 2, 1, relues telles quelles). | M |
| C-11 | `desiredType` à l'écriture et reprise de `data-seller-type`, `data-offer-type` et `data-seller-id` de la page de résultats : **mesuré** le 2026-10-30 (écriture réelle de l'adaptateur) : le corps `{productId, offerId, sellerType, desiredQuantity, desiredType, sellerId}` est accepté (`200`), la réponse rend `desiredType = DEFAULT` ; connecté, `cartId` = `cart.id` lu par `GET /cart` (pas le cookie `lark-cart`). | M |
| C-12 | Une ligne présente au panier avec une quantité déjà supérieure ou égale n'est pas réécrite (jamais de réduction) ; plafond du code : 99. | M (règle de l'adaptateur, **vue à l'œuvre** le 2026-10-30 : le même plan d'essai rejoué avec les mêmes choix = 4 requêtes, **aucune écriture**, panier inchangé à 12,60 €) |
| C-13 | Le « Plan d'essai (débogage) » joue, **connecté**, le choix guidé et l'écriture au panier d'Auchan de bout en bout (DRV-735) : issue `completed`, 13 requêtes, 0 erreur, 0 blocage, 4 lignes `CHOSEN` (farine 1, sucre 1, sel 2 pour 1 kg en paquets de 550 g, huile 1), total du panier relu à 12,60 € (= la somme des prix annoncés), aucun défi DataDome. Rejoué avec les mêmes choix : aucune écriture, aucun doublement (C-12). Il reste à jouer une vraie liste envoyée par Repas, avec des produits mémorisés (DRV-726). | M |
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
