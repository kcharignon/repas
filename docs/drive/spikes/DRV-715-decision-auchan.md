# DRV-715 — Décision : Auchan, adaptateur complet

> **Décision du 2026-10-30 : GO pour un adaptateur Auchan complet** (pas de mode assisté, pas d'abandon). Prise par l'assistant d'après les rapports [DRV-712](DRV-712-rapport.md), [DRV-713](DRV-713-rapport.md) et [DRV-714](DRV-714-rapport.md), à la demande du propriétaire (« on va commencer à gérer Auchan »). Auchan reste **éteint** (`DRIVE_RETAILERS` ne le contient pas) jusqu'à la recette réelle (DRV-726), que seul l'humain déclenche. Même forme que [DRV-005](../tickets/DRV-005-synthese-decision-g1.md) (G1 de Leclerc).

## 1. Les preuves

| Question de DRV-715 | Réponse | Preuve |
|---|---|---|
| Le panier se remplit-il avec une **quantité absolue** ? | **Oui.** `POST /cart/update`, `desiredQuantity` est le total (ligne à 1, envoi de 3 : résultat 3, pas 4) ; `0` retire la ligne ; la réponse rend tout le panier. | [714](DRV-714-rapport.md) § Panier |
| Le panier se **lit-il** sans écrire ? | **Oui.** `GET /cart?consentId=…` (avec `cartId` quand on est visiteur, sans quand on est connecté) rend `cart.id`, les lignes, le total en centimes. | 714 § Panier |
| Le **magasin** se lit-il ? | **Oui, sans rien mettre au panier** : `GET /journey` → `activeContexts[type=GROCERY]` (identifiant UUID, libellé, canal `PICK_UP`). Il n'est **pas** dans l'adresse. | [712](DRV-712-rapport.md) § Compléments |
| L'**anti-robot** est-il surmontable dans la page de la personne ? | **Non observé** : DataDome est présent partout (cookie, balise, `x-datadome: protected`) et un reCAPTCHA Enterprise invisible garde la création de compte et la connexion, mais **aucun défi** sur une centaine de requêtes (15 écritures, 8 recherches espacées de 2 s, une création de compte, deux connexions). À lire « non observé », jamais « absent ». | [713](DRV-713-rapport.md) § Anti-robot |
| La **connexion** est-elle possible dans la WebView ? | **Non testé** (Chrome seulement : e-mail et mot de passe passent). Apple et Google probablement refusés en WebView. | 713 § Connexion ; **DRV-726** |
| Un **visiteur** peut-il remplir un panier ? Que devient-il à la connexion ? | Oui ; il est **fusionné** avec celui du compte (deux lignes de produits différents : les deux restent). La déconnexion vide le panier de visiteur et **efface le magasin**. | 714 § Panier ; 713 § Connexion |
| Identifiant de produit | **UUID** (`productId`) **et** UUID d'offre (`offerId`) ; pas numérique. | 714 § Recherche |
| Code-barres | Absent de la page de résultats ; présent dans la réponse du panier (`product.eans`) et sur la fiche (`data-flix-ean`). | 714 |
| Site mobile ou ordinateur | Un seul site adaptatif sur `www.auchan.fr` ; le serveur lit l'appareil (`x-ua-device`). Pas de mode ordinateur à imposer. | 712 |

## 2. La décision et sa forme

1. **Adaptateur complet.** Le contrat JSON du panier est propre, absolu, relisible : la mécanique de Leclerc se transpose (l'agent appelle les routes du site **depuis la page**, mêmes cookies, `X-Requested-With`, aucun jeton d'en-tête). Ce n'est **pas** un abandon et un mode assisté ne serait qu'un recul.
2. **`Store` d'Auchan = identifiant explicite lu par l'agent, pas une adresse.** `id` = `seller.id` du contexte `GROCERY` de `GET /journey` (UUID, **36 caractères**) ; `label` = `pointOfService.name` ; `baseUrl` = **constante d'enseigne** `https://www.auchan.fr/` (le magasin n'est pas dans l'adresse). Conséquences au back (**DRV-720**) : le profil d'enseigne dit d'où vient l'identifiant (`URL` pour Leclerc, `EXPLICIT` pour Auchan, avec son motif), `Store::create` ne le déduit plus de l'adresse quand il est explicite, et `Store::ID_MAX_LENGTH` passe de 20 à **40** avec la migration d'élargissement des trois colonnes `store_id` (`drive_settings`, `drive_product`, `drive_choice`). `drive_product.retailer_product_id` fait déjà 40 caractères : un UUID y tient. **La décision D-M7 (« `Store` non modifié avant le spike A1 ») est levée.**
3. **Un produit d'Auchan a deux identifiants** : `productId` (celui du produit, mémorisé comme `retailer_product_id`) et `offerId` (celui de l'offre dans le magasin, **nécessaire à l'ajout au panier**). L'offre se relit au prochain passage, mais la mémoriser évite une recherche : colonne facultative `drive_product.retailer_offer_id` et champ facultatif `offerId` du plan et du résultat (**DRV-721** back, **DRV-722** protocole, agent, web). Pour Leclerc : absent, rien ne change.
4. **Motifs d'identifiants** (registre, `productIdPattern`, `storeIdPattern`, `offerIdPattern`) : UUID `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`.
5. **Mode du site** : `AS_IS` (le site mobile convient, pas de mode ordinateur permanent). **Accueil de l'enseigne** : `https://www.auchan.fr/courses`.
6. **Domaines, et sécurité du pont (xhigh).** Navigation autorisée dans l'application : `www.auchan.fr` et `compte.auchan.fr` (connexion). **Le pont `repasHost` et l'agent ne sont injectés que sur `https://www.auchan.fr`**, **jamais** sur `compte.auchan.fr` (c'est la page du mot de passe : aucun code de Repas n'y vit). Les images (`cdn.auchan.fr`), le suivi et les appels d'API (`api.auchan.fr`, `dd.ecom.auchan.fr`, `google.com/recaptcha`) sont des sous-ressources de la page, pas des navigations ; leur traitement en WebView est à vérifier à la recette (DRV-726). Un plan d'Auchan n'est livré qu'à une page de `www.auchan.fr`.
7. **Anti-robot et cadence.** `antiBot: DATADOME` (plus un reCAPTCHA Enterprise à la connexion). Cadence de départ **non mesurée au-delà de ce qui a été essayé** : pause 2 000 ms, gigue 1 000 ms, plafond 4 s (essais : 8 recherches espacées de 2 s, écritures espacées de 2,5 s, tout `200`) ; à marquer comme telle dans le code. L'agent détecte le défi comme chez Leclerc (pause, bandeau, reprise) ; les marqueurs propres à DataDome sont ceux de Leclerc, ceux du reCAPTCHA s'ajoutent.
8. **Lire avant d'écrire, relire après.** Connecté, le cookie `lark-cart` n'existe plus : l'agent lit `GET /cart` pour prendre `cart.id`. Il envoie l'`id` de la ligne quand elle existe (un essai sans `id` sur une ligne existante n'a pas été appliqué : non élucidé). Quantité plancher et « jamais réduit » inchangés (la quantité est absolue : plus simple que chez Leclerc).
9. **Visiteur et magasin oublié.** `tracking_authenticated` (sessionStorage, `"false"`/`"true"`) dit si la personne est connectée ; une barre « Choisir vos courses » dit qu'**aucun magasin n'est choisi** (par exemple après une déconnexion) : l'agent s'arrête avec la cause déjà prévue (« choisis ton magasin »). Le panier de visiteur est fusionné à la connexion : le danger « visiteur » de Leclerc est moindre.
10. **Reprise par EAN** (DRV-606) : l'EAN d'Auchan n'est pas sur la page de résultats ; il vient de la réponse d'ajout au panier (`product.eans`). La phase A ne l'a donc pas ; il est mémorisé après l'ajout.
11. **Contenu net, prix** : prix en microdonnées (`price`, décimal en euros) et dans le panier (centimes) ; contenu net **affiché seulement** (« 1kg ») : à extraire du libellé et de la pastille comme chez Leclerc ; champ structuré non relevé.
12. **ADR 0027** : acceptée et amendée le même jour (décision 11 remplacée : forme du magasin d'Auchan, identifiant d'offre, origines du pont par enseigne).

## 3. Tickets écrits par cette décision

Ordre et dépendances (le découplage DRV-701 à 711 se poursuit en parallèle, seul DRV-716 les attend) :

| Ticket | Dépôt | Taille | Effort | Titre | Dépend de |
|---|---|---|---|---|---|
| [DRV-720](../tickets/DRV-720-magasin-a-identifiant-explicite.md) | back | M | xhigh | Magasin à identifiant explicite et plus long (migration, `Store`, profil) | 701 |
| [DRV-721](../tickets/DRV-721-identifiant-d-offre-du-produit.md) | back | M | high | Identifiant d'offre facultatif du produit (colonne, plan, contrat) | 701 |
| [DRV-722](../tickets/DRV-722-identifiant-d-offre-dans-le-protocole.md) | front (protocole, agent, web) | M | high | `offerId` facultatif dans le plan et le résultat | 704, 721 |
| [DRV-716](../tickets/DRV-716-declarer-auchan-dans-les-registres.md) | back | M | high | Déclarer Auchan dans le registre du back, éteint | 701, 702, 720, 721 |
| [DRV-727](../tickets/DRV-727-auchan-dans-les-registres-du-web-et-de-l-agent.md) | front (web, agent) | M | high | Auchan dans le registre du web et celui de l'agent, éteint | 704, 708, 716, 722 |
| [DRV-728](../tickets/DRV-728-auchan-dans-le-coeur-et-l-application-android.md) | front (android) | M | **xhigh** | Auchan dans le cœur et l'application Android (navigation, pont, injection) | 706, 707, 716 |
| [DRV-717](../tickets/DRV-717-adaptateur-auchan-squelette.md) | front (agent) | L | high | Faux site d'Auchan, magasin, session, entrée, diagnostic | 727, 722 |
| [DRV-724](../tickets/DRV-724-adaptateur-auchan-recherche.md) | front (agent) | M | high | Recherche et lecture des produits d'Auchan | 717 |
| [DRV-725](../tickets/DRV-725-adaptateur-auchan-panier.md) | front (agent) | M | high | Panier d'Auchan : quantité absolue, lecture, défi, cadence | 717 |
| [DRV-726](../tickets/DRV-726-recette-reelle-auchan.md) | **humain** | M | — | Recette réelle d'Auchan sur le téléphone (WebView, connexion, un envoi) | 728, 717, 724, 725, 707 à 710 |

Hors ordre : [`hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) dit ce qui est mesuré, supposé et inconnu ; chaque ticket s'y réfère et le met à jour.

## 4. Risques gardés ouverts

1. **La WebView** : formulaire Keycloak, reCAPTCHA Enterprise dont le score n'est pas celui de Chrome, cookies `lark-*` ; connexion Apple et Google probablement refusée. Si la connexion est impossible en WebView, la seule parade **dans le périmètre** est de laisser la personne se connecter dans le navigateur du téléphone puis de revenir (à étudier à la recette, pas avant). C'est le point qui peut encore changer cette décision.
2. **DataDome** réagit au comportement : la cadence se règle à la recette, pas avant.
3. **Disponibilité selon le mode** (livraison ou drive) et **valeurs « indisponible »** : non observées (DRV-724).
4. Un identifiant de magasin **stable dans le temps** : à surveiller à la recette (le magasin enregistré reste-t-il le même un jour plus tard ?).

## 5. Ce que cette décision ne change pas

Les invariants de l'ADR 0022 (aucun identifiant d'enseigne chez nous, le back ne contacte jamais une enseigne, l'agent ne valide ni ne paie, le panier n'est jamais vidé ni réduit) valent pour Auchan. Auchan est éteint par défaut. Rien d'Auchan n'est publié ni allumé sans l'humain ; le déploiement attend l'approbation du propriétaire.
