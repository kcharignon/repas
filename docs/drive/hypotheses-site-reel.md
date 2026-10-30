# Hypothèses sur le vrai site de Leclerc : mesuré, supposé, inconnu

> **Ce fichier est celui de Leclerc.** Chaque enseigne a le sien (Auchan : [`hypotheses-site-reel-auchan.md`](hypotheses-site-reel-auchan.md), mesuré, supposé et inconnu d'après les spikes DRV-712 à 714). Ne jamais déduire un fait d'une enseigne de l'autre ; un fait non mesuré chez elle est « inconnu ».

Analyse faite le **2026-10-18** par un agent d'analyse, en **lecture seule** : documentation de ce dépôt (`docs/drive/`, spikes DRV-002 à DRV-011, `recette.md`) et code du dépôt front (`drive-agent/src/leclerc/`, `drive-agent/src/flow/`, `drive-agent/fake-leclerc/`, `drive-agent/fixtures/`, `android/`). Aucun contact avec Leclerc. Les exemples d'adresses sont inventés : `123456`, `Ville-Test`, `exemple.leclercdrive.fr`.

**Pourquoi ce fichier.** La recette réelle (DRV-505) a montré que l'application ouvrait la racine `https://<hôte>/` du drive au lieu de la page du magasin : l'agent affichait « Page non prise en charge ». DRV-319 avait posé, sans jamais le mesurer, que la racine mène à l'accueil du magasin (le faux site la redirigeait). La correction (repas-front#125, `DrivePlan.startUrl` ouvre `/magasin-<id>-<id>/`) remplace une hypothèse par une autre, elle aussi jamais mesurée (H1). Ce fichier cherche **les autres hypothèses de ce genre**, pour les vérifier pendant la recette au lieu de les rencontrer une à une.

**Ce qui a vraiment été mesuré (le périmètre sûr).** Un seul utilisateur, un seul magasin, **compte connecté**, magasin déjà choisi, mode ordinateur, un Pixel 9 (WebView 155), des **mots courants d'épicerie** (farine, sucre, sel…), des écritures lancées depuis une page de **recherche** ou d'accueil ouverte à la main. Tout ce qui s'en écarte (autre magasin, autre téléphone, visiteur non connecté, page d'ouverture choisie par l'application, fruits et légumes, mot rare, produit en rupture) est une **extrapolation**.

**Mise à jour du 2026-10-19.** La recette réelle (parties 3 à 8, 2026-10-18 et 19) a réfuté H1 (§ 8) et fait corriger une partie des défenses ci-dessous dans le dépôt front (PR #125 à #138, agents 0.9.0 à 0.15.0, [`roadmap.md`](roadmap.md) § M3c). **Le § 9 donne le statut de chaque hypothèse et de chaque ticket candidat** ; les § 1 à 7 gardent leur texte d'origine (historique), complété d'une ligne « Statut » pour les fiches touchées. « Défense livrée, à mesurer à la recette réelle » veut dire : le code existe et est testé contre le faux site, rien n'a encore été relevé sur le vrai site.

## Comment lire

- **Classe** : **(a)** MESURÉ sur le vrai site (spike et date cités) ; **(b)** SUPPOSÉ : seuls les tests, les fixtures ou le faux site le « prouvent », et ils sont écrits par nous ; **(c)** INCONNU : ni mesuré, ni même modélisé.
- **Risque** : **haut** = probable et le passage s'arrête, ou l'utilisateur est trompé sans le savoir ; **moyen** = plausible, ou gênant mais visible ; **bas** = improbable ou sans conséquence grave.
- Noms de fichiers : `drive-agent/…` et `android/…` sont dans le dépôt front (`kcharignon/repas-front`).
- « **Vérifier** » ne donne que des gestes sans risque pour le panier (lecture, ou un produit remis à zéro par la fonction de test) et des notes **sans donnée personnelle** : écrire une adresse sous la forme `/magasin-N-N[-V][/page]` (N pour un nombre, V pour la ville), jamais les vrais nombres ni la ville.

## 1. Les cinq à vérifier en premier pendant la recette

| # | Hypothèse | Pourquoi en premier | Quand (étape de `recette.md`) | Ce qu'il faut noter |
|---|---|---|---|---|
| 1 | **H1** Le site sert `/magasin-N-N/` (sans ville, sans nom de page) comme page de magasin | C'est la nouvelle ouverture d'un envoi : si elle est fausse, aucun envoi ne démarre | 6.3 « Ouvrir le drive » (et avant : 4.2) | Bandeau vert « Repas attend la page de ton magasin » visible ? Ce que montre la page (accueil du magasin, message d'erreur de Leclerc, accueil de l'enseigne, connexion) ? « Prêt à remplir ton panier » après combien de secondes ? Forme de l'adresse après chargement |
| 2 | **H2** La page « aucun résultat » du vrai site est reconnue | Un seul mot sans résultat arrête **tout** le passage avec un message faux (« Le site a changé ») ; des noms de référence mal écrits en produiront | Dernière ligne à choisir de la partie 6 (juste avant de la passer ou de la marquer « J'en ai toujours ») | Mot absurde cherché (`zzqxkw`) : « Aucun produit trouvé » ou « Le site a changé » ? |
| 3 | **H3** Un produit connu est retrouvé en cherchant son libellé complet | C'est le cœur du deuxième envoi (phase A) ; un échec passe pour une « rupture » | 7.3 « Ouvrir le drive : phase A » | Combien de lignes connues sortent « plus disponible » alors que le produit existe sur le site ? Le même libellé collé dans la recherche de Leclerc : trouvé, à quel rang ? |
| 4 | **H4** Un visiteur non connecté est détecté | Sans détection, l'agent remplit un panier **anonyme** et dit « Panier rempli » | Après la partie 11, avant le point 8 de la partie 12 (déconnexion) | Test automatique **déconnecté** : « Verdict : OK » (donc non détecté) ou « NOT_LOGGED_IN » ? Les produits sont-ils dans le panier de l'application officielle du même compte ? |
| 5 | **H5** Les écritures du panier réussissent depuis la page d'ouverture (autre page que celle mesurée) | Les 3 produits du test mesuré partaient d'une page de recherche ; un envoi part de l'accueil du magasin | 11.2 lancé **depuis l'accueil du magasin**, avant le premier envoi ; puis 6.4 | Verdict du test ; si échec, le code HTTP du rapport (nombre seul) |

## 2. Vue d'ensemble, par risque décroissant

| Id | Hypothèse | Classe | Risque | Étape |
|---|---|---|---|---|
| H1 | `/magasin-N-N/` est servi comme page de magasin | (b) | **haut** | 6.3 |
| H2 | La page « aucun résultat » est reconnue | (b) | **haut** | 6.4 |
| H3 | Un produit connu est retrouvé par son libellé complet | (b), (c) | **haut** | 7.3 |
| H4 | « Non connecté » est détecté (sinon : panier anonyme) | (c) | **haut** | 11 et 12 |
| H5 | Écritures du panier depuis l'accueil du magasin (Referer, contexte `uni-2`) | (b) | moyen | 11.2, 6.4 |
| H6 | Point de livraison `sNoPointLivraison` : valeur et source | (a) en partie, (b) | moyen | 11.1 |
| H7 | Accents, ligatures, singulier, noms mal écrits dans la requête | (b), (c) | moyen | 6.4 |
| H8 | Produits à poids variable (fruits, légumes, viande) : sens de la quantité | (c) | moyen | 6.4 (à changer) |
| H9 | Rupture, indisponible au point de retrait, limite d'achat, quantité réduite | (c) | moyen | 7.3, 8.1 |
| H10 | Choix du magasin sur le vrai site (lecture `setup`) | (b) | moyen | 4.2 |
| H11 | Le site mobile `m-courses` est accepté comme un magasin | (b) | moyen | 4.2, 11.1 |
| H12 | Un vrai passage (50 à plus de 200 requêtes) passe l'anti-robot | (b) | moyen | 7 |
| H13 | Défi anti-robot, 2FA ou session perdue en cours de route | (c) | moyen | 14 |
| H14 | Une quantité déjà au panier est écrasée (`inCart` jamais lu) | (b) | moyen | 7.3 |
| H15 | Aucune requête n'a de délai d'attente | (c) | moyen | 7 |
| H16 | Compte ou profil neuf : mode de retrait, créneau avant d'ajouter | (c) | bas | 4.2 |
| H17 | Liens ouverts dans le navigateur du téléphone (Finaliser, mode assisté) | (b) | bas | 8.3 |
| H18 | La connexion reste sur `leclercdrive.fr` | (a) en partie | bas | 4.2 |
| H19 | Le second nombre de `/magasin-N-N` égale le premier | (c) | bas | 6.3 |
| H20 | L'hôte `fdN` d'un magasin est stable dans le temps | (c) | bas | surveillance |
| H21 | Pagination et nombre de résultats | (c) | bas | 11.1 |
| H22 | Bandeaux de cookies et fenêtres modales | (c) | bas | 4.2 |

## 3. Fiches des hypothèses supposées ou inconnues (b) et (c)

### H1. `/magasin-N-N/` est servi comme page de magasin (b, risque haut)

- **Mesuré autour** : les pages portent le suffixe de ville (`/magasin-123456-123456-Ville-Test/recherche.aspx`, DRV-002 et DRV-011) ; le panier se poste sur `/magasin-123456-123456/panier.aspx?op=1` **sans** ville (DRV-007) ; la racine `https://<hôte>/` n'est **pas** un magasin (recette réelle du 2026-10-18). **Jamais mesuré** : un `GET` de `/magasin-123456-123456/` (barre finale, sans ville ni nom de page).
- **Où** : `android/…/DrivePlan.kt` (`startUrl`), `DriveActivity.kt` (`onCreate`, `sendPlan` : le plan ne part que si `NavigationPolicy.isStorePage(webView.url)`), `StoreBanner`. Déjà noté « reste à confirmer » dans `android/README.md`.
- **Si c'est faux** : (i) page d'erreur générique du site (« service momentanément indisponible », statut 404) : `LoadProblems` ne traite que les 5xx, l'application ne la signale pas et le bandeau suppose que l'utilisateur peut « ouvrir son magasin » depuis cette page ; (ii) redirection vers la racine ou la connexion : même bandeau, rien à toucher ; (iii) page servie mais à une adresse hors de la forme `/magasin-N-N…` : le plan n'est jamais envoyé. L'agent reste muet dans les trois cas.
- **Vérifier** : en 6.3, noter ce que montre la page et si le bandeau est visible (voir tableau du § 1). Pour la forme de l'adresse : menu **⋮** → « Ouvrir dans le navigateur », lire la barre d'adresse, noter `/magasin-N-N[-V][/page]`.
- **Défense** : tickets E (repli et message clair) et F (le plan porte l'adresse que le site sert vraiment).
- **Statut (2026-10-19) : RÉFUTÉE.** `/magasin-N-N/` sans ville est une **page 404** du vrai site, comme la racine de l'hôte du magasin (§ 8). Corrigé : un plan s'ouvre sur l'accueil de l'enseigne `https://www.leclercdrive.fr/` (`NavigationPolicy.START_URL`, PR front #132, `android-v0.1.4`), après deux adresses fausses (#125 puis #128). Le repli du ticket E (bandeau, bouton « Ouvrir l'accueil du drive ») est livré (PR #128). Le ticket F (le plan porte l'adresse d'entrée) n'est pas fait.

### H2. La page « aucun résultat » est reconnue (b, risque haut)

- **Où** : `drive-agent/src/leclerc/adapter.ts` (`NO_RESULT`, `searchProducts`) ; la page de référence est **inventée** : `fixtures/leclerc/recherche-vide.html` et `fake-leclerc/pages.ts` (« Aucun résultat pour votre recherche »). Le texte du vrai site n'a jamais été relevé. Le test se fait sur le texte brut (`reply.text`), sans décoder `&quot;`.
- **Si c'est faux** : réponse 200 sans produit et sans texte reconnu → `CONTRACT_CHANGED`, message « Le site a changé. Mets à jour l'application » (conseil faux). En phase A, `absorb` marque la ligne en échec et **arrête** le passage ; en phase B, `load()` relance l'erreur et le passage s'arrête aussi. Les ingrédients de référence contiennent des noms qui n'auront aucun résultat (« betternut », « bettrave », « bn » dans `ingredient.json`) : le cas arrivera dès qu'un de ces ingrédients sera sur une liste.
- **Mesuré** : 12 recherches sur 12 avec résultats (selftest, 2026-10-18) ; aucune recherche à zéro résultat n'a jamais été jouée sur le vrai site.
- **Vérifier** : à la dernière ligne à choisir de la partie 6, toucher « Chercher autre chose », taper `zzqxkw`, « Chercher ». Une seule fois (lecture seule). Attendu : « Aucun produit trouvé ». Si « Le site a changé » s'affiche, le passage s'arrête : c'est justement la preuve ; ne pas insister.
- **Défense** : ticket C (reconnaître « aucun résultat » par la structure de la page), A (cause fine) et B (sonde « recherche sans résultat »).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket C (PR front #127, agent 0.9.1, `android-v0.1.2`) : une réponse 200 sans produit lisible, au squelette du magasin et sans trace de produit, est une liste vide et n'est plus jamais `CONTRACT_CHANGED` ; le texte seul ne suffit plus. Tickets A (cause fine, même PR) et B (sonde « recherche du mot absurde `zzqxkw` » du diagnostic, PR #129, agent 0.10.0). Reste à relever sur le vrai site : la forme réelle d'une page sans résultat (le diagnostic écrit « liste vide reconnue »).

### H3. Un produit connu est retrouvé en cherchant son libellé complet (b, c ; risque haut)

- **Où** : `drive-agent/src/flow/phase-a.ts` (`settleKnownLine` appelle `readProductFromSearch(product.label, product.id)`), `phase-b.ts` (`labelOf` : première ligne + seconde ligne, jusqu'à 255 caractères). Le test automatique ne l'exerce **jamais** (il cherche des mots seuls). Une seule mesure : DRV-002 ligne 19 (2026-10-17, PC), un produit « farine » retrouvé au rang 1.
- **Inconnu** : le moteur de recherche accepte-t-il 8 à 15 mots, des quantités (« 1 kg », « 6 x 33 cl »), `%`, `&`, apostrophes ? Le produit revient-il parmi les résultats embarqués (nombre limité, voir H21) ?
- **Si c'est faux** : soit H2 (arrêt du passage), soit le produit est déclaré « plus disponible » à tort : l'utilisateur voit « Ces produits ne sont plus disponibles » et rechoisit, la case « Ne plus jamais prendre » devient dangereuse. Le plan porte pourtant un `searchQuery` (nom de l'ingrédient) qui n'est pas utilisé en repli.
- **Vérifier** : au deuxième envoi (7.3), noter combien de lignes connues sortent « plus disponible » ; pour chacune, coller son libellé dans la recherche de Leclerc (hors envoi) : trouvé ? à quel rang ? Un échec avec produit visible = H3 confirmée.
- **Défense** : ticket D (requêtes de repli : libellé complet, première ligne seule, nom de l'ingrédient ; « introuvable » n'est jamais `CONTRACT_CHANGED`).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket D (PR front #130, agent 0.11.0, `android-v0.1.4`) : en phase A, les formes dérivées du libellé sont essayées avant « plus disponible » ; en phase B, celles du nom. Coût : jusqu'à 4 recherches de plus pour un produit vraiment disparu. À noter (fiche § 7 ligne 6) : les lignes connues sorties « plus disponible » alors que le produit existe.

### H4. « Non connecté » est détecté (c, risque haut)

- **Où** : `adapter.ts` (`assertUsable` : `NOT_LOGGED_IN` seulement pour un 401 ou une redirection dont l'adresse contient `connexion|identif|login|authent`) ; le faux site ne simule que cette redirection (`fault: logged-out`). DRV-002 et DRV-011 ont tous deux été faits **compte connecté**.
- **Si c'est faux** : si le site laisse un visiteur chercher (probable : le catalogue est public) et ajouter au panier, l'agent remplit un panier **anonyme** : tout est « ajouté », le récapitulatif est vert, et le panier du compte reste vide (ou perd son contenu à la connexion). C'est l'échec silencieux le plus coûteux. Déclencheurs réels : session expirée (durée de vie inconnue ; une seule réouverture mesurée, DRV-005), profil du drive effacé, cookie d'anti-robot renouvelé.
- **Vérifier** : (1) en fin de recette, **déconnecté** du site (lien de déconnexion de Leclerc s'il y en a un, pas « effacer les données »), lancer le test automatique depuis une page de magasin : « NOT_LOGGED_IN » (bien) ou « Verdict : OK » (non détecté ; sans danger : panier anonyme remis à zéro) ; (2) à l'étape 8.3, ouvrir le panier dans l'**application officielle** de Leclerc (autre appareil de session) : les produits y sont-ils ? L'étape 8.1 seule ne prouve rien, c'est la même session.
- **Défense** : ticket G (marqueur de connexion lu dans la page, contrôle avant « Commencer »), après la sonde B qui découvre le marqueur.
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket G (PR front #127, agent 0.9.1) : visiteur = au moins deux signes cumulés et aucun signe de client ; affiné par la PR #129 (agent 0.10.0) : le lien « Se connecter » est un signe faible (le site le garde pour un client connecté, § 8) et l'état indéterminé vaut « connecté ». Les libellés de visiteur restent des hypothèses plausibles ; le diagnostic écrit « Connexion : … (signes : …) » (des noms seulement) pour les confirmer. À faire : le test déconnecté (fiche § 7 ligne 10).

### H5. Les écritures du panier réussissent depuis la page d'ouverture (b, risque moyen)

- **Où** : `drive-agent/src/leclerc/cart.ts` (`cartPayload`) et `adapter.ts` (`setCartQuantity`). La requête reproduit celle de DRV-007 : en-tête `zcwb`, adresse sans ville, identifiant en texte, contexte `{eOrigine:4, eTypePage:3, sTexteRecherche, eVue:0, sInformationsComplementaires:'uni-2'}`. Cette requête **partait de la page de recherche** (Referer relevé). Un envoi part de l'accueil du magasin, avec `eTypePage: 3` (« recherche ») sur un Referer d'accueil, et `uni-2` copié d'un seul produit d'épicerie (sa signification, « univers 2 », est une supposition de DRV-007).
- **Si c'est faux** : 404 générique → `CONTRACT_CHANGED` au premier ajout (partie 6.4), ou seulement pour certains rayons (frais, surgelé) : l'échec est alors incompréhensible.
- **Vérifier** : lancer « Test automatique (débogage) » **depuis l'accueil du magasin** (sans recherche préalable), avant le premier envoi. Noter le verdict. Dans les envois, noter les rayons des produits ajoutés (épicerie seulement ? frais ? surgelé ?).
- **Défense** : ticket H (fixer le `referrer` de la requête sur la dernière page de recherche ; en cas de 404, une reprise sans contexte ; noter laquelle a marché).

### H6. Point de livraison `sNoPointLivraison` : valeur et source (a en partie, b)

- **Mesuré** : le champ est dans la page de l'accueil du magasin (PC) et dans la page de recherche (téléphone, mode ordinateur), absent en mode mobile (DRV-002 ligne 3 ; DRV-011 ligne 5). DRV-002 note « même valeur » sans dire de quoi (le numéro de l'adresse ?).
- **Contradiction** : `fake-leclerc/README.md` affirme que le point de livraison est « différent de l'identifiant du magasin, **comme sur le vrai site** » ; aucun spike ne l'établit, et DRV-002 laisse croire le contraire.
- **Où** : `drive-agent/src/leclerc/store.ts` (`readDeliveryPoint` : lit le texte de la page **courante**, à défaut le premier nombre de l'adresse) et `adapter.ts` (`deliveryPoint ??=` : la première lecture est gardée tout le passage). Aucun test de bout en bout n'exerce ce repli : la page du faux site contient toujours le champ.
- **Si c'est faux** : un point inexact (repli, ou page sans le champ comme la page de connexion) donne une erreur ou un ajout au mauvais point, **pour tout le passage**.
- **Vérifier** : une ligne de sonde, sans valeur : « lu dans la page : oui/non ; identique au numéro de l'adresse : oui/non ». Sans elle, déduire de H5 : si le test automatique réussit depuis l'accueil, la lecture est bonne.
- **Défense** : tickets B (sonde) et H (lire le champ dans la dernière réponse de recherche avant le DOM ; ne jamais retomber en silence sur le numéro de l'adresse).

### H7. Accents, ligatures, singulier, noms mal écrits dans la requête (b, c ; risque moyen)

- **Mesuré** : « pâtes », « huile d'olive », « oeufs » (ASCII, pluriel), « bananes », « tomates » : résultats non vides (selftest, 2026-10-18).
- **Où** : le plan envoie `searchQuery = ingredientName` brut (`DrivePlanPostgreSQLLoader`, `src/Drive/Infrastructure/Loader/`). Les ingrédients de référence contiennent « œuf » et « bœuf » (ligature, singulier), « aubergine », « banane » (singulier), des noms mal écrits (« betternut », « bettrave », « boudoire ») et une abréviation (« bn »).
- **Si c'est faux** : zéro résultat → H2 (arrêt) ou « Aucun produit trouvé » (l'utilisateur retape : défendu par « Chercher autre chose »).
- **Vérifier** : en 6.4, pour chaque ligne, noter si la première recherche était vide (oui/non) et le nom de l'ingrédient (les noms d'ingrédients ne sont pas sensibles).
- **Défense** : ticket D (normaliser : minuscules, `œ`→`oe`, `’`→`'`, second essai sans accents).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket D (PR front #130, agent 0.11.0) : normalisation et second essai sans accents, 4 formes au plus. La liste des mots de liaison et le pluriel du premier mot ne sont pas mesurés (fiche § 7 ligne 5).

### H8. Produits à poids variable : sens de la quantité (c, risque moyen)

- **Où** : `cart.ts` (`iQuantite` entier de 1 à 99) ; `content.ts` renvoie `null` pour « environ », « vrac », « selon » ; phase B pose alors « Vérifie la quantité ». `lstProduitsLight` expose `fQuantiteMax` (nombre décimal), signe que des quantités non entières existent.
- **Mesuré** : rien. La recherche trouve mal les fruits et légumes (7 échecs sur 30, DRV-002 ligne 18), donc ils passent par le choix manuel ; la recette 6.4 les fait **passer** (I5), donc ils ne sont jamais essayés.
- **Si c'est faux** : « 3 » peut signifier 3 pièces, 3 kilos ou 3 × 100 g : le panier contient une quantité fausse **sans erreur** (`obtained === sent`).
- **Vérifier** : en phase B, choisir **un** fruit ou légume (quantité 1) au lieu de le passer ; ouvrir le panier ; noter l'unité de la ligne (pièce, kilo, grammes) et si le prix de la ligne est celui attendu ; retirer à la main.
- **Défense** : ticket I (repérer le « vendu au poids », contrôler `rTotalAPayer` contre prix × quantité, avertir).

### H9. Rupture, indisponible au point de retrait, limite d'achat, quantité réduite (c, risque moyen)

- **Où** : `phase-a.ts` (`seen === null || seen.stock === 0` = indisponible ; un stock absent compte comme disponible), `adapter.ts` (`sent = min(demandé, 99, stock)`, `ok` jamais lu par le passage), `cart.ts` (`readCartResponse` lève `CONTRACT_CHANGED` sans évènement `Panier`).
- **Mesuré** : « stock 520 » pour un produit courant ; rien pour un stock à 0. DRV-007 liste d'autres champs de disponibilité (`eDisponibilite`, `fAfficherTexteProduitIndispo`, `iNbProduitIndisponible`) que l'agent **ne lit pas**.
- **Si c'est faux** : (i) produit indisponible dont `iQteDisponible` est > 0 : le site répond sans évènement panier → `CONTRACT_CHANGED` et arrêt ; (ii) limite d'achat : même cas ; (iii) quantité plafonnée au stock : la ligne est « ajoutée » avec moins que prévu, **sans avertissement** (le récapitulatif affiche la quantité obtenue sans la comparer à la quantité voulue).
- **Vérifier** : en 7.3 et 8.1, comparer la quantité de chaque ligne du récapitulatif avec la quantité du plan (« 2 produits ») ; noter tout écart. Si un produit grisé « Indisponible » est connu sur le site, le chercher dans l'agent et noter s'il est aussi marqué « En rupture ».
- **Défense** : ticket I (lire les champs de disponibilité, avertir d'une quantité réduite) ; A (cause fine) ; B (sonde des champs de disponibilité, valeurs seulement).

### H10. Choix du magasin sur le vrai site, lecture `setup` (b, risque moyen)

- **Déjà signalé** par `recette.md` partie 4 : seul le faux site a joué ce parcours. **Mesuré** : l'accueil `https://www.leclercdrive.fr/` s'affiche dans la WebView, la connexion et le choix du magasin se font à la main, sans site confié au navigateur (DRV-011 lignes 2, 3, 12, 2026-10-17). **Non mesuré** : l'adresse d'arrivée après le choix (est-elle de la forme `/magasin-N-N…` ?), l'ergonomie de l'écran de choix en mode ordinateur sur 412 px, l'envoi du message `setup` à ce moment (`DriveActivity.sendPlan`).
- **Où** : `NavigationPolicy.START_URL`, `SetupRequest.startUrl`, `isStorePage`, `drive-agent/src/flow/setup.ts` (le libellé du magasin n'est jamais lu : `readLabel` n'est pas branché, l'écran affiche « magasin n° … »).
- **Si c'est faux** : aucun message de l'agent après le choix ; l'utilisateur n'a que le bandeau « Connecte-toi… puis choisis ton magasin ».
- **Vérifier** : en 4.2, noter la forme de l'adresse d'arrivée (voir H1), le temps jusqu'à « Enregistrer ce magasin dans Repas ? », et si l'écran de choix était utilisable au doigt.
- **Défense** : ticket E (repli et message clair).

### H11. Le site mobile `m-courses` est accepté comme un magasin (b, risque moyen)

- **Mesuré** : sans mode ordinateur, Leclerc sert `m-courses.leclercdrive.fr` (routes autres, `recherche.aspx` répond 200 sans produit) ; avec le mode ordinateur (agent utilisateur + indications client), `fdN-courses` sur ce Pixel 9 (DRV-011 lignes 2, 5, 6 ; 2026-10-17). On ne sait pas si l'agent utilisateur seul suffit (« non testé », ligne 11), donc pour un autre téléphone ou une WebView sans indications client (`UserAgents.identity` : « agent utilisateur seul »).
- **Où** : `drive-agent/protocol/check.ts` (`HOST = ^[a-z0-9-]+\.leclercdrive\.fr$`), `android/…/DrivePlan.kt` (même règle) : `m-courses` est accepté. Seul le diagnostic classe `fdN` (`hostFamily`).
- **Si c'est faux** : à la **lecture du magasin**, l'agent enregistre l'hôte `m-courses…` comme celui du magasin (le back valide `[a-z0-9-]+` : état corrompu, tous les plans suivants portent ce faux hôte) ; à un **envoi**, `STORE_MISMATCH` (« Ce n'est pas le bon magasin ») ou `CONTRACT_CHANGED` à la première recherche. Le `runbook.md` § 1 annonce « Page non prise en charge » pour ce cas : c'est inexact.
- **Vérifier** : au diagnostic (11.1), la ligne « Page prise en charge » doit dire une famille `fdN`, jamais « autre hôte leclercdrive.fr » (le texte du diagnostic pour un hôte hors `fdN`, donc aussi `m-courses`).
- **Défense** : ticket J (n'accepter que le site ordinateur, message dédié, « mode ordinateur appliqué : oui/non » dans « Version et mode d'emploi »).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket J (PR front #137, agent 0.14.0) : l'agent refuse le site mobile tout de suite (`UNSUPPORTED_PAGE`, cause `PAGE_MOBILE`) et l'application ne lui envoie ni plan ni lecture du magasin (bandeau « Leclerc affiche son site mobile »). La ligne « mode ordinateur appliqué : oui/non » de « Version et mode d'emploi » n'a pas été ajoutée. Pas encore dans une application publiée.

### H12. Un vrai passage passe l'anti-robot (b, risque moyen)

- **Mesuré** : une quarantaine de requêtes en série sur PC (DRV-002 ligne 6) ; un lot de 30 recherches sur téléphone (DRV-005) ; 12 recherches et 3 produits en 41 s, sans blocage (2026-10-18). **Pas de DataDome simulé** : le faux site ne le prouve pas.
- **Estimation** : 25 lignes connues × (1 à 2 recherches + 1 écriture) font 50 à 75 requêtes en 2 à 3 minutes ; 100 lignes, plus de 200. Aucune mesure au-delà de 40.
- **Si c'est faux** : `BLOCKED` après deux 403/429 de suite (prévu, message correct), mais le seuil réel et son effet sur le compte (CGU, DRV-003) sont inconnus.
- **Vérifier** : la recette ne note ni le nombre de requêtes ni la durée par phase. Noter : nombre de lignes, durée de chaque passage, première lenteur éventuelle.
- **Défense** : ticket L (compteurs d'un passage, respect d'un `Retry-After`, cache des recherches identiques dans un passage).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket L (PR front #138, agent 0.15.0) : compteurs `stats` du résultat et du diagnostic, cadence adaptative (seuils non mesurés, `architecture.md` § 8). Non faits : respecter un `Retry-After`, ne pas rejouer une recherche identique dans le même passage, compteurs dans le test automatique. Pas encore dans une application publiée ; **le front doit être déployé avant elle** (`architecture.md` § 12).

### H13. Défi anti-robot, 2FA ou session perdue en cours de route (c, risque moyen)

- **Où** : `pacer.ts` (`isChallenge` = texte contenant `captcha-delivery.com` ; 403/429 retentés une fois puis `BLOCKED`), `DriveActivity.kt` (`onReceivedHttpError` ignore les 403 : le défi s'affiche tel quel).
- **Inconnu** : la forme réelle d'un défi reçu par `fetch` (page, JSON avec une adresse de défi ?), la 2FA (code SMS ou courriel) pendant la connexion dans la WebView, la reprise après résolution (le plan est renvoyé au rechargement et l'état du passage est dans `sessionStorage`, prévu mais jamais joué sur le vrai site).
- **Si c'est faux** : `BLOCKED` avec le conseil « Attends quelques minutes… » alors qu'il suffit de recharger la page et de résoudre le défi ; un défi plein écran masque l'interface de Repas.
- **Vérifier** : ne pas provoquer. Si un défi ou un code de vérification apparaît, noter à quel moment (étape), le résoudre, et noter si l'agent propose « Reprendre où tu t'étais arrêté ? ».
- **Défense** : tickets K (message distinct pour le défi, avec « recharger la page ») et L.
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket K (PR front #136, agent 0.13.0) : `CHALLENGE` dès la première réponse, passage suspendu derrière un bandeau « Leclerc demande une vérification » (Reprendre / Arrêter) ; côté application, un 403 ou 429 sur la page d'ouverture laisse la page visible avec le même titre de bandeau (PR #128). La forme réelle du défi reçu par `fetch` n'est pas mesurée. Ticket L livré aussi (PR #138). Pas encore dans une application publiée.

### H14. Une quantité déjà au panier est écrasée (b, risque moyen)

- **Où** : `phase-a.ts` / `adapter.ts` : `inCart` (`iQuantitePanier` de la recherche) est lu puis **ignoré** ; la quantité est absolue. Écart avec l'invariant « le panier n'est jamais vidé **ni réduit** » (ADR 0022 règle 9, `architecture.md` § 2 point 7), que seul « l'agent ne fixe que la quantité des produits du plan » nuance.
- **Si c'est faux** (c'est un défaut de conception plus qu'une hypothèse du site) : un produit du plan déjà au panier avec une quantité supérieure (ajout à la main, envoi précédent) est ramené à la quantité du plan, sans avertissement.
- **Vérifier** : avant le deuxième envoi, ajouter à la main **un** exemplaire en plus d'un produit connu (I1), puis noter après l'envoi si sa quantité est revenue à celle du plan. Remettre à la main.
- **Défense** : ticket M (décision du PO : garder la plus grande quantité, ou demander).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket M (PR front #135, agent 0.12.0) : la quantité du plan est un plancher, l'agent n'écrit que si elle dépasse le panier (écart au libellé du ticket : il n'écrit rien quand le panier a déjà autant ou plus). À noter : une ligne « Quantité au panier illisible » si elle apparaît (fiche § 7 ligne 9). Pas encore dans une application publiée.

### H15. Aucune requête n'a de délai d'attente (c, risque moyen)

- **Où** : `pacer.ts` (`fetchFn` sans `AbortController`), `adapter.ts` (`browserDeps`). Le test « mode avion » (13.3) ne prouve qu'un échec **immédiat**.
- **Mesuré** : recherche de 0,5 à 1,9 s, écriture de 0,1 à 0,3 s. DataDome peut aussi retenir une requête.
- **Si c'est faux** : la requête ne rend jamais la main ; l'écran de progression reste figé, sans message, jusqu'à l'abandon.
- **Vérifier** : si la progression reste figée plus d'une minute sur une ligne, noter la ligne et l'heure, attendre deux minutes, puis « Abandonner ».
- **Défense** : ticket K (délai de 25 s, puis le chemin « réseau » existant).
- **Statut (2026-10-19) : défense livrée, à mesurer à la recette réelle.** Ticket K (PR front #136, agent 0.13.0) : délai de **20 s** (le ticket proposait 25 s), une reprise après 2 s, puis `NETWORK` de cause `REQUEST_TIMEOUT`. Pas encore dans une application publiée.

### H16. Compte ou profil neuf : mode de retrait, créneau avant d'ajouter (c, risque bas pour la recette, moyen pour les proches)

- **Mesuré** : ajout réussi sur un compte ancien, magasin et mode déjà choisis. Non mesuré : un compte jamais utilisé, ou le profil « drive » neuf de l'application (premier chargement).
- **Si c'est faux** : l'écriture est refusée (réponse sans évènement panier) tant que l'utilisateur n'a pas choisi un mode de retrait ou un créneau : `CONTRACT_CHANGED`.
- **Vérifier** : en 4.2, noter si le site, après la connexion dans le profil neuf, demande un mode (drive, livraison) ou un créneau avant d'afficher les produits.
- **Défense** : ticket A (cause fine) puis un message statique (« Choisis d'abord ton mode de retrait sur Leclerc »).

### H17. Liens ouverts dans le navigateur du téléphone (b, risque bas)

- **Où** : `src/features/drive/leclerc.ts` (`BASKET_PATH = ''` : « Finaliser sur Leclerc » ouvre `https://<hôte>/magasin-N-N`, sans barre finale ni page, comme H1) et `DriveSendView.vue` (`searchUrl` = `<baseUrl>/recherche.aspx?TexteRecherche=…` pour le **mode assisté**, repli permanent du chantier).
- **Inconnu** : le navigateur d'un téléphone se voit servir le site **mobile** (`m-courses`) dont les routes sont autres (DRV-011 lignes 5-6) ; `recherche.aspx` y a répondu sans produit. Le mode assisté n'est jamais essayé par la recette. La session du navigateur est distincte de celle de l'application (8.3 le dit).
- **Vérifier** : dans Chrome (pas dans l'application), ouvrir l'écran d'envoi de Repas et toucher « Chercher sur Leclerc » sur une ligne : version mobile (nom en `m-`) ? résultats ? connexion demandée ?
- **Défense** : ticket N.

### H18. La connexion reste sur `leclercdrive.fr` (a en partie, risque bas)

- **Mesuré** : connexion et choix du magasin sans aucun site confié au navigateur (DRV-011 lignes 3 et 12, 2026-10-17).
- **Où** : `NavigationPolicy.decide` : toute navigation de la page principale vers un autre domaine part dans le navigateur du téléphone (les cookies n'arrivent pas dans le profil du drive).
- **Si c'est faux** : un compte relié à une connexion externe (compte E.Leclerc sur un autre domaine, connexion par un tiers, page de vérification en deux étapes) sort de l'application et la connexion boucle.
- **Vérifier** : en 4.2, noter si Chrome s'est ouvert pendant la connexion (oui/non).
- **Défense** : ticket P (compter les sites confiés au navigateur, noms de domaine seulement, dans le diagnostic).

### H19. Le second nombre de `/magasin-N-N` égale le premier (c, risque bas)

- **Où** : le plan ne porte que `store.id` (premier nombre) et l'hôte ; `DrivePlan.startUrl` répète `id` (`/magasin-$id-$id/`). Le back et le front gardent les deux nombres dans `baseUrl`, mais l'application Android ne le reçoit pas.
- **Mesuré** : rien ne dit que les deux nombres sont toujours égaux (la source tierce écrit `magasin-{id}-{id}`) ; le faux site les égale.
- **Si c'est faux** : l'ouverture d'un envoi vise un autre magasin ou une page d'erreur (H1 sous un autre angle).
- **Vérifier** : en 6.3, via « Ouvrir dans le navigateur », noter « les deux nombres de l'adresse sont identiques : oui/non » (sans les écrire).
- **Défense** : ticket F.

### H20. L'hôte `fdN` d'un magasin est stable dans le temps (c, risque bas)

- **Où** : `orchestrator.ts` (`storeProblem` : l'hôte de la page doit être **égal** à celui du plan, mémorisé à la lecture du magasin) ; `recherche.md` § 2 : plusieurs familles (`fd9`, `fd8`, `fd14`).
- **Si c'est faux** : si Leclerc déplace un magasin vers une autre famille, tous les envois sortent « Ce n'est pas le bon magasin », avec le conseil « Ouvre le magasin choisi dans Repas » (sans effet).
- **Vérifier** : pas en une séance. À surveiller : comparer la famille vue au diagnostic le jour de la lecture du magasin et le jour d'un envoi.
- **Défense** : ticket F (n'exiger que le numéro du magasin ; proposer de mettre à jour l'hôte).

### H21. Pagination et nombre de résultats (c, risque bas)

- **Mesuré** : 25 à 26 produits pour « farine », 55 à 209 pour d'autres mots, pages de 0,36 à 1 million de caractères (DRV-002 ligne 4, DRV-011 ligne 6). On ne sait pas si la page embarque **tous** les résultats ou une première tranche.
- **Où** : `embedded.ts`. Phase B n'affiche que 8 produits (`MAX_CANDIDATES`) : sans effet ; phase A cherche un produit précis (voir H3) : une tranche courte peut le manquer.
- **Vérifier** : au diagnostic, « Recherche d'un mot courant » donne N produits lus ; faire la même recherche sur le site et noter combien s'affichent et s'il y a « voir plus » ou des pages.
- **Défense** : ticket B (compteur) puis D.

### H22. Bandeaux de cookies et fenêtres modales (c, risque bas)

- **Où** : l'interface de l'agent est posée sous `documentElement` avec `z-index: 2147483647` (`ui/ui.ts`), et l'agent ne clique rien sur la page : il n'agit que par `fetch`. Un bandeau ne devrait donc pas gêner.
- **Inconnu** : un bandeau qui bloque les défilements, ou un gestionnaire de consentement qui retient des scripts du site (anti-robot) tant qu'on n'a pas accepté.
- **Vérifier** : au premier chargement du profil neuf (4.2), noter si un bandeau ou une fenêtre apparaît, et si la couche de Repas passe au-dessus et reste utilisable au doigt.
- **Défense** : aucune pour l'instant.

## 4. Ce qui est mesuré (a)

| Fait | Source | Date |
|---|---|---|
| Recherche `GET …/recherche.aspx?TexteRecherche=` : 200, JSON embarqué, sans EAN, 25 à 209 produits | DRV-002 ligne 4 (PC) ; DRV-011 ligne 6 (téléphone) | 2026-10-17 |
| Champs lus : identifiant, libellés, prix, prix à l'unité de mesure, stock, quantité au panier, contenu net structuré | DRV-002 lignes 4 et 17 ; DRV-007 | 2026-10-17 |
| Contenu net et prix lus pour 100 % des produits, sur 12 mots courants, dont « pâtes » et « huile d'olive » (liste du test automatique) | DRV-011, essai réel du test automatique (version 12, Pixel 9) | 2026-10-18 |
| Écriture du panier : `POST …/panier.aspx?op=1`, champ `d`, `zcwb: ajax-manager`, adresse **sans** ville, identifiant en texte, contexte à l'ajout ; quantité absolue dans les deux sens ; retrait = action 2, quantité 0 | DRV-007 ; DRV-002 ligne 5 ; DRV-011 lignes 7-8 | 2026-10-17 |
| État du panier lu dans la réponse (`lstProduitsLight`, `iQuantitePanier`, `sTotalAPayer`, évènements 101, 102, 104) | DRV-007 ; selftest (ajout, passage à 3 puis 2, retrait, panier revenu à 0) | 2026-10-17, 2026-10-18 |
| Hôte `fdN-courses` en mode ordinateur, `m-courses` sinon ; `sNoPointLivraison` absent en mode mobile | DRV-011 lignes 2, 5, 6 | 2026-10-17 |
| `X-Frame-Options: DENY` ; pas de CSP ni COOP sur l'accueil et la recherche (la page panier n'est pas relevée) | DRV-004 | 2026-10-17 |
| Pas de blocage : ~40 requêtes en série (PC), lot de 30 recherches (téléphone), 12/12 et 3 produits (selftest) | DRV-002 ligne 6 ; DRV-005 ; DRV-011 | 2026-10-17, 2026-10-18 |
| Connexion faite à la main dans l'application, sans site confié au navigateur ; connexion conservée après fermeture complète | DRV-011 lignes 3, 12 ; DRV-005 (la ligne 10 de DRV-011 est restée « non testé ») | 2026-10-17 |
| Un panier rempli sur le site apparaît dans l'application Leclerc du même compte | DRV-002 ligne 13 | 2026-10-17 |
| Contenu net déductible pour 25 produits sur 25 ; bon produit dans les 3 premiers pour 23 ingrédients sur 30 | DRV-002 lignes 17, 18 | 2026-10-17 |
| La racine `https://<hôte>/` n'est **pas** une page de magasin | recette réelle (repas-front#125) | 2026-10-18 |

## 5. Écarts de documentation trouvés en chemin

1. `drive-agent/fake-leclerc/README.md` : le point de livraison serait « différent de l'identifiant du magasin, comme sur le vrai site » ; DRV-002 ligne 3 dit « même valeur ». L'un des deux est faux (H6).
2. `runbook.md` § 1 : « Page non prise en charge » est donné comme symptôme du site mobile ; en réalité `m-courses` passe la lecture du magasin et le symptôme est `STORE_MISMATCH` ou `CONTRACT_CHANGED` (H11).
3. `recette.md` partie 6 (« À vérifier ici (DRV-319) ») et `tickets/DRV-319-emulateur-android-docker.md` (dernière hypothèse) décrivent encore la racine de l'hôte, hypothèse **réfutée** : à remplacer par H1.
4. `recette.md` étape 2.1 : la pré-version à installer doit contenir repas-front#125 (ouverture sur la page du magasin), en plus de DRV-320 et de DRV-613 partie 2.
5. `spikes/DRV-011-rapport.md` ligne 10 (session) est restée « non testé » alors que DRV-005 la donne comme OK.
6. Ni le diagnostic ni le test automatique n'exercent les points H2, H3, H4 et le parcours `drive.start` : « tout vert » sur le test automatique ne dit rien de ces quatre points.

## 6. Défenses proposées : tickets candidats

Tailles comme dans `roadmap.md` (S, M, L). « Agent » = `drive-agent/` ; « Android » = `android/` ; les deux sont dans le dépôt front. Aucun n'est implémenté ici.

| Id | Titre | Taille | Dépôt | Couvre | Contenu |
|---|---|---|---|---|---|
| A | Cause fine des erreurs `CONTRACT_CHANGED` | S | agent | H2, H3, H5, H8, H9, H16 | Un code court de cause (`SEARCH_EMPTY`, `HTTP_404`, `CART_NO_EVENT`, `CART_NOT_JSON`, `EXCEPTION`…) ajouté à `LeclercError`, affiché sur l'écran d'erreur et dans `error.message` du résultat, sans contenu de page. Aujourd'hui une dizaine de causes distinctes donnent le même « Le site a changé ». |
| B | Sondes de diagnostic du site réel | M | agent | H2, H4, H6, H11, H19, H21 | Lignes ajoutées au diagnostic (sans donnée personnelle) : forme de l'adresse (`/magasin-N-N[-V][/page]`) et nombre de redirections ; recherche d'un mot absurde ; point de livraison lu (oui/non) et égal au numéro de l'adresse (oui/non) ; **noms** des clés de premier niveau de l'état embarqué de la page (pour trouver un marqueur de connexion) ; valeurs des champs de disponibilité ; famille d'hôte ; nombre de produits par recherche. |
| C | Reconnaître « aucun résultat » par la structure de la page | S | agent | H2 | Une réponse 200 qui a le squelette habituel (état embarqué présent) et zéro produit est une liste vide ; `CONTRACT_CHANGED` seulement si le squelette manque. Décoder `&quot;` comme pour les produits. |
| D | Retrouver un produit connu : requêtes de repli et normalisation | M | agent | H3, H7, H21 | Libellé complet, puis première ligne seule, puis `searchQuery` ; « introuvable » = indisponible, jamais l'arrêt du passage ; normaliser (`œ`→`oe`, `’`→`'`, minuscules, second essai sans accents) ; noter le rang trouvé dans le diagnostic. |
| E | Page d'ouverture : repli et message clair | S | Android | H1, H10 | Détecter la page d'erreur du site (statut 404 de la page principale) et l'absence de page de magasin après un délai ; bandeau avec un bouton « Ouvrir l'accueil du drive » et un texte exact ; ne plus supposer que l'utilisateur peut « ouvrir son magasin ». |
| F | Le plan porte l'adresse du magasin telle que le site la sert | M | back + protocole + front | H1, H19, H20 | Mémoriser à la lecture du magasin le chemin d'entrée observé (sans la ville si l'on veut la minimiser), l'envoyer dans `store` (champ facultatif du protocole v1, plan et exemples), ouvrir cette adresse ; ne comparer que le numéro du magasin et proposer de mettre à jour l'hôte s'il change. |
| G | Détecter « non connecté » avant de commencer | M | agent | H4 | Après la sonde B : lire le marqueur de connexion, refuser « Commencer » sans connexion avec un message clair ; sinon un panier vide au retour. Dépend de B. |
| H | Écriture du panier fidèle à la requête mesurée | S | agent | H5, H6 | `referrer` fixé sur la dernière page de recherche ; en cas de 404, une reprise sans contexte (noter laquelle a marché) ; point de livraison lu d'abord dans la dernière réponse de recherche, jamais de repli silencieux sur le numéro de l'adresse. |
| I | Quantités : poids variable, rupture, quantité réduite | M | agent + protocole + front | H8, H9 | Lire `eDisponibilite` et le « vendu au poids » ; contrôler `rTotalAPayer` contre prix × quantité ; comparer `obtained` à la quantité voulue et le dire sur l'écran de fin et au retour dans Repas. |
| J | Site ordinateur seulement | S | agent + Android | H11 | `readStore` et la lecture du magasin n'acceptent que `fd<N>-courses` ; message « version mobile » dédié ; ligne « mode ordinateur appliqué : oui/non » dans « Version et mode d'emploi ». |
| K | Délai d'attente et message de défi distincts | S | agent | H13, H15 | `AbortController` de 25 s sur chaque requête, branché sur le chemin « réseau » ; texte distinct pour un défi (« recharge la page, résous la vérification, puis Reprendre ») et pour un 403/429 ; compteur de durée sur l'écran de progression. |
| L | Compteurs d'un passage et cadence adaptative | S | agent | H12, H13 | Nombre de requêtes, durée par phase, statuts rencontrés, dans le rapport de fin et le diagnostic ; respecter un `Retry-After` ; une recherche identique n'est pas rejouée dans le même passage. |
| M | Ne pas réduire une quantité déjà au panier | S | PO puis agent | H14 | Décision à prendre (garder la plus grande quantité, ou demander), puis lecture de `inCart`. |
| N | Liens vers le navigateur du téléphone | S | front | H17 | Décider du comportement de « Finaliser sur Leclerc » et du mode assisté face au site mobile (ouvrir dans la WebView du drive quand on est dans l'application ; sinon expliquer). |
| O | Recette : gestes de vérification de ce fichier | S | back (docs) | tous | Ajouter à `recette.md` les gestes du § 1 et § 3, la fiche de notes du § 7, et les mesures manquantes (nombre de requêtes, durée par phase) ; corriger les écarts du § 5. |
| P | Sites confiés au navigateur dans le diagnostic | S | Android | H18 | Compter les navigations confiées au navigateur (nom de domaine seulement) et les montrer dans le diagnostic. |

**Ordre conseillé.** *Avant de reprendre la recette* : A, E, O, puis B et C (ils rendent la suite lisible). *Avant d'ouvrir aux proches* : D, F, G, H, J, K, L. *Plus tard* : I, M, N, P.

## 7. Fiche de notes pour la recette (sans donnée personnelle)

À remplir en route ; les adresses sous la forme `/magasin-N-N[-V][/page]`.

| # | Hypothèse | À noter | Réponse |
|---|---|---|---|
| 1 | H1 | Bandeau vert visible ? Ce que montre la page ? Temps jusqu'à « Prêt à remplir ton panier » ? | |
| 2 | H1, H10, H19 | Forme de l'adresse d'arrivée ; les deux nombres sont-ils identiques (oui/non) ? | |
| 3 | H5, H6 | Test automatique lancé depuis l'accueil du magasin : verdict, code HTTP si échec | |
| 4 | H2 | Mot absurde cherché : « Aucun produit trouvé » ou « Le site a changé » ? | |
| 5 | H7 | Lignes dont la première recherche était vide (noms d'ingrédients) | |
| 6 | H3 | Lignes connues sorties « plus disponible » ; même libellé cherché sur le site : trouvé, rang | |
| 7 | H9 | Écarts entre la quantité du plan et celle du récapitulatif / du panier | |
| 8 | H8 | Un fruit ou légume choisi (quantité 1) : unité de la ligne du panier, prix | |
| 9 | H14 | Un exemplaire ajouté à la main avant le 2ᵉ envoi : quantité après l'envoi | |
| 10 | H4 | Déconnecté : verdict du test automatique ; produits vus dans l'application officielle ? | |
| 11 | H11, H21 | Diagnostic : famille d'hôte (`fdN`) ; nombre de produits lus pour « sel » et nombre vu sur le site | |
| 12 | H16, H22, H18 | Mode de retrait ou créneau demandé ? Bandeau de cookies ? Chrome ouvert pendant la connexion ? | |
| 13 | H12, H15 | Nombre de lignes, durée de chaque passage, progression figée plus d'une minute ? | |
| 14 | H13 | Défi anti-robot ou code de vérification rencontré ? À quelle étape ? Reprise proposée ? | |
| 15 | H17 | « Chercher sur Leclerc » dans Chrome : version mobile ? résultats ? | |

## 8. Constats de la recette réelle (2026-10-18, Pixel 9, android-v0.1.1 à 0.1.3)

Mesurés par l'assistant en pilotant le téléphone de l'utilisateur par `adb` (session de l'utilisateur, aucun identifiant chez nous) ; adresses en motif.

- **H1 réfutée.** `https://<hôte du magasin>/magasin-N-N/` (sans ville) est une **page 404** du vrai site (« Désolé, la page demandée n'a pas pu être trouvée »). La **racine de l'hôte** du magasin (`https://fdN-courses.leclercdrive.fr/`) est elle aussi une **page 404**. Seul `https://www.leclercdrive.fr/` (session ouverte) affiche l'accueil du magasin de l'utilisateur avec le bouton « Commencer mes courses », qui mène aux vraies pages du magasin (adresse `/magasin-N-N-<Ville>/…`), où l'agent reconnaît « Page prise en charge ». La PR front #125 (`/magasin-N-N/`) et la PR #128 (racine de l'hôte) étaient donc fausses ; le faux site de test servait à tort ces adresses comme un accueil, ce qui a masqué le défaut. Correction : un plan s'ouvre sur `https://www.leclercdrive.fr/` comme la lecture du magasin, et le faux site est rendu fidèle (racine de l'hôte et `/magasin-N-N/` en 404).
- **Cause du HTTP 400 de la recherche confirmée.** Depuis l'accueil du magasin atteint par « Commencer mes courses », la recherche de l'agent (`GET <chemin de la page>/recherche.aspx?TexteRecherche=<mot>`) répond **HTTP 400** ; depuis une page de résultats de la recherche du site (chemin avec ville), le diagnostic lit « sel » : 195 produits, tous avec identifiant, libellé, prix et stock, et le contenu net de 195 produits sur 195. La requête JS était la même que celle du banc d'essai : seul le chemin de la page de départ diffère. L'agent 0.10.0 lit donc dans la page le chemin avec ville (action du formulaire de recherche, à défaut premier lien `/magasin-N-N-<Ville>`) et l'essaie d'abord ; en repli, chaque autre forme une fois sur un 400 ; le chemin du panier reste sans ville.
- **H4 (visiteur) précisée.** Un membre connecté voit aussi un lien « Se connecter » dans la page : ce signe est devenu faible (visiteur seulement avec plusieurs signes et aucun signe de membre).
- **Outils utiles pour la suite.** Le menu du drive (⋮) a « Diagnostic de l'agent » (ignoré pendant un passage : à lancer hors passage), « Agrandir/Réduire la page » et « Version et mode d'emploi » ; « Ouvrir dans le navigateur » ouvre Chrome (site mobile `m-courses`, sans la session) : il ne donne pas l'adresse de la WebView.

## 9. Statut au 2026-10-19 (après les corrections de la recette réelle)

Ajouté le 2026-10-19. Sources : les PR du dépôt front `kcharignon/repas-front` (#125 à #138) et [`roadmap.md`](roadmap.md) § M3c. « Application » : la première application publiée qui embarque la correction ; `android-v0.1.4` (agent 0.11.0) est la dernière publiée, les agents 0.12.0 à 0.15.0 n'y sont pas. **« À mesurer »** veut dire : rien n'est relevé sur le vrai site, la recette réelle le dira (fiche § 7).

| Id | Statut | PR front · agent · application | Reste |
|---|---|---|---|
| H1 | **Réfutée** (§ 8) | #132 · — · `android-v0.1.4` (après #125 et #128, fausses) | Ticket F non fait |
| H2 | Défense livrée, à mesurer à la recette réelle | #127 (C) · 0.9.1 · `android-v0.1.2` ; sonde #129 | Forme réelle d'une page sans résultat |
| H3 | Défense livrée, à mesurer à la recette réelle | #130 (D) · 0.11.0 · `android-v0.1.4` | Lignes « plus disponible » à tort |
| H4 | Défense livrée, à mesurer à la recette réelle | #127 (G), #129 · 0.9.1 et 0.10.0 · `android-v0.1.2`, `android-v0.1.3` | Test déconnecté ; libellés de visiteur |
| H5 | Non défendue (ticket H non fait) | — | Test automatique depuis l'accueil du magasin |
| H6 | Sonde livrée (ligne « Point de livraison » du diagnostic), ticket H non fait | #129 (B) · 0.10.0 · `android-v0.1.3` | Valeur et source du point de livraison |
| H7 | Défense livrée, à mesurer à la recette réelle | #130 (D) · 0.11.0 · `android-v0.1.4` | Mots de liaison, pluriel |
| H8 | Non défendue (ticket I non fait) | — | Un fruit ou légume choisi (fiche § 7 ligne 8) |
| H9 | Cause lisible seulement (A : `CART_NO_CART_EVENT`…), ticket I non fait | #127 · 0.9.1 · `android-v0.1.2` | Quantité réduite, limite d'achat |
| H10 | Repli livré (E) ; la partie 4 de la recette (lecture du magasin sur le vrai site) est déclarée réussie | #128, #132 · — · `android-v0.1.3`, `android-v0.1.4` | Les notes de la fiche § 7 ne sont pas reportées ici |
| H11 | Défense livrée, à mesurer à la recette réelle | #137 (J) · 0.14.0 · pas encore publiée | — |
| H12 | Défense livrée, à mesurer à la recette réelle | #138 (L) · 0.15.0 · pas encore publiée | Nombre de requêtes, durée, seuils de la cadence |
| H13 | Défense livrée, à mesurer à la recette réelle | #136 (K), #138 · 0.13.0 et 0.15.0 · pas encore publiée ; bandeau de l'application #128 · `android-v0.1.3` | Forme réelle du défi |
| H14 | Défense livrée, à mesurer à la recette réelle | #135 (M) · 0.12.0 · pas encore publiée | `iQuantitePanier` toujours lu ? |
| H15 | Défense livrée, à mesurer à la recette réelle | #136 (K) · 0.13.0 · pas encore publiée | — |
| H16 | Cause lisible seulement (A), pas de message statique | #127 · 0.9.1 · `android-v0.1.2` | Compte ou profil neuf |
| H17 | Non défendue (ticket N non fait) | — | « Finaliser sur Leclerc », mode assisté (voir aussi F16 de `audit-simplicite.md`) |
| H18 | Non défendue (ticket P non fait) | — | — |
| H19 | Non défendue (ticket F non fait) | — | — |
| H20 | Non défendue (ticket F non fait) | — | Surveillance |
| H21 | Sonde livrée (nombre de produits lus par recherche, dans le diagnostic) | #129 (B) · 0.10.0 · `android-v0.1.3` | Pagination du vrai site |
| H22 | Aucune défense | — | — |

**Tickets candidats du § 6** : faits A, B, C, D, E, G, J, K, L, M (voir le tableau ci-dessus et `roadmap.md` § M3c) ; non faits **F** (adresse d'entrée portée par le plan), **H** (écriture du panier fidèle à la requête mesurée), **I** (poids variable, rupture, quantité réduite), **N** (liens ouverts dans le navigateur), **P** (sites confiés au navigateur) ; **O** (gestes de vérification dans `recette.md`) revient à l'assistant, après DRV-621.

**Écarts du § 5** : (1) `drive-agent/fake-leclerc/README.md` du front dit encore que le point de livraison diffère de l'identifiant du magasin « comme sur le vrai site » : toujours là au 2026-10-19 ; (2) `runbook.md` corrigé le 2026-10-19 (§ 1 et § 12) ; (3) le ticket DRV-319 corrigé le 2026-10-19, `recette.md` partie 6 : le constat du 2026-10-19 est en 6.3, l'encadré « À vérifier ici (DRV-319) » juste au-dessus est périmé ; (4) `recette.md` 2.1 : version minimale d'application ajoutée le 2026-10-19 ; (5) `spikes/DRV-011-rapport.md` ligne 10 inchangée ; (6) toujours vrai : le diagnostic et le test automatique n'exercent pas H3 ni le parcours `drive.start`.

## 10. Constats du 2026-10-19 (Pixel 6, compte Leclerc d'essai, application `android-v0.1.7`, agent 0.22.0)

Mesurés avec les sondes du diagnostic (DRV-625) et des touches à la main ; formes seulement, aucun numéro de magasin ni ville.

| Constat | Valeur mesurée |
|---|---|
| « Commencer mes courses » sur l'accueil | **un bouton sans adresse** (« oui, adresse sans adresse (bouton) ») : l'agent ne peut pas le suivre comme un lien ; une touche humaine mène à `/magasin-N-N-V` (page d'accueil du magasin, HTTP 200) |
| Lien du panier sur une page de magasin | **aucun lien** « panier » dans la page (l'icône du panier est un bouton) |
| Page du panier (après touche sur l'icône) | **`/magasin-N-N-V/detail-panier.aspx`**, avec la ville dans le chemin, HTTP 200, `Point de livraison` lu et égal au numéro de l'adresse |
| Recherche depuis une page de magasin | inchangée : `/magasin-N-N/recherche.aspx` (sans ville) répond 200, 195 produits lus pour « sel » ; variantes avec ville = 400 |
| Anti-robot | un **Chromium automatisé du PC** (sans fenêtre) reçoit HTTP 403 ; avec une fenêtre, 200 mais sans pouvoir se connecter ; ces essais ont fait **marquer l'adresse de la box** (« Vous avez été bloqué(e) », curseur à faire glisser, puis hCaptcha à la connexion) ; l'appareil, lui, passe après le curseur. **Aucun essai automatisé depuis le PC** à l'avenir. |
| Connexion | e-mail, puis mot de passe, avec une case hCaptcha à cocher par une personne |

**Conséquences** : (1) `BASKET_PATH` est `detail-panier.aspx`, à ajouter au chemin du magasin **avec la ville**, que seule la page donne (ticket DRV-629) ; (2) l'entrée sans geste de DRV-625 doit **cliquer le bouton** « Commencer mes courses » (il n'a pas d'adresse), avec repli sur le geste manuel.

## 11. Constats du 2026-10-19 après-midi (Pixel 6, compte d'essai, application `android-v0.1.8`, agent 0.24.0)

| Constat | Valeur mesurée |
|---|---|
| Clic programmé sur « Commencer mes courses » (DRV-629) | **ignoré par le site** : 25 secondes après « Ouvrir le drive », la page reste sur l'accueil avec la fenêtre « Commencer mes courses », et l'agent affiche son bandeau de repli « Touche « Commencer mes courses ». ». Le repli marche : une touche à la main, puis le plan part. Le site distingue donc un clic de personne d'un clic de programme (événement non fiable) : l'entrée sans geste n'est **pas possible** sur le vrai site ; le comportement de DRV-629 reste correct (repli). |
| Envoi de 4 articles dont un produit habituel devenu indisponible | l'agent le dit (« Ces produits ne sont plus disponibles : … »), propose de choisir un autre produit, puis « Toujours prendre ce produit ? » ; retour automatique dans Repas : « Le panier est rempli », 3 ajoutés, 1 choisi, « Tes choix sont enregistrés pour les prochains envois. » |
| « Finaliser sur Leclerc » (DRV-629) | **ouvre l'accueil du drive** (dans l'application, connecté), pas la page du panier : le repli sur l'accueil s'est déclenché (chemin avec ville non mémorisé, page refusée ou redirigée par le site : cause inconnue, l'application ne le journalise pas) → DRV-631 |
| Fiche produit de l'agent | « En promotion : 0,00 € » affiché sur chaque produit sans promotion, et « Il te faut : 3 unité » (pluriel) → DRV-632 |
| Mise à jour du site dans l'application | la page restée ouverte garde l'ancienne version ; un redémarrage de l'application charge la nouvelle (tutoiement vu après redémarrage) |
