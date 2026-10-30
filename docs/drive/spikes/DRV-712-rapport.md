# DRV-712 — Rapport du spike Auchan : parcours et magasin (U1 à U4)

> Fait le **2026-10-30** sur le téléphone de test (Pixel 6, Chrome mobile) par l'assistant, à la demande du propriétaire (qui a levé pour cette fois la règle « jamais par un agent, jamais depuis un ordinateur » des tickets DRV-712 à 714). Rapport complet et d'origine : [issue #322](https://github.com/kcharignon/repas/issues/322). Décision : [`DRV-715-decision-auchan.md`](DRV-715-decision-auchan.md). Aucun identifiant réel n'est écrit ici (formes et noms de champs seulement). Les valeurs sont **mesurées** sauf mention « non testé » ou « à confirmer ».

## Méthode et garde-fous

- Navigation **à la main** (touches sur le téléphone de test) pour tout le parcours ; ensuite quelques appels d'un seul `fetch` **depuis la page de l'onglet du téléphone** pour tester la mécanique de l'agent (lecture du panier, écritures, 8 recherches espacées de 2 s).
- Relevé des requêtes par le **débogage de Chrome du téléphone** (le trafic reste celui du téléphone, le site n'a jamais été ouvert dans un navigateur de l'ordinateur). Journal **rédigé** : noms de champs, méthodes, formes, jamais de valeurs d'identifiant ni de cookie.
- **Rien commandé, aucun créneau, rien payé.** Le panier a été rempli et vidé plusieurs fois, il est vide à la fin (visiteur et compte).
- **Un compte a été créé** avec l'accord et les données du propriétaire (voir « Connexion »), refus des offres par e-mail et par SMS, carte de fidélité **non créée**. Le mot de passe est généré et gardé hors dépôt.
- Une centaine de requêtes en une demi-heure, depuis l'adresse du domicile. Le navigateur d'un ordinateur n'a pas été utilisé : le téléphone a suffi, et un navigateur automatisé aurait été plus voyant pour DataDome.

## Parcours et magasin (DRV-712 : U1 à U4)

- **Accueil** : `auchan.fr/courses` sur un seul hôte `www.auchan.fr` (pas de `m.`). Un bandeau cookies s'affiche d'abord (« Continuer sans accepter » existe et suffit pour tout ce qui suit). Barre « Choisir vos courses » en haut, puis en-tête : Rayons, Recherche, Mes produits, panier avec total.
- **Choix du magasin** : on touche la barre, on saisit un code postal ou une ville (liste de suggestions), on choisit parmi des cartes. Quatre gestes. Le panneau s'appelle « Choisir un drive ou la livraison ».
- **Modes proposés** : « Tous / Drive / Livraison à domicile / + de filtres ». Les cartes sont de types **Livraison à domicile** (minimum de commande de 50 €), **Consigne entreprise** et **Drive**. Une fois choisi, la barre devient « Retrait : <nom du drive> » et un message confirme (« Vos courses seront maintenant à retirer au Drive de … »). Un panneau « Mon mode de course » permet de changer de magasin.
- **U1, où se lit le magasin** : **pas dans l'adresse** (elle ne change pas, `auchan.fr/courses` reste `auchan.fr/courses`) et **pas dans `localStorage`**. Il est gardé côté serveur par la session (cookie `connect.sid`, `HttpOnly`, **un jour**) ; il a survécu à l'ouverture d'un nouvel onglet **et à la connexion**, mais **la déconnexion l'efface** (la barre redit « Choisir vos courses » et il faut le rechoisir). L'agent peut lire **le libellé** dans la barre « Retrait : … ». Un identifiant de magasin stable existe très probablement dans la réponse du panier (`offer.context.seller` porte `id`, `name`, `type` et un objet `storeReference`) : **à creuser** avant de figer `Store`.
- **U2, mobile ou ordinateur** : même hôte, même adresses, une seule application adaptative ; le serveur déduit l'appareil (`x-ua-device: mobile`, paramètre `x-cms-ua-device` des appels de contenu). Le site ordinateur n'a pas été testé.
- **U3, modes** : le mode figure dans le panier (`context.deliveryChannel`). Que le stock diffère selon le mode (A9) **n'a pas été testé**.
- **U4, entrée** : pas de bouton « Commencer mes courses » sur l'accueil ; la page du panier vide (`/checkout/cart`) propose « Commencer mes achats » et le lien « connectez-vous pour retrouver vos produits précédemment ajoutés ».
- **Page introuvable** : une fiche inventée répond un **vrai `404`** (titre « 404 Not Found Page », « Erreur 404 : Cette page n'existe pas »), pas un `200` trompeur comme chez Leclerc (DRV-631).
- **Prix et photos** : les prix s'affichent **après** le choix d'un magasin (A3 confirmé) ; photos sur un autre domaine (`cdn.auchan.fr/media/…`, nom du genre `S12345678901G1GPRIMARY`).

## Compléments du même jour : lire le magasin sans rien mettre au panier (`GET /journey`)

Relevé après la rédaction de l'issue, pour fermer U1 (« où se lit le magasin ? ») :

- `GET /journey` (XHR, `X-Requested-With: XMLHttpRequest`, réponse JSON, `200`) rend le **parcours** de la session, **sans qu'aucun article soit au panier** : `{id, address{…}, location{longitude, latitude}, accuracy, activeContexts[], contextHistory[], metadata}`. `address` est la localité saisie par la personne (code postal, ville) : **donnée personnelle, à ne jamais mémoriser**.
- `activeContexts[]` : un élément par type, `type` ∈ `GROCERY` (le **drive choisi**), `ONLINE` (contexte `null` observé) et `STORE` (l'hypermarché rattaché, `channels` `SELF` et `PICK_UP`). Pour `GROCERY` : `channels: ["PICK_UP"]`, `seller{id, name, type, storeReference{id}, functionalReferences{storeId, warehouseId, warehouseUid, assortmentId, assortmentUid, discountId, pointOfSaleId, opDataReferentialId, mkpVendorId}}` et `pointOfService{id, name, type: "DRIVE", access, address, location, storeReference, url, enabled, open, openingSchedule, …}`.
- **Formes** : `seller.id` = **UUID** (36 caractères) ; `seller.storeReference.id` = nombre à **3 chiffres** pour un drive (2 chiffres pour l'hypermarché) ; `pointOfService.name` = le **libellé du drive** (celui de la barre « Retrait : … ») ; `pointOfService.url` = chemin de la page du magasin sur `www.auchan.fr`.
- **Égalités vérifiées** par essai : `journey.GROCERY.seller.id` = `offer.context.seller.id` de la réponse du panier ; `seller.storeReference.id` = celui du panier = `pointOfService.storeReference` ; `offerId` lu sur la carte de recherche (`data-offer-id`) = `offerId` de la ligne de panier.
- **Conséquence** : l'identifiant de magasin d'Auchan est `journey.GROCERY.seller.id` (UUID, 36 caractères), lisible **avant** tout ajout ; le libellé est `pointOfService.name`. Le mode est `channels` du contexte (`PICK_UP` pour un drive ; la livraison à domicile a ses propres contextes, **non relevés**).
