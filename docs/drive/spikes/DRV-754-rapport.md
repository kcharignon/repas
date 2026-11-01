# DRV-754 — La version mobile de Leclerc Drive : mesures du 2026-11-01

Exploration faite dans **Chrome** sur le téléphone de test (Pixel 6, Android 10, Chrome 156, page de 411 × 761 pixels CSS, ×2,6), avec les outils de développement de Chrome relayés par adb. Chrome permet les captures d'écran, ce que l'application Repas (`FLAG_SECURE`) interdit : c'est la première fois qu'on voit le vrai site mobile en entier. Magasin d'essai : Saint-Priest, n° 206911 (public). Aucune donnée personnelle, aucun cookie, aucun jeton dans ce rapport. **Le propriétaire impose la version mobile** (2026-11-01) : le drive ne fonctionne que sur téléphone, et si une action est impossible à toucher pour l'assistant, elle l'est pour l'utilisateur ; Auchan passe déjà par sa version mobile.

## 1. Le parcours, page par page

1. **`https://www.leclercdrive.fr/`** : page d'accueil adaptée au téléphone. Une fenêtre de cookies plein écran (« Continuer sans accepter → » en haut, « Paramétrer les cookies », « Tout accepter »), un bandeau qui propose l'application Leclerc (« OUVRIR », croix pour le fermer), « Se connecter », et un champ « Où souhaitez-vous récupérer vos courses ? » avec une icône de localisation.
2. Un **code postal** tapé dans ce champ (69720) fait apparaître une ligne de suggestion « Saint-Bonnet-de-Mure, Rhône (69720) » : il faut la toucher. Elle ouvre un panneau « Choisissez votre service autour du 69720 » : puces **Tous / Drive / Retrait piéton / Livraison à domicile**, puis une carte par magasin (commune, distance, services, **bouton rond à flèche**). Des cartes de 240 pixels de haut au moins : tout se touche au doigt.
3. La flèche ouvre la **présentation du magasin** (adresse `https://www.leclercdrive.fr/#_divMoc…`, un fragment sur la même page) : nom, services, adresse, horaires, **« Commencer mes courses »**.
4. « Commencer mes courses » mène à **`https://m-courses.leclercdrive.fr/magasin-206911-206911-saint-priest`** (sans `.aspx`) : une application web **Angular** (`ng-star-inserted`, `/030000002/main.*.js`). En-tête : menu, nom du magasin, pastille du panier et total ; champ « Produit, marque, … » ; « Mes produits habituels » ; rayons ; promotions. Le pied de page contient des liens vers l'hôte ordinateur du même magasin (`https://fd8-courses.leclercdrive.fr/magasin-206911-206911-Saint-Priest/plan-du-site`) : c'est de là que l'application tire l'hôte `fd<N>`.
5. **Recherche** : « Poireau » donne « 15 résultats affichés pour "Poireau" », des tuiles (photo, prix, prix au kilo, bouton « + » panier). Le « + » donne tout de suite un sélecteur « − 1 + ».
6. **Menu** (hamburger) : « Accueil », le magasin et sa date de livraison, **« Connexion / Inscription »**, « Mes outils », les rayons.
7. **Connexion** : « Connexion / Inscription » mène à la **création de compte** (`https://fd8-espace-client.leclercdrive.fr/drive/magasin-206911-206911-saint-priest/creation-compte-ccc.aspx?s=16000&v=&u=&r=…`) ; la page de **connexion** est `https://fd8-espace-client.leclercdrive.fr/drive/magasin-206911-206911-Saint-Priest/authentification.aspx?s=600…` (obtenue en demandant `…/mon-compte.aspx` sans être connecté : « Veuillez vous connecter pour accéder à cette page. »). Hôte **`fd<N>-espace-client`**, chemin **`/drive/magasin-N-N-Ville/authentification.aspx`**, très différent de `/connexion(.aspx)` de la version ordinateur. Deux temps : « Adresse email » puis « Continuer » ; ensuite « Mot de passe unique », « Rester connecté » et un **hCaptcha « Je suis un humain »** (une case à cocher). Le compte est le « compte unique E.Leclerc » (OIDC).

**hCaptcha** : un test anti-robot fait pour qu'un humain le passe. Ni l'agent ni l'assistant ne le contournent, ne le cochent ni ne le résolvent : la personne se connecte elle-même, comme le dit la carte « Connecte-toi » de DRV-751 (inchangée sur le fond).

## 2. Les appels réseau de la version mobile (formes seulement)

L'application Angular de `m-courses` parle au **même serveur que la version ordinateur**, l'hôte `fd<N>-courses` (ici `fd8`), en AJAX, depuis un autre hôte que le sien :

| Appel | Forme | Réponse |
|---|---|---|
| Suggestions | `POST https://fd8-courses.leclercdrive.fr/magasin-206911-206911/suggestion-recherche.aspx?ng-ajax=1`, corps `d={"sTexteRecherche":"sel"}` | JSON |
| **Recherche** | `POST …/recherche-ajax.aspx?TexteRecherche=Sel&idsupport=10&ng-ajax=1` (sans corps) | JSON |
| **Ajout au panier** | `POST …/panier.aspx?op=1&ng-ajax=1`, corps `d={"eTypeActio…` (du JSON dans un champ `d`, comme l'écriture du panier de l'agent actuel, `drive-agent/src/leclerc/cart.ts`) | JSON |
| Tris, marquage | `GET …/recuperertris.ashz?ng-ajax=1`, `GET …/Marquage.ashz?ng-ajax=1&niIdCanal=1&sNoPointLivraison=206911&iIdEvenementMarquage=1&sMotCleRecherche=Sel` | JSON |
| Photos | `fd8-photos.leclercdrive.fr` (`image.ashx`…) | images |

Depuis la page `m-courses`, **le rejeu fonctionne** : `fetch('https://fd8-courses.leclercdrive.fr/magasin-206911-206911/recherche-ajax.aspx?TexteRecherche=Poireau&idsupport=10&ng-ajax=1', {method:'POST', credentials:'include'})` répond 200 en JSON. En revanche **`GET …/recherche.aspx?TexteRecherche=poireau` (la requête de l'agent actuel) échoue depuis `m-courses`** (`TypeError: Failed to fetch`, interdit entre hôtes) et `GET …/panier.aspx` répond 404 (lisible). L'agent doit donc **parler comme l'application mobile**, pas comme la version ordinateur.

Forme de la réponse de **`recherche-ajax.aspx`** (clés, jamais de valeur personnelle) : `sTexteRecherche`, `iNbTotalProduits`, `lstFiltreBlocs[]`, `lstFiltresValeurs[]` (rayons, « En ce moment », marques… avec `iIdBloc`, `iIdValeur`, `sIdValeurUrl` du genre `4-324184`, `sLibelle`, `niNbReferences`), `lstProduits.lstElements[].objElement`, `lstProduitsMDD`, `objStickers`, `lstTrade`. Un produit (`objElement`) : `iIdProduit` (17313), `sNoPointLivraison` (« 206911 »), `iIdRayon`, `iIdFamille`, `niIdSousFamille`, `sLibelleLigne1` (nom, **avec des entités HTML** : `Poireaux coup&#233;s`), `sLibelleLigne2` (contenance, `500g`), `iQteDisponible`, `sPrixUnitaire` (« 3,99 € »), `nrPVUnitaireTTC` (3.99), `sPrixPromo`, `sPrixParUniteDeMesure` (« 7,98 € / kg »), `nrPVParUniteDeMesureTTC` (7.98), `iQuantitePanier`, `rTotalAPayer`, `eDisponibilite` (0), `sUrlVoirRayon`, `sUrlRemplacerProduit`, `sOrigineZoneDepeche`.

## 3. Autres constats

- **Le panier s'écrit sans être connecté** sur la version mobile (un article ajouté, pastille « 1 » et total 2,69 €, aucune connexion). La règle de l'agent reste : jamais de panier anonyme (ticket G de l'audit), la connexion est demandée avant.
- Le mur de cookies de la version mobile est une **fenêtre modale** : « Continuer sans accepter → » est un lien en haut, « Paramétrer les cookies » et « Tout accepter » des boutons en bas.
- Une page ordinateur `https://fd10-courses.leclercdrive.fr/magasin-…aspx?RedirectByUAFromMobile=1` existe : Leclerc renvoie par défaut un navigateur de téléphone vers `m-courses` ; le mode ordinateur de l'application (agent utilisateur + indications client) est ce qui force `fdN-courses` aujourd'hui.
- Le magasin choisi sur `www` est retenu par le site (un cookie) : la page `m-courses` s'ouvre directement sur ce magasin.
- **L'hôte de l'application Angular n'est pas l'hôte du serveur** : `m-courses` (pages) et `fd<N>-courses` (AJAX), `fd<N>-espace-client` (compte et connexion), `fd<N>-photos` (images). Le protocole v1 du plan porte déjà `store.host` (`fdN-courses`) : il reste l'hôte du serveur.

## 4. Ce qui reste à mesurer (faute de connexion automatisable)

La lecture du panier de la version mobile, l'état « connecté » d'un en-tête `m-courses`, la page qui suit la connexion (adresse de retour), « Mes produits habituels », la quantité absolue d'un ajout (`d` complet) et le refus d'un produit indisponible. Chaque point est à relever **par l'agent lui-même au diagnostic** (sondes de forme, DRV-752) ou par une recette où le propriétaire passe le hCaptcha.
