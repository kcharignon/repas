# Runbook : « Leclerc a changé » (et couper l'envoi au drive)

À suivre quand l'envoi de la liste au drive ne marche plus : message « Le site a changé », « Leclerc a ralenti les requêtes », paniers vides, produits qui ne se trouvent plus. Écrit pour une personne qui **n'a pas écrit le code** : chaque étape dit quoi faire et ce qu'on doit voir. Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Mise en production et retour arrière : [`../production.md`](../production.md), section « Envoi de la liste au drive ». Dernière mise à jour : 2026-10-19 (DRV-503, corrections de la recette réelle : site mobile, page de vérification, compteurs, ordre de publication).

## Règles d'or

- **Rien n'est urgent côté données.** Repas ne valide jamais de commande, ne choisit pas de créneau, ne paie pas et ne vide jamais le panier Leclerc. Le pire qui arrive est un panier Leclerc incomplet ou avec un produit en trop : on le regarde et on le corrige à la main avant de finaliser.
- **Couper est toujours sûr, immédiat et réversible.** Rien n'est effacé. En cas de doute, couper.
- **On ne contacte jamais Leclerc depuis le serveur**, ni avec `curl`, ni par un agent d'IA, ni depuis un émulateur ouvert sur le vrai site. Les seuls essais réels se font sur le téléphone ou l'ordinateur de l'utilisateur, avec son propre compte, à faible cadence.
- **Aucun secret dans le chat ni dans un ticket** : ni mot de passe, ni code reçu par e-mail, ni cookie, ni jeton, ni numéro de magasin complet, ni nom de ville. Les rapports de l'application n'en contiennent pas ; en cas de doute, relire avant de coller.
- **L'humain décide** : couper ou allumer `DRIVE_ENABLED`, créer une version de l'application (`android-vX.Y.Z`) ou du back (`vX.Y.Z`), approuver un déploiement. Un agent ou l'assistant prépare, explique et vérifie.
- **Une étape à la fois** : faire, regarder le résultat, passer à la suivante.

## Aide-mémoire

1. Noter ce qu'on voit (titre du message, code).
2. Faire le **diagnostic** de l'agent (§ 3) pour savoir si Leclerc a changé, bloque, ou si le problème est ailleurs.
3. Si c'est sérieux, ou dans le doute : **couper** (§ 4).
4. **Prévenir** la famille et proposer le repli (§ 5 et 6).
5. **Corriger** (§ 7) : nouvelle version de l'agent, donc de l'application ; chacun l'installe.
6. **Rallumer** après les contrôles (§ 8).

## 1. Reconnaître le problème

L'agent s'arrête proprement avec un code ; le titre est ce que l'utilisateur lit à l'écran.

| Ce qu'on voit | Code | Sens probable | Couper pour tous ? |
|---|---|---|---|
| « **Le site a changé** » : « Leclerc a modifié son site. Mets à jour l'application Repas Drive » | `CONTRACT_CHANGED` | Leclerc a modifié une page ou une réponse que l'agent sait lire (recherche, panier), ou une requête est refusée parce que mal formée. **C'est le cas « Leclerc a changé ».** | **Oui**, une fois confirmé par le diagnostic (§ 3) |
| « **Leclerc a ralenti les requêtes** » | `BLOCKED` | L'anti-robot de Leclerc a refusé (403, 429 ou page de contrôle) deux fois de suite ; l'agent s'est arrêté (résultat « partiel »). Pas forcément un changement du site. | Non pour un cas isolé : attendre et ne pas insister. **Oui** si ça touche plusieurs personnes ou dure plus d'un jour |
| Des lignes « indisponibles » ou « quantité à vérifier » partout, ou **aucun produit trouvé** pour des mots courants (farine, sel, lait) | (résultat vide, `CONTRACT_CHANGED` ou lignes `UNAVAILABLE` / `TO_CHECK`) | La recherche ne lit plus les produits, ou plus leur contenu net : le site a changé d'une façon que l'agent ne reconnaît pas comme une erreur. | **Oui** après diagnostic |
| « Tu n'es pas connecté » | `NOT_LOGGED_IN` | La session Leclerc a expiré. | Non : se reconnecter à Leclerc dans l'application |
| « Le site ne répond pas » | `NETWORK` | Réseau du téléphone ou panne passagère de Leclerc (l'agent a déjà réessayé une fois). | Non : réessayer plus tard |
| « Page non prise en charge » ou, depuis l'agent 0.14.0, « **Ce n'est pas la version ordinateur du site** » | `UNSUPPORTED_PAGE` (causes `PAGE_PATH`, `PAGE_HOST`, `PAGE_MOBILE`) | L'application n'est pas sur une page de magasin du site **ordinateur** (hôte `fdN-courses…`). Soit l'adresse n'est pas celle d'un magasin (`PAGE_PATH`), soit elle n'est pas sur `leclercdrive.fr` (`PAGE_HOST`), soit Leclerc sert son site **mobile** (`m-courses…`, `PAGE_MOBILE`), dont les routes sont autres. Avant l'agent 0.14.0 (application `android-v0.1.4` et plus anciennes), le site mobile n'était pas reconnu : il se voyait comme « Ce n'est pas le bon magasin » ou « Le site a changé ». | Non : voir § 12 |
| « Ce n'est pas le bon magasin » | `STORE_MISMATCH` | Repas a préparé la liste pour un autre magasin que celui ouvert. | Non |
| « Liste illisible » | `INVALID_MESSAGE` | L'application, le front et le back ne parlent pas la même version du protocole (une mise à jour manque). | Non : mettre l'application à jour, puis le front si besoin |
| « Envoi annulé » | `CANCELLED` | L'utilisateur a annulé. | Non |

- **Le message porte une cause entre parenthèses** (agent 0.9.1 et plus récent) : « Le site a changé (SEARCH_NO_STATE) ». Le code dit **quelle vérification** a échoué ; la table « Les causes » plus bas donne le sens. Elle ne contient jamais un morceau de page ni d'adresse, on peut la recopier.
- **Le site s'affiche en version mobile** (le nom commence par `m-`) : voir § 12.
- **Le compte Leclerc d'un proche est suspendu ou bloqué** : couper aussitôt pour tous (§ 4). Les conditions de Leclerc permettent à l'enseigne de suspendre un compte en cas d'usage jugé abusif. Le proche contacte lui-même le service client de Leclerc ; Repas ne contacte jamais Leclerc.
- **Un `429` `DRIVE_RATE_LIMITED` vient de Repas, pas de Leclerc** (DRV-615) : le back plafonne par utilisateur les écritures du drive (60 par minute) et la lecture des plans et estimations (120 par minute). Un usage normal n'y arrive jamais ; si quelqu'un le voit, attendre une minute suffit (aucune donnée n'est perdue : la demande refusée n'a rien enregistré). À ne pas confondre avec `BLOCKED`, qui est le refus de Leclerc. Rien à couper ni à corriger côté Leclerc ; si un compte le déclenche en boucle, regarder qui (script, application bloquée dans une boucle de nouvelle tentative) avant de désactiver le compte (ADR 0021).
- **Le back ne voit rien** : il ne contacte jamais Leclerc et n'enregistre ni plan ni résultat dans ses journaux ([`architecture.md`](architecture.md) § 10). Le serveur n'a donc aucun signal de panne : la détection vient des messages de l'agent, du diagnostic et de ce que disent les utilisateurs.

### Les causes (entre parenthèses dans le message, ou « cause … » dans le diagnostic)

Source : `drive-agent/src/leclerc/causes.ts` du dépôt front (agent 0.9.1 et plus récent). Un code `HTTP_<nombre>` dit que le site a répondu ce code HTTP à une requête que l'agent n'attendait pas (le nombre seulement, jamais le texte de la réponse).

| Cause | Ce que ça dit | Que faire |
|---|---|---|
| `SEARCH_NO_STATE`, `SEARCH_PRODUCTS_UNREADABLE` | La page de recherche n'a plus l'état embarqué du magasin, ou ses produits ont des champs renommés | **Leclerc a changé** : diagnostic (§ 3) puis § 7 |
| `SEARCH_REDIRECTED`, `LOGIN_REDIRECT`, `LOGIN_FORM` | La recherche a été remplacée par une autre page, ou par la page de connexion | Se reconnecter à Leclerc ; si ça revient, le diagnostic |
| `SESSION_VISITOR` | La page est celle d'un visiteur non connecté (code `NOT_LOGGED_IN`) | Se reconnecter à Leclerc dans l'application, puis relancer |
| `CART_NOT_JSON`, `CART_NO_EVENTS`, `CART_NO_CART_EVENT`, `CART_NO_PRODUCT_LIST`, `CART_QUANTITY_UNREADABLE` | La réponse du panier n'a pas la forme attendue : Leclerc a changé, ou refuse l'ajout (produit indisponible, limite d'achat : hypothèse H9) | Si c'est un seul produit, le passer ; si c'est partout, **Leclerc a changé** : le test automatique (§ 3.2) |
| `HTTP_400` (recherche) | La recherche est demandée sur un chemin que le site refuse | § 11 |
| `HTTP_404` | Page introuvable | § 11 ; vérifier l'adresse ouverte |
| `REQUEST_TIMEOUT` | Leclerc n'a pas répondu dans les 20 s (code `NETWORK`) | Réessayer plus tard ; réseau du téléphone |
| `CHALLENGE` | Page de vérification de l'anti-robot (code `BLOCKED`) | § 13 |
| `PAGE_HOST`, `PAGE_PATH` | La page n'est pas sur `leclercdrive.fr`, ou son adresse n'est pas celle d'un magasin | Ouvrir son magasin (§ 11) |
| `PAGE_MOBILE` | Le site mobile, pas la version ordinateur | § 12 |
| `SEARCH_COMMON_EMPTY`, `SEARCH_FIELDS_INCOMPLETE`, `CONTENT_UNREADABLE` | Contrôles du diagnostic : aucun produit pour le mot d'essai, produits sans tous leurs champs, contenu net illisible | **Leclerc a changé** (§ 3.3) |
| `EXCEPTION` | Incident imprévu de l'agent (jamais son message) | Ouvrir une issue avec le rapport du diagnostic |

## 2. Avant de commencer

Noter, sans donnée personnelle : la date, le titre du message et son code, la version de l'application (menu → « Version et mode d'emploi »), qui est touché (une personne ou toute la famille), et si ça marchait la veille.

## 3. Contrôler : le diagnostic

### 3.1 Le diagnostic de l'agent (toujours disponible)

C'est le contrôle de premier niveau. Il est en **lecture seule** : il ne met rien au panier.

1. Sur le téléphone concerné, dans l'application, ouvrir le **drive Leclerc** (menu de Repas → « Ouvrir le drive Leclerc » : dans la pré-version de test, le bouton ⋮ au bord droit de l'écran ; dans l'application de publication, « Moi » → section « Drive » → « Menu de l'application » (DRV-320, repas-front#111) : visible seulement dans l'application et **fonction allumée**), se connecter si besoin, choisir son magasin et aller sur une page de magasin (faire une recherche, par exemple « farine »).
2. Sur l'écran du drive, toucher le bouton rond **⋮** en bas à droite, puis **« Diagnostic de l'agent »**. Les contrôles défilent (une seule recherche, celle du mot « sel », avec la cadence normale).
3. Toucher **« Copier le rapport »**, puis le coller dans la conversation. Le rapport ne contient ni cookie, ni identifiant, ni adresse, ni nom, ni numéro de magasin complet (seulement `…` et les trois derniers chiffres) ; relire avant de coller.

Lecture du rapport (chaque ligne : `OK`, `KO` avec un code, ou `NON TESTÉ`) :

| Contrôle | Si c'est `KO` ou inattendu |
|---|---|
| Version de l'agent | Information : la noter (elle compte pour § 7). |
| Page prise en charge | `UNSUPPORTED_PAGE` : pas sur une page de magasin du site ordinateur ; voir § 1. |
| Origine de Repas dans l'agent | Dans l'application, `NON TESTÉ` (« pont de l'application : pas d'origine à vérifier ») est normal. |
| Stockage de session / Écran allumé (Wake Lock) | Dégradé mais pas bloquant (reprise après rechargement, écran qui peut s'éteindre). |
| **Recherche d'un mot courant** | **Le contrôle qui décide.** `KO CONTRACT_CHANGED` ou « aucun produit lu » / « seulement N produits avec tous les champs attendus » : **Leclerc a changé**. `KO BLOCKED` : anti-robot. `KO NOT_LOGGED_IN` : se reconnecter. `KO NETWORK` : réseau. |
| Contenu net des produits | « seulement N produits sur M » : le contenu net n'est plus lu ; l'envoi marche mais demande la quantité ligne par ligne. Dégradé, à corriger (§ 7). |
| Cadre de même origine | `NON TESTÉ` est normal (le choix du produit se fait dans la liste de l'agent, le site refuse les cadres). |
| **Requêtes du diagnostic** (agent 0.15.0) | Ligne de nombres : requêtes, erreurs, blocages, délais dépassés, réponses lentes, pause la plus longue (ms). Voir § 14. Information, jamais `KO`. |
| **Sondes du site** (agent 0.10.0) | Des formes, des noms et des nombres (adresse d'arrivée, code HTTP et taille de la recherche, formes de requête essayées « HTTP : a=… b=… », connexion lue). Elles servent à la personne qui corrige (§ 7), pas à décider ; elles ne contiennent aucune valeur du site. |

### 3.2 Le test automatique de l'application de débogage (pour confirmer l'écriture au panier)

Le diagnostic **ne teste pas l'ajout au panier**, qui est la partie la plus fragile (en-tête et adresse exacts). Le **test automatique** de l'application de **débogage** le fait, en un seul geste, et rend un seul rapport :

- il lance le diagnostic, lit le magasin, fait un lot de 12 recherches, puis un essai au panier : **3 produits au plus, 3 exemplaires au plus**, remis à zéro aussitôt, et compare le panier avant et après ;
- il s'arrête dès qu'il est bloqué ; la cadence est celle de l'agent, jamais accélérée ;
- le rapport commence par `Verdict : OK` ou dit quelle étape a échoué, et si des produits sont à retirer du panier à la main.

Pas à pas complet pour l'utilisateur : `android/PROCEDURE.md` du dépôt front (installer la pré-version `android-dev-AAAA-MM-JJ`, se connecter à Leclerc, aller sur une page de magasin, toucher « Test automatique », coller le rapport).

À savoir avant de le lancer :
- il **touche au vrai panier** de l'utilisateur (ajout puis retrait) : à faire sur son propre téléphone et son propre compte, jamais depuis un serveur ni un émulateur ;
- l'APK de débogage et l'APK de publication ont la même identité mais **pas la même clé** : installer l'un demande de **désinstaller** l'autre (la connexion à Leclerc dans l'application est alors effacée). Préférer un téléphone de test ; sinon réinstaller ensuite l'application de publication ;
- la pré-version de débogage est publiée sur demande (workflow `Android`, lancé à la main) ; ce n'est jamais une version de publication.

### 3.3 Conclure

| Constat | Conclusion | Suite |
|---|---|---|
| Recherche `KO CONTRACT_CHANGED` (ou aucun produit lu) **deux fois**, à quelques minutes d'intervalle, ou ajout au panier en échec au test automatique | **Leclerc a changé** | Couper (§ 4), corriger (§ 7) |
| `KO BLOCKED` | L'anti-robot a réagi. Ne pas insister, ne pas relancer en boucle, ne jamais accélérer la cadence | Attendre (plusieurs heures). Si ça dure plus d'un jour ou touche plusieurs proches : couper (§ 4) et rouvrir le sujet |
| `KO NOT_LOGGED_IN` ou `KO NETWORK` | Pas un incident de Leclerc | Se reconnecter / réessayer |
| Tous `OK` (hors `NON TESTÉ`) et l'envoi échoue quand même | L'écriture au panier (que le diagnostic ne teste pas), le plan ou le protocole, ou une application trop ancienne (`INVALID_MESSAGE`) | Lancer le test automatique (§ 3.2) ; vérifier les versions (application, front, back) ; ouvrir une issue avec le rapport |

## 4. Couper tout de suite

Décision de l'utilisateur ; un agent l'accompagne une étape à la fois.

1. **But** : que plus personne ne puisse envoyer de liste (aucune liste ne passe en `SENT`), le temps de réparer. Rien n'est effacé.
2. **Lancer** (l'utilisateur, depuis le dépôt `kcharignon/repas`) :
   `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh`
   Le script écrit `DRIVE_ENABLED=false` dans `/opt/repas/.env`, redémarre le conteneur `php` (quelques secondes de coupure, comme un déploiement) et vérifie.
3. **Ce qu'on doit voir** : `OK : DRIVE_ENABLED=false, le conteneur php le voit et /api/v1/health répond.`
4. **Contrôle** (lecture seule) : `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'cd /opt/repas && docker compose exec -T php printenv DRIVE_ENABLED < /dev/null'` affiche `false`.

Ce qui change ensuite :
- le front lit `enabled: false` : il n'offre plus l'envoi, et les réglages du drive disent que la fonction n'est pas activée (comportement prévu par DRV-403 et DRV-404, à contrôler à la recette DRV-505) ;
- `send` et les routes du drive répondent `404 DRIVE_DISABLED` ; `reopen` continue de répondre (ADR 0023 §9) ;
- les **listes déjà envoyées** (`SENT`) restent **lisibles** et peuvent être **terminées** ou **supprimées** ; elles restent verrouillées (non modifiables) tant qu'elles sont envoyées, mais **chacun peut rouvrir la sienne** depuis l'interface même fonction coupée (`reopen` n'est pas derrière l'interrupteur) ;
- les produits mémorisés, les réglages de magasin et les listes sont **intacts** ; l'application et l'agent ne sont pas touchés.
- dans l'application de publication, « Moi » → « Drive » (donc « Menu de l'application », donc « Se déconnecter de Leclerc ») disparaît tant que la fonction est coupée ; la pré-version de test garde son bouton ⋮ (point signalé à l'utilisateur, ticket DRV-320).

**Rouvrir en bloc les listes envoyées** (utile surtout avant de remettre une version du code qui ne connaît pas `SENT`, qui n'a pas de `reopen` ; pour une simple coupure, chacun rouvre sa liste) : script `rouvrir-listes-envoyees.sh`, d'abord sans argument (lecture seule), puis avec `--appliquer` après accord : voir [`../production.md`](../production.md), « Listes `SENT` ». Il fait une sauvegarde avant.

### Couper une seule enseigne (DRV-702)

Quand le site d'une seule enseigne change ou bloque et que l'autre fonctionne : retirer **son** identifiant de `DRIVE_RETAILERS` dans `/opt/repas/.env` (l'humain, à la main tant qu'aucun script ne le fait ; `DRIVE_RETAILERS=` vide éteint toutes les enseignes, la ligne absente vaut `leclerc`), recréer le conteneur `php` (`docker compose up -d --wait php`), contrôler en lecture seule : `docker compose exec -T php printenv DRIVE_RETAILERS`. L'enseigne éteinte répond `404 DRIVE_RETAILER_DISABLED` et n'est plus proposée (`retailers`) ; ses réglages, produits et préférences restent en base ; les listes `SENT` se rouvrent comme avant. L'autre enseigne ne bouge pas. Rallumer = la remettre dans la liste.

## 5. Se replier : le mode assisté (« Chercher à la main » à l'écran)

Le **mode assisté** est le repli permanent prévu par l'ADR 0022 : Repas affiche la liste, la personne ouvre Leclerc (application ou navigateur) et cherche elle-même chaque produit, sans remplissage automatique ; l'écran d'envoi propose, **hors de l'application** (dans un navigateur ordinaire) et **fonction allumée**, un lien « Chercher sur Leclerc » par ligne (DRV-404, `driveSend.notInApp`). Rien n'est mémorisé dans ce mode et Repas n'envoie rien à Leclerc. **Fonction coupée**, l'écran d'envoi n'est pas offert : il n'y a alors pas de liens, la personne cherche seule sur le site de Leclerc (ou dans l'application : menu → « Ouvrir le drive Leclerc ») en lisant sa liste dans Repas.

- Une liste déjà envoyée reste lisible : elle sert de liste de courses telle quelle.
- Pour la modifier, la rouvrir (§ 4), ou en créer une nouvelle.
- Le panier Leclerc est celui du compte de la personne : Repas ne le vide jamais. Vérifier son contenu avant de finaliser la commande.

## 6. Prévenir

- **La famille qui utilise l'envoi** : un message court, sans jargon. Exemple : « L'envoi de la liste au drive est coupé le temps de réparer : Leclerc a changé son site. Tes listes et tes produits mémorisés sont intacts. Pour les courses, ouvre Leclerc et prends ta liste dans Repas. Je te préviens quand c'est de nouveau possible. »
- **Si l'application doit être mise à jour** (§ 7) : dire à chacun d'installer la nouvelle version quand elle est annoncée. Distribuée hors Google Play, l'application ne se met pas à jour toute seule ; quand la publication signée sera en place (DRV-315), elle indique « Une nouvelle version est disponible ».
- **Suivi** : ouvrir (ou compléter) une issue du chantier (label `drive`) dans le dépôt concerné, `kcharignon/repas-front` pour l'agent et l'application, `kcharignon/repas` pour le back, avec le rapport du diagnostic (sans donnée personnelle) et la date.
- **Compte suspendu** : voir § 1. L'utilisateur concerné prend contact lui-même avec Leclerc.

## 7. Corriger (réservé à la personne qui maintient le code)

L'agent n'a **aucun code distant** : une correction est une **nouvelle version de l'application**, qui embarque le nouvel agent. Le travail se fait dans le dépôt front (`kcharignon/repas-front`, dossiers `drive-agent/` et `android/`). Compétence : skills `repas-agent-dev` et `repas-android-dev` de ce dépôt.

### 7.1 Obtenir un relevé de ce que Leclerc envoie maintenant

On ne devine rien et on ne contacte pas Leclerc : l'utilisateur relève lui-même, sur **son ordinateur** (Chrome, connecté à son compte), ce que le site envoie. Procédure du relevé d'origine : [`spikes/DRV-007-requete-ajout-panier.md`](spikes/DRV-007-requete-ajout-panier.md).

1. Ouvrir une page de son magasin sur leclercdrive.fr, ouvrir les outils du navigateur (F12), onglet **Réseau**, filtre **Fetch/XHR**.
2. Refaire le geste qui échoue : une recherche (« farine »), ou le **+** d'un produit (c'est un vrai ajout à son panier : le retirer ensuite avec **−**).
3. Clic droit sur la requête `recherche.aspx` ou `panier.aspx` → « Copier » → « Copier en tant que fetch ».
4. **Avant de le partager**, remplacer par `[masqué]` : toute valeur de `Cookie`, de jeton ou d'en-tête d'identification, le numéro de magasin, le point de livraison, le nom de la ville, et tout ce qui est personnel. Ne copier que la **forme** (adresse sans identifiant, en-têtes, structure du corps, structure de la réponse).

Si le problème est seulement un blocage (`BLOCKED`), aucun relevé n'est utile.

### 7.2 Corriger l'adaptateur seul

Tout ce qui est propre à Leclerc est dans `drive-agent/src/leclerc/` (`embedded.ts` lit les produits de la page, `cart.ts` construit la requête du panier, `content.ts` lit le contenu net, `errors.ts` classe les erreurs). Ne rien changer ailleurs sans raison.

1. Écrire d'abord un **test qui échoue** avec une **fixture synthétique** (`drive-agent/fixtures/leclerc/`) qui reproduit la **forme** changée : jamais de HTML brut de Leclerc, jamais un vrai numéro de magasin ni une vraie ville (valeurs factices `123456`, `Ville-Test`). Adapter aussi le faux site (`drive-agent/fake-leclerc/`) pour que le test de bout en bout reproduise la nouvelle forme.
2. Corriger l'adaptateur. En cas de doute sur une valeur, garder `null` ou `CONTRACT_CHANGED` plutôt qu'une valeur fausse. Ne jamais accélérer la cadence (800 ms + gigue, une requête à la fois, arrêt après deux refus).
3. Monter `AGENT_VERSION` dans `drive-agent/src/version.ts`.
4. Si le **plan** ou un **message du protocole** doit changer : trois endroits, dans cet ordre (voir [`consignes-agents.md`](consignes-agents.md)) : DTO du back et [`architecture.md`](architecture.md) § 8, schémas `drive-agent/protocol/*.schema.json` et exemples (front), puis `buildPlanMessage` et l'agent.

### 7.3 Reconstruire et vérifier

Dans le dépôt front : `make agent-test`, `make agent-e2e` (agent construit contre le faux site, jamais Leclerc), `make agent-verify` (construction reproductible), puis `make agent-build && make android-agent-hash` (note la nouvelle empreinte SHA-384 de l'agent dans `android/agent.js.sha384`, qu'il faut **commiter** ; sans cela la construction de l'application échoue), enfin `make check`. Si `android/` change : `make android-test`. Ouvrir la PR, attendre la CI verte, fusionner.

### 7.4 Publier la nouvelle application

0. **Si le protocole a changé, publier d'abord le front.** Le site web valide chaque résultat de l'agent et **rejette le message entier** s'il porte un champ qu'il ne connaît pas : le front répond « Réponse incomprise » et n'enregistre rien. Quand un changement ajoute un champ ou un code à `drive-agent/protocol/` (par exemple le champ `stats` de l'agent 0.15.0), l'humain publie **la release du front** (`vX.Y.Z` de `kcharignon/repas-front`, approbation du déploiement) **avant** l'étiquette `android-vX.Y.Z` de l'application qui embarque le nouvel agent. Le site se charge dans l'application à chaque ouverture : le déployer met à jour toutes les applications déjà installées. Concrètement pour l'agent 0.15.0 : le front `v0.5.2` puis `android-v0.1.5` (numéros décidés par l'humain). Vérifier avant la deuxième étape que le site en production a bien la nouvelle version ([`../production.md`](../production.md), « Contrôles »). Règle : [`architecture.md`](architecture.md) § 12.
1. **L'humain décide de l'étiquette** `android-vX.Y.Z` (un numéro plus grand que le précédent ; le compteur de version ne baisse jamais, sinon plus de mise à jour par-dessus). Ni un agent ni l'assistant ne la crée.
2. Le workflow construit l'APK **signé** avec la clé de l'utilisateur (secrets GitHub posés par lui, jamais vus par un agent), calcule son SHA-256 et publie la release avec `repas-drive.apk`, `repas-drive.apk.sha256` et `version.json` (ticket DRV-315, dépôt front). Tant que DRV-315 n'est pas fait, la publication signée n'existe pas : seules les pré-versions de débogage sont possibles.
3. **Vérifier l'empreinte** du fichier téléchargé : `sha256sum repas-drive.apk` doit donner la valeur de `repas-drive.apk.sha256`.
4. Sur chaque téléphone : télécharger et **installer par-dessus** (sans désinstaller : même clé de signature). Ouvrir menu → « Version et mode d'emploi » : la version est la nouvelle et la ligne « Agent : version …, empreinte … » donne la nouvelle version de l'agent (pas « absent » ni « empreinte inattendue »).

### 7.5 Version minimale de l'agent : elle n'existe pas

Le ticket DRV-402 prévoyait une constante `MIN_AGENT_VERSION` et une fonction `isAgentCompatible` pour refuser les résultats d'un agent trop ancien ; **elles n'ont pas été écrites** (vérifié le 2026-10-18 : `git grep MIN_AGENT_VERSION` ne trouve rien dans le front, et DRV-403 a retenu à la place le drapeau `drive` de `app.info`, qui dit seulement si l'agent est présent et intact). Il n'y a donc rien à relever après une correction : un téléphone qui n'a pas encore mis l'application à jour envoie un plan à l'ancien agent, qui s'arrête proprement (`CONTRACT_CHANGED` ou `INVALID_MESSAGE`) sans rien abîmer. Si on veut un jour que Repas refuse un agent trop ancien, il faut d'abord écrire cette fonction (nouveau ticket, côté front), puis la relever à chaque correction **après** avoir publié l'application, pas avant, sinon tout le monde est refusé.

L'ordre de publication du § 7.4 (étape 0) est ce qui évite le cas inverse : un agent récent dont le front ne comprend pas le résultat.

### 7.6 Faire réinstaller

Prévenir chacun (§ 6) et vérifier sa version (menu → « Version et mode d'emploi »). Tant qu'une personne a l'ancienne version, son envoi échouera proprement (`CONTRACT_CHANGED`) : sans danger, mais inutile.

## 8. Rallumer

Ne rallumer que lorsque **tout** ceci est vrai, dans l'ordre :

1. La nouvelle application est installée sur le téléphone de la personne qui maintient le code ; son **diagnostic** (§ 3.1) est `OK` partout (hors `NON TESTÉ`).
2. Le **test automatique** (§ 3.2) donne `Verdict : OK` et le panier est revenu à son état initial. Il passe par l'APK de débogage et ne dépend pas du serveur : il peut se faire fonction coupée. Si on ne peut pas le faire, le contrôle de l'étape 4 en tient lieu.
3. L'utilisateur décide de rallumer. Script avec `true` :
   `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh`
   Attendu : `OK : DRIVE_ENABLED=true, …`.
4. **Premier envoi réel court, par l'utilisateur lui-même** : une liste de 2 ou 3 lignes, **sans valider la commande Leclerc** (il vérifie le panier, puis le vide à la main s'il le veut). L'envoi ne peut se tester qu'allumé ; la famille n'est pas encore prévenue. S'il échoue, couper de nouveau (§ 4).
5. Prévenir la famille (§ 6), qui installe la nouvelle version si ce n'est pas fait.
6. Surveiller les premiers envois : demander de signaler tout « Le site a changé » ou « Leclerc a ralenti les requêtes ». Si ça recommence, couper de nouveau (§ 4).
7. Compléter le journal ci-dessous.

Si l'anti-robot bloque encore (`BLOCKED`) : ne pas rallumer pour tout le monde ; attendre, ou rester en mode assisté.

## 9. Ce qu'il ne faut jamais faire

- Contacter Leclerc depuis le serveur, ou y envoyer un agent d'IA, un script ou un navigateur automatique.
- Coller dans le chat, un ticket ou une PR un cookie, un jeton, un mot de passe, un code reçu par e-mail, un numéro de magasin complet ou une ville.
- Copier du HTML brut de Leclerc dans le dépôt (public) : les fixtures sont inventées.
- Accélérer la cadence, lancer deux requêtes en parallèle, insister après un blocage.
- Corriger en chargeant du code depuis un serveur : l'agent n'a aucun code distant (ADR 0022). Une correction passe par une nouvelle version de l'application.
- Allumer `DRIVE_ENABLED`, créer une étiquette de version ou approuver un déploiement à la place de l'utilisateur.

## 10. Journal des changements de Leclerc

À compléter à chaque incident (une ligne, sans donnée personnelle).

| Date | Symptôme (code) | Cause trouvée | Correction (PR) | Agent / application | Remis en service |
|---|---|---|---|---|---|
| | | | | | |

## 11. Symptôme « HTTP 400 » ou « Page introuvable » (recette du 2026-10-18)

- **Le diagnostic dit « Recherche d'un mot courant : CONTRACT_CHANGED, cause HTTP_400 »** : la recherche est demandée sur un chemin que le site refuse (page de départ sans suffixe de ville). Lance le diagnostic depuis une vraie page du magasin (« Commencer mes courses », puis une recherche du site) et lis les lignes « Chemin avec ville tiré de la page » et « HTTP : a=… b=… » (une ligne par forme essayée) : celle qui répond 200 est la forme à utiliser. Voir `hypotheses-site-reel.md` § 8.
- **Une page « Désolé, la page demandée n'a pas pu être trouvée » à l'ouverture d'un envoi** : l'adresse ouverte n'est pas celle d'un magasin ; l'application doit ouvrir `https://www.leclercdrive.fr/` (`NavigationPolicy.START_URL`, application `android-v0.1.4` et plus récentes ; les versions plus anciennes ouvraient `/magasin-N-N/` puis la racine du serveur du magasin, deux pages 404 du vrai site, § 8 de `hypotheses-site-reel.md`). Cette page est l'accueil de l'enseigne : l'utilisateur touche « Commencer mes courses » **à la main** (un bandeau le lui dit) et l'agent démarre tout seul sur la page du magasin. Le bandeau « Cette page n'est pas celle de ton magasin » et le bouton « Ouvrir l'accueil du drive » sont le repli quand le site refuse la page (statut 4xx ou 5xx), tarde (25 s) ou la sert sans agent (10 s). **Application plus ancienne que `android-v0.1.4`** : l'envoi s'ouvre sur une page 404 ; la mettre à jour.

## 12. Symptôme « Leclerc affiche son site mobile » (agent 0.14.0, PR front #137)

Le site mobile de Leclerc (`m-courses.leclercdrive.fr`) n'a pas les routes du site ordinateur : sa recherche répond 200 sans produit (DRV-011). L'application met donc la WebView du drive en **mode ordinateur** ; si Leclerc sert quand même le site mobile, l'agent et l'application le refusent tout de suite au lieu d'échouer plus loin.

- **Ce qu'on voit** : sur l'écran du drive, le bandeau « Leclerc affiche son site mobile : ce n'est pas la version ordinateur du site, et Repas ne peut pas y agir » avec le bouton « Ouvrir l'accueil du drive » ; ou, si l'agent est lancé sur cette page, l'erreur « Ce n'est pas la version ordinateur du site » (code `UNSUPPORTED_PAGE`, cause `PAGE_MOBILE`) ; le diagnostic écrit « site mobile (pas la version ordinateur) » à la ligne « Hôte » du rapport, et « Page prise en charge » est `KO` (`UNSUPPORTED_PAGE`, cause `PAGE_MOBILE`). La lecture du magasin ne rend jamais un hôte mobile : le magasin enregistré ne peut plus être faussé par ce site.
- **Ce n'est pas une panne de Leclerc** : ne pas couper pour tous. L'envoi n'a rien écrit.
- **Que faire** : toucher « Ouvrir l'accueil du drive » (recharge l'accueil de l'enseigne), puis « Commencer mes courses ». Si le site mobile revient : menu du drive (**⋮**) → « Se déconnecter de Leclerc », puis rouvrir le drive et se reconnecter. Si le bandeau revient à chaque fois, ouvrir une issue avec le rapport du diagnostic (version de l'application, ligne « Hôte »).
- **Piège à ne pas confondre** : « Ouvrir dans le navigateur » (menu du drive) ouvre Chrome sur le site **mobile** `m-courses`, sans la session de l'application. C'est normal et sans rapport avec l'envoi.
- **Avant l'agent 0.14.0** (applications `android-v0.1.4` et plus anciennes) : le site mobile n'était pas reconnu ; un envoi se terminait par « Ce n'est pas le bon magasin » ou « Le site a changé », et, d'après l'analyse de l'hypothèse H11 (jamais observé), la lecture du magasin aurait pu enregistrer un hôte mobile. Si un magasin enregistré a un hôte commençant par `m-`, le refaire lire dans une application récente.

## 13. La page de vérification de l'anti-robot : « Leclerc demande une vérification » (agent 0.13.0, PR front #136)

Leclerc protège son site par un anti-robot (DataDome). Quand il doute, il peut servir une **page de vérification** à la place de la réponse attendue. **La forme réelle de cette page n'a jamais été mesurée** (hypothèse H13).

- **Ce qu'on voit** : un bandeau en bas de l'écran du drive, « Leclerc demande une vérification », avec deux boutons « Reprendre » et « Arrêter ». La page de Leclerc reste visible et touchable derrière. Le message d'arrêt, lui, dit « Leclerc a ralenti les requêtes (CHALLENGE) ». Côté application, un bandeau du même titre apparaît aussi quand c'est la page d'ouverture qui répond 403 ou 429 : l'application laisse alors la page visible.
- **Que faire** :
  1. **Faire la vérification** dans la page de Leclerc, avec son doigt. Ne pas insister si elle se représente en boucle.
  2. Toucher **« Reprendre »** : l'agent refait la ligne en cours (ou les lignes qui attendaient). Ce qui est déjà au panier n'est pas touché.
  3. Si la page se recharge, l'agent propose de reprendre où il s'était arrêté : accepter.
  4. Si rien ne s'affiche, ou si la vérification revient plusieurs fois : toucher **« Arrêter »** (le résultat est « partiel », `BLOCKED`, cause `CHALLENGE`), attendre **plusieurs heures** et réessayer **une seule fois**. Ne pas changer de réseau pour contourner.
- **Quand couper pour tous** : comme pour `BLOCKED` (§ 1 et § 3.3) : plusieurs personnes touchées, ou plus d'un jour. Noter dans le journal du § 10 l'étape où le défi est apparu (sans donnée personnelle), c'est le relevé qui manque à H13.
- **À ne pas confondre** : un 403 ou 429 **sans** page de vérification est retenté une fois ; deux de suite arrêtent le passage (`BLOCKED` sans cause). Un délai dépassé (20 s sans réponse) est une erreur réseau (`NETWORK`, cause `REQUEST_TIMEOUT`), pas un blocage.

## 14. Les compteurs d'un passage (agent 0.15.0, PR front #138)

Pour savoir combien de requêtes un vrai passage envoie et à partir de quand Leclerc ralentit (hypothèse H12), l'agent compte, **en nombres seulement** (jamais une adresse ni un contenu) :

- **Dans le diagnostic** : la ligne « Requêtes du diagnostic : N (erreurs E, blocages B, délais dépassés D, réponses lentes L), pause la plus longue P ms ». À coller avec le rapport.
- **Dans le résultat d'un envoi** : le champ facultatif `stats: {requests, errors, blocks}` ([`architecture.md`](architecture.md) § 8). Il n'est visible que dans le rapport de résultat de l'application de **débogage** (« Requêtes : N (erreurs E, blocages B) ») ; le back ne le reçoit pas et l'application signée ne l'affiche pas.

Lecture : `erreurs` > 0 : réseau ou réponses d'erreur du site ; `blocages` > 0 : l'anti-robot a refusé (§ 13) ; « réponses lentes » > 0 ou pause la plus longue au-delà de 1 500 ms (800 ms plus la gigue maximale de 700 ms : une pause plus longue vient d'un ralentissement) : Leclerc a ralenti et l'agent a **espacé** ses requêtes tout seul (jusqu'à 3 000 ms de pause, puis retour à 800 ms à mesure que les réponses redeviennent rapides ; seuils **non mesurés** sur le vrai site, `architecture.md` § 8). À noter à chaque recette dans la fiche de `hypotheses-site-reel.md` § 7 (ligne 13) : nombre de lignes, durée, ces compteurs. Aucune valeur de ces compteurs n'est une limite connue de Leclerc.

## 15. « Auchan a changé » (squelette, à compléter par DRV-726)

Auchan est déclarée dans le registre du back (DRV-716) mais **éteinte** : `DRIVE_RETAILERS` ne la contient pas (par défaut `leclerc`). Tant qu'elle est éteinte, aucun utilisateur ne la voit (`404 DRIVE_RETAILER_DISABLED`, absente de `retailers`) et il n'y a rien à réparer côté utilisateur.

Ce qui est déjà vrai et vaut pour la suite (le reste viendra avec l'adaptateur et sa recette réelle, DRV-726) :

- **Couper Auchan seule** : la retirer de `DRIVE_RETAILERS`, comme décrit en § 4 « Couper une seule enseigne » ; Leclerc ne bouge pas, les réglages, produits et préférences d'Auchan restent en base.
- **Identifiants** : le magasin, les produits et les offres d'Auchan sont des UUID en minuscules (`docs/drive/hypotheses-site-reel-auchan.md`) ; une valeur qui n'en a pas la forme est refusée (`422 DRIVE_STORE_INVALID` ou `DRIVE_CHOICE_INVALID`).
- **Contrat** : la forme des identifiants et l'adresse constante d'Auchan sont dans `docs/drive/samples/retailers.sample.json`.

À compléter par DRV-726 : reconnaître le problème (symptômes et codes propres à Auchan), le diagnostic de l'adaptateur, le relevé de ce que le site envoie, la correction, le journal des changements d'Auchan, ce qu'il ne faut jamais faire.
