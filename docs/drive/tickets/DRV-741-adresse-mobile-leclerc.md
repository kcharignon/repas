# DRV-741 — Accepter l'adresse mobile d'un magasin Leclerc quand la personne la colle

- **Dépôt** : front (`src/features/drive/`, `android/`) ; le back et l'agent ne changent pas
- **Jalon** : M7 Plusieurs enseignes (suite de la recette réelle)
- **Taille** : S
- **Dépend de** : DRV-307, DRV-320, DRV-623, DRV-706
- **Statut** : en cours (PR front #273)
- **Lire d'abord** : [`../hypotheses-site-reel.md`](../hypotheses-site-reel.md) H11 ; [`../analyse.md`](../analyse.md) D25 ; [`../principes-produit.md`](../principes-produit.md).

## Contexte

Retour d'un proche du propriétaire (2026-10-30) : « Quand on te copie une adresse de Leclerc avec le mobile, il faut que tu l'acceptes et la convertisses dans le bon format (version ordinateur) ; sinon c'est trop complexe pour les utilisateurs qui copient/collent une adresse de magasin. »

**Fait mesuré le 2026-10-30 par l'assistant sur le téléphone de test (mode ordinateur)** : une adresse mobile du type `https://m-courses.leclercdrive.fr/magasin-N-N-ville` (valeurs inventées) **redirige** vers `https://fd<N>-courses.leclercdrive.fr/magasin-N-N-ville.aspx`. C'est Leclerc qui donne le bon hôte `fd<N>` (le numéro dépend du magasin : on ne peut pas le deviner) et qui ajoute `.aspx`.

Avant : la saisie à la main ne reconnaissait pas l'adresse avec ville (`magasin-N-N-ville` : refusée sans explication), et une adresse mobile sans ville (`/magasin-N-N`) était enregistrée avec l'hôte `m-courses…` : état corrompu (l'hôte du magasin doit être de la famille `fd<N>`, tous les plans suivants portent le mauvais hôte, H11).

## Parcours (touches)

- **Avant** : coller une adresse mobile ne menait à rien (refus sans explication) ou enregistrait un faux hôte.
- **Après, dans l'application** : coller l'adresse, toucher « Enregistrer mon magasin », puis « Utiliser ce magasin » sur la page du magasin que l'application ouvre : **un geste de plus** que pour une adresse ordinateur, et rien à comprendre.
- **Après, dans un navigateur** : message court, rien n'est enregistré : « Cette adresse est celle du site mobile. Ouvre-la dans l'application Repas, ou colle l'adresse du site sur ordinateur. »
- Une adresse au bon format (`fd<N>`) : **exactement** comme avant.

## Portée

1. **Web** (`retailers.ts`, `storeAddress.ts`, `StoreManualForm.vue`) : `Retailer.readMobileStoreAddress` (la règle de l'enseigne : hôte `m` ou `m-…`, chemin `/magasin-N-N[-ville][.aspx]`) et `parseMobileStoreAddress`. L'adresse mobile n'est **jamais** enregistrée. Dans l'application, l'écran des réglages la fait lire : `drive.setup` avec un `address` facultatif (l'adresse ramenée à `https://<hôte>/magasin-N-N[-ville]`, sans `.aspx`, requête, fragment ni suite). Hors de l'application : le message ci-dessus.
2. **Application Android** (`android/core`) : `drive.setup` accepte `address` ; `RetailerSite.openableStoreAddress` la **revérifie à la lettre** (ASCII visible, `https` sans port ni identifiants ni requête ni fragment, hôte de l'enseigne où le pont existe, chemin exactement le segment d'un magasin ; une enseigne sans pages de magasin n'en accepte aucune ; tout nom d'enseigne reste dans le registre). Elle devient la page de départ de la lecture du magasin à la place de l'accueil, en mode ordinateur ; Leclerc la redirige, l'agent lit le magasin sur la page finale (flux « Utiliser ce magasin ? »), et c'est cette lecture, avec le bon hôte, qui est enregistrée. Une adresse refusée : `INVALID_SETUP`. Gardée avec le passage pour la reprise. Pendant le chargement de cette adresse, le bandeau est celui de la lecture du magasin, pas « site mobile » ; si la page reste mobile, le bandeau habituel et « Ouvrir l'accueil du drive » (qui ouvre toujours l'accueil de l'enseigne).
3. **Agent et protocole : aucun changement** (le message `setup` de l'agent ne porte pas l'adresse ; pas de nouvelle version de l'agent ni de `android/agent.js.sha384`). Seul le message du pont natif `drive.setup` gagne un champ facultatif : une application plus ancienne l'ignore et ouvre l'accueil, comme avant.
4. **Faux site** (banc d'essai de l'émulateur) : le faux Leclerc sert `m-courses.leclercdrive.fr` et y redirige une adresse de magasin vers l'hôte du magasin (+ `.aspx`) ; scénario 1c de `make android-e2e`.

## Critères d'acceptation

- Tests unitaires web : adresse mobile reconnue avec/sans `.aspx`, avec/sans ville ; mauvaises adresses ; hors application (message, rien d'enregistré) ; dans l'application (`drive.setup` avec `address`, le magasin lu enregistré avec le bon hôte) ; adresse `fd<N>` inchangée.
- Tests Kotlin : `SetupAddressTest` (règle de l'adresse, refus hostiles, bandeau, reprise).
- Scénario d'émulateur 1c contre le faux site (adresse mobile redirigée vers l'hôte `fd` du faux site).

## Points laissés

- Une adresse du site ordinateur avec ville ou `.aspx` : traitée par [DRV-742](DRV-742-adresse-ordinateur-avec-ville.md).
- Non mesuré : si l'adresse mobile **sans ville** (`/magasin-N-N`) est redirigée par Leclerc comme celle avec ville. Sinon l'application montre le bandeau habituel du site mobile.
- Publication : l'application doit être republiée pour que la lecture à l'adresse marche (le site peut être publié avant : une ancienne application ouvre l'accueil). Ordre : front, puis application.
