# Runbook : « Leclerc a changé » (et couper l'envoi au drive)

À suivre quand l'envoi de la liste au drive ne marche plus : message « Le site a changé », « Leclerc a ralenti les requêtes », paniers vides, produits qui ne se trouvent plus. Écrit pour une personne qui **n'a pas écrit le code** : chaque étape dit quoi faire et ce qu'on doit voir. Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Mise en production et retour arrière : [`../production.md`](../production.md), section « Envoi de la liste au drive ». Dernière mise à jour : 2026-10-18 (DRV-503).

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
| « Page non prise en charge » | `UNSUPPORTED_PAGE` | L'application n'est pas sur une page de magasin du site **ordinateur** (hôte `fdN-courses…`). Souvent : Leclerc sert son site **mobile** (`m-courses…`), dont les routes sont inconnues, parce que le mode ordinateur de l'application a été perdu. | Non : voir « Le site s'affiche en version mobile » plus bas |
| « Ce n'est pas le bon magasin » | `STORE_MISMATCH` | Repas a préparé la liste pour un autre magasin que celui ouvert. | Non |
| « Liste illisible » | `INVALID_MESSAGE` | L'application, le front et le back ne parlent pas la même version du protocole (une mise à jour manque). | Non : mettre l'application à jour, puis le front si besoin |
| « Envoi annulé » | `CANCELLED` | L'utilisateur a annulé. | Non |

- **Le site s'affiche en version mobile** (le nom commence par `m-`) : dans l'application, menu → « Se déconnecter de Leclerc et effacer les données du drive », puis rouvrir le drive.
- **Le compte Leclerc d'un proche est suspendu ou bloqué** : couper aussitôt pour tous (§ 4). Les conditions de Leclerc permettent à l'enseigne de suspendre un compte en cas d'usage jugé abusif. Le proche contacte lui-même le service client de Leclerc ; Repas ne contacte jamais Leclerc.
- **Le back ne voit rien** : il ne contacte jamais Leclerc et n'enregistre ni plan ni résultat dans ses journaux ([`architecture.md`](architecture.md) § 10). Le serveur n'a donc aucun signal de panne : la détection vient des messages de l'agent, du diagnostic et de ce que disent les utilisateurs.

## 2. Avant de commencer

Noter, sans donnée personnelle : la date, le titre du message et son code, la version de l'application (menu → « Version et mode d'emploi »), qui est touché (une personne ou toute la famille), et si ça marchait la veille.

## 3. Contrôler : le diagnostic

### 3.1 Le diagnostic de l'agent (toujours disponible)

C'est le contrôle de premier niveau. Il est en **lecture seule** : il ne met rien au panier.

1. Sur le téléphone concerné, dans l'application, ouvrir le **drive Leclerc** (menu de Repas → « Ouvrir le drive Leclerc » : dans la pré-version de test, le bouton ⋮ au bord droit de l'écran ; dans l'application de publication, « Mon compte » → « Menu de l'application », prévu par DRV-320 et à vérifier une fois fusionné), se connecter si besoin, choisir son magasin et aller sur une page de magasin (faire une recherche, par exemple « farine »).
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

**Rouvrir en bloc les listes envoyées** (utile surtout avant de remettre une version du code qui ne connaît pas `SENT`, qui n'a pas de `reopen` ; pour une simple coupure, chacun rouvre sa liste) : script `rouvrir-listes-envoyees.sh`, d'abord sans argument (lecture seule), puis avec `--appliquer` après accord : voir [`../production.md`](../production.md), « Listes `SENT` ». Il fait une sauvegarde avant.

## 5. Se replier : le mode assisté

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

1. **L'humain décide de l'étiquette** `android-vX.Y.Z` (un numéro plus grand que le précédent ; le compteur de version ne baisse jamais, sinon plus de mise à jour par-dessus). Ni un agent ni l'assistant ne la crée.
2. Le workflow construit l'APK **signé** avec la clé de l'utilisateur (secrets GitHub posés par lui, jamais vus par un agent), calcule son SHA-256 et publie la release avec `repas-drive.apk`, `repas-drive.apk.sha256` et `version.json` (ticket DRV-315, dépôt front). Tant que DRV-315 n'est pas fait, la publication signée n'existe pas : seules les pré-versions de débogage sont possibles.
3. **Vérifier l'empreinte** du fichier téléchargé : `sha256sum repas-drive.apk` doit donner la valeur de `repas-drive.apk.sha256`.
4. Sur chaque téléphone : télécharger et **installer par-dessus** (sans désinstaller : même clé de signature). Ouvrir menu → « Version et mode d'emploi » : la version est la nouvelle et la ligne « Agent : version …, empreinte … » donne la nouvelle version de l'agent (pas « absent » ni « empreinte inattendue »).

### 7.5 Version minimale de l'agent : elle n'existe pas

Le ticket DRV-402 prévoyait une constante `MIN_AGENT_VERSION` et une fonction `isAgentCompatible` pour refuser les résultats d'un agent trop ancien ; **elles n'ont pas été écrites** (vérifié le 2026-10-18 : `git grep MIN_AGENT_VERSION` ne trouve rien dans le front, et DRV-403 a retenu à la place le drapeau `drive` de `app.info`, qui dit seulement si l'agent est présent et intact). Il n'y a donc rien à relever après une correction : un téléphone qui n'a pas encore mis l'application à jour envoie un plan à l'ancien agent, qui s'arrête proprement (`CONTRACT_CHANGED` ou `INVALID_MESSAGE`) sans rien abîmer. Si on veut un jour que Repas refuse un agent trop ancien, il faut d'abord écrire cette fonction (nouveau ticket, côté front), puis la relever à chaque correction **après** avoir publié l'application, pas avant, sinon tout le monde est refusé.

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
