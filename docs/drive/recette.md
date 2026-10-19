# Recette en conditions réelles : envoyer une liste au drive Leclerc (DRV-505)

Procédure à dérouler **par l'utilisateur, sur son téléphone Android, avec son compte Leclerc et une vraie petite liste**, sans jamais valider de commande. Un agent (l'« assistant ») le guide **une étape à la fois**. Elle prouve ce que les tests automatiques ne peuvent pas prouver : l'anti-robot de Leclerc (DataDome), la connexion, le vrai site, le vrai téléphone. Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Si Leclerc bloque ou a changé : [`runbook.md`](runbook.md). Vie privée : [`note-vie-privee.md`](note-vie-privee.md). Dernière mise à jour : 2026-10-18 (ticket DRV-501 pour la rédaction, DRV-505 pour l'exécution) ; état, version minimale d'application et ordre de publication ajoutés le 2026-10-19.

**État au 2026-10-19 : en cours, non signée.** La recette a été déroulée par l'utilisateur sur son téléphone (un Pixel 9) les 2026-10-18 et 2026-10-19, avec des applications de `android-v0.1.1` à `android-v0.1.4`. **Les parties 3 à 8 sont faites, avec succès. Les parties 9, 10 et 12 restent à faire.** Les autres parties (1, 2, 11 et 13 à 17) ne sont pas renseignées ici. Le tableau de signature (partie 18) est vide. Les constats de ces séances sont dans [`hypotheses-site-reel.md`](hypotheses-site-reel.md) § 8 (statut des défenses en § 9) ; les corrections faites ensuite dans l'agent et l'application, dans [`roadmap.md`](roadmap.md) § M3c.

**Passages de ce fichier que la séance a rendus périmés** (à relire par l'assistant, pas encore réécrits : un ticket en cours, DRV-621, change les noms de boutons et de menus) : la partie 1.1 (au 2026-10-19 le back `v0.6.1` et le front `v0.5.1` sont en production, drive compris) ; les parties 1.6 et 2 (des applications **signées** `android-vX.Y.Z` existent depuis le 2026-10-18, DRV-315 : la recette n'a plus besoin d'une pré-version `android-dev-…`) ; l'encadré « À vérifier ici (DRV-319) » de la partie 6 (hypothèse réfutée, le constat est en 6.3) ; la liste « Écarts connus » de la partie 0 (DRV-613 partie 2, DRV-614 et DRV-315 sont livrés).

**Ce que cette recette suppose du vrai site sans l'avoir mesuré** : [`hypotheses-site-reel.md`](hypotheses-site-reel.md) (les cinq points à vérifier en premier, fiche de notes à remplir en route).

## Comment lire ce fichier

- Chaque étape a quatre lignes : **But** (pourquoi on le fait), **Geste** (ce que tu fais, exactement), **Attendu** (ce que tu dois voir), **Si ça diffère** (quoi faire). Fais une étape, regarde le résultat, puis seulement passe à la suivante. L'assistant te donne une partie à la fois.
- Les mots entre « » sont ceux de l'écran. Un texte exact peut avoir changé : le sens compte.
- **À vérifier** veut dire : l'étape décrit ce que prévoit un ticket qui n'était pas encore fusionné quand ce fichier a été écrit (liste en bas de la partie 0). L'assistant le confirme, ou corrige ce fichier, **avant** de te le faire faire.
- Tu n'as **jamais** besoin d'écrire un identifiant, un mot de passe, un code reçu par e-mail ou un cookie dans le chat. Si une étape te demande de coller un rapport, relis-le d'abord (il ne doit contenir ni adresse e-mail, ni mot de passe, ni numéro de magasin entier, ni nom de ville).

## 0. Avant de commencer

**Plan** : 1 prérequis · 2 installer l'application · 3 allumer l'interrupteur · 4 choisir le magasin · 5 préparer la liste · 6 premier envoi · 7 deuxième envoi · 8 panier Leclerc · 9 produits, estimation, verrou · 10 rouvrir, couper, terminer · 11 diagnostic · 12 vie privée · 13 robustesse (facultatif) · 14 blocage · 15 retour arrière · 16 critères · 17 mesures · 18 signature · 19 après.

**Ce qu'il te faut** : un téléphone Android avec Chrome ; ton compte Leclerc (identifiant, mot de passe, et ton téléphone ou ta messagerie si Leclerc envoie un code) ; ton compte Repas ; une bonne connexion (Wi-Fi ou données mobiles) ; environ **1 h 30** sans être dérangé. La partie 13 (essais de robustesse) est facultative ; la partie 14 sert en cas de blocage.

**Règles de la recette**

1. **Tu ne valides aucune commande.** Tu ne choisis aucun créneau, tu ne paies rien. Si Leclerc te propose « Valider mon panier » ou « Payer », tu t'arrêtes avant.
2. **Une seule personne à la fois.** L'interrupteur est global : pendant la recette, la fonction est visible par toute la famille. Préviens-les de ne pas l'utiliser avant la fin.
3. **Cadence lente, une seule fois.** L'application cherche les produits un par un, avec des pauses : c'est voulu. Tu ne relances **jamais** un envoi en boucle. Si Leclerc ralentit ou bloque, tu t'arrêtes (partie 14).
4. **Note l'état de ton panier Leclerc avant de commencer** (ce qu'il contient). Repas ne le vide jamais ; à la fin tu retires à la main ce qui vient de la recette si tu ne le veux pas.
5. **Aucun secret dans le chat ni dans le dépôt.** Le dépôt est public.
6. **Tu peux tout arrêter** à n'importe quelle étape (partie 15, retour arrière) : couper est sûr et immédiat.

**Où est le menu ?** Il y en a deux dans l'application de test.

| Menu | Où | Entrées utiles |
|---|---|---|
| Menu de Repas | petit bouton rond **⋮** au bord droit de l'écran Repas (version de test seulement) ; dans toute version : « Moi » → section « Drive » → « Menu de l'application » (visible seulement dans l'application, **fonction allumée**) | « Réglages du drive », « Ouvrir le drive Leclerc », « Version et mode d'emploi », « Se déconnecter de Leclerc et effacer les données du drive » |
| Menu du drive | bouton rond **⋮** en bas à droite de l'écran du drive (Leclerc) | « Test automatique (débogage) », « Abandonner l'envoi » (pendant un envoi seulement), « Version et mode d'emploi », « Se déconnecter de Leclerc et effacer les données du drive », « Ouvrir dans le navigateur », « Diagnostic de l'agent » |

**Ce que cette recette ne couvre pas** : la suppression du compte et sa purge (prouvées par les tests automatiques : `UserOwnedDataCoverageTest`, ADR 0021), la protection des fiches de produits partagées entre deux comptes (prouvée par `DrivePlanApiFlowTest::testASecondUserCannotRewriteTheProductOfTheFirst`, DRV-613 ; un essai à deux comptes de test est possible mais n'est pas demandé), la publication signée de l'application (DRV-315) et le lien vérifié `assetlinks.json` (partie 2, encadré « Plus tard »), l'émulateur (DRV-319).

**Écarts connus entre ce fichier et le code au moment de l'écriture** (à vérifier par l'assistant, partie 1 étape 1.1) :

- DRV-320 (bouton « Lire mon magasin dans l'application », entrée « Menu de l'application » de « Moi ») est **fusionné** (repas-front#111, agent 0.7.0) : la pré-version doit être construite après lui. Reste à essayer sur téléphone la **page de choix du magasin du vrai site** : l'application attend une adresse `/magasin-<id>-<id>` pour lancer la lecture, ce que seul le faux site a prouvé (étape 4.2).
- DRV-613 partie 2 (l'agent recalcule le nombre de produits avec le contenu lu en direct) : pas encore fusionné. Le message « contenu différent de celui mémorisé » n'est pas à attendre tant qu'il manque.
- DRV-614 (front, à faire) : le retour ne retiendra que les lignes du plan (option C du constat C1 de la revue du front), `DRIVE_LIMIT_REACHED` aura son message, et « Rouvrir » est proposé fonction coupée. Tant qu'il manque, l'écran du retour enregistre tout résultat portant le bon identifiant de passage, et un « Rouvrir » absent fonction coupée (étape 10.2) est un défaut connu.
- DRV-315 (APK signé) n'existe pas : **aucune release `android-v…` n'existe encore** (la clé de signature est une étape humaine). La recette se fait donc avec une **pré-version de test** `android-dev-…`, en essai, à désinstaller après (étape 1.6).
- L'adresse de la page panier de Leclerc n'est pas encore relevée (`BASKET_PATH` du front, DRV-501) : « Finaliser sur Leclerc » ouvre l'accueil du magasin, d'où le panier est à un geste. Tu la notes à l'étape 8.4.

## 1. Prérequis : le déploiement est stable et l'interrupteur est coupé

**Qui** : l'assistant contrôle en lecture seule ; l'utilisateur ne fait que les gestes marqués « toi ».

### 1.1 Les bonnes versions sont en production

- **But** : la fonction n'existe que si le back **et** le front qui la connaissent sont déployés. D'après `gh release list`, les dernières versions publiées au 2026-10-18 étaient le back `v0.5.0` et le front `v0.4.1`, **sans** le drive : il faut d'abord publier une nouvelle version de chacun (décision et approbation du déploiement : toi seul, `.claude/skills/repas-ops/references/release.md`).
- **Geste** (assistant, lecture seule) : `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'cd /opt/repas && cat state/ok-back state/ok-front'` puis `ssh … 'cd /opt/repas && docker compose exec -T php php bin/console doctrine:migrations:status --no-interaction < /dev/null | grep -E "Current|New"'` (commandes de [`../production.md`](../production.md), « Contrôles »). Il note les deux versions dans le tableau de signature (partie 18).
- **Attendu** : un back et un front plus récents que `v0.5.0` / `v0.4.1` ; `Current` = `Version20261018150000` (ou plus récente) et `New` = 0 ; `/api/v1/health` répond `200`.
- **Si ça diffère** : on s'arrête ici. L'assistant te guide pour publier les versions (une étape à la fois, `repas-ops`). Il vérifie aussi que la pré-version de l'étape 2.1 contient bien DRV-320 (fusionné, repas-front#111) et DRV-613 partie 2 (sinon il le note dans « Écarts relevés » du tableau de signature).

### 1.2 L'interrupteur est coupé

- **But** : partir d'un état connu. `DRIVE_ENABLED` est coupé par défaut ; tu l'allumeras toi-même à la partie 3.
- **Geste** (assistant, lecture seule) : `ssh … 'cd /opt/repas && docker compose exec -T php printenv DRIVE_ENABLED < /dev/null'`. Puis, **s'il dispose d'un compte de test en production** (il ne te demande jamais ton mot de passe, et n'en met jamais dans le chat) : `GET /api/v1/drive/settings` et, sur une liste de test, `POST /api/v1/shopping-lists/{id}/send`. S'il n'en a pas, il saute cette seconde partie : l'étape 1.3 montre le même comportement côté interface, et `make deploy-smoke` (rejoué par la CI) prouve que l'envoi est coupé par défaut.
- **Attendu** : `false` (ou ligne absente : coupé par défaut) ; `GET /drive/settings` répond `200` avec `"enabled": false` ; `send` répond `404` avec le message `DRIVE_DISABLED` (la route répond avant toute action : rien n'est modifié).
- **Si ça diffère** : `DRIVE_ENABLED` vaut `true` sans que tu l'aies décidé : couper tout de suite (runbook § 4) et chercher qui l'a allumé avant de continuer.

### 1.3 Ce que toi tu vois, fonction coupée

- **But** : vérifier que l'interface se tait quand la fonction est coupée.
- **Geste** (toi, dans un navigateur ou l'ancienne icône Repas) : ouvre « Moi », puis une liste qui a des articles, puis l'adresse `/drive/reglages` de Repas.
- **Attendu** : « Moi » n'a **pas** de rubrique « Drive » ; la liste n'a **pas** de bouton « Envoyer au drive » ; `/drive/reglages` dit « L'envoi au drive n'est pas encore activé : il n'y a rien à régler pour le moment. ».
- **Si ça diffère** : note ce que tu vois et dis-le à l'assistant ; ne continue pas.

### 1.4 Seuls les comptes attendus sont actifs

- **But** : les inscriptions sont ouvertes (une adresse e-mail confirmée suffit pour qu'un compte soit actif), et les fiches de produits sont **partagées** entre les utilisateurs d'un même magasin ([`revue-securite-back.md`](revue-securite-back.md), constat C1, atténué par DRV-613 partie 1). Mieux vaut allumer la fonction quand on sait qui peut l'utiliser.
- **Geste** (assistant, lecture seule, sans afficher d'adresse e-mail dans le chat) : il compte les comptes `ACTIVE` et te donne le nombre.
- **Attendu** : le nombre est celui que tu attends (toi et tes proches).
- **Si ça diffère** : un compte que tu ne connais pas est actif : décide avec l'assistant de le désactiver (réglages d'administration, ADR 0021) **avant** d'allumer.

### 1.5 Une sauvegarde récente existe et tu connais le bouton d'arrêt

- **But** : pouvoir revenir en arrière sans stress.
- **Geste** : l'assistant contrôle `ssh … 'ls -1t /opt/repas/backups | head -3'` (une sauvegarde de moins de 24 h). Toi, tu lis (ou tu demandes à l'assistant de te lire) les « Règles d'or » et le « § 4 Couper tout de suite » de [`runbook.md`](runbook.md).
- **Attendu** : tu sais que couper (`DRIVE_ENABLED=false`) est sûr, rapide et ne supprime rien.
- **Si ça diffère** : pas de sauvegarde récente : l'assistant en fait faire une avant d'allumer (`references/incident.md`).

### 1.6 Aucune version signée n'existe encore : la recette utilise une pré-version en essai

- **But** : savoir ce que tu installes et pourquoi. L'aide de Repas (« Installer l'application Repas ») dit de prendre une release dont le nom commence par `android-v` et d'ignorer les versions « dev » et « Pre-release ». Elle a raison pour la famille, mais **aucune release `android-v…` n'existe encore** (DRV-315 : la clé de signature se crée et se garde par toi, hors du dépôt). Une pré-version `android-dev-…` est publique, **débogable** et signée d'une clé de test publique : n'importe qui pourrait fabriquer un fichier de même nom. Elle ne convient donc que comme **essai**, avec les précautions ci-dessous (revue de sécurité du front, constats D2 et C6).
- **Geste** : tu acceptes ces règles. (1) Tu télécharges le fichier **seulement** depuis la page GitHub officielle `https://github.com/kcharignon/repas-front/releases`, tapée toi-même. (2) L'assistant a vérifié avant que l'empreinte SHA-256 du fichier publié est celle écrite dans la description de la release (étape 2.1). (3) Tu ne t'en sers que pour cette recette. (4) Tu la **désinstalles à la fin** (partie 19), puis tu installeras l'APK signé quand il existera.
- **Attendu** : tu sais que ce n'est pas la version définitive.
- **Si ça diffère** : tu préfères ne pas installer une version d'essai sur le téléphone qui sert aux courses : utilise un autre téléphone Android, ou attends l'APK signé (DRV-315) ; la recette est alors reportée.

## 2. Installer l'application de test

L'application « Repas » est **toute** l'application Repas (recettes, listes, drive) dans une coquille Android. Comme l'APK signé n'existe pas encore (DRV-315), tu installes la **pré-version de test** : même application, signée avec une clé de test publique, avec en plus deux entrées de menu de test.

### 2.1 L'assistant prépare une pré-version à jour

> **Version minimale d'application (constat du 2026-10-19) : `android-v0.1.4` (agent 0.11.0) ou plus récente.** Une application plus ancienne ouvre une **page 404** au début d'un envoi : `android-v0.1.0` ouvrait la racine du serveur du magasin, `android-v0.1.1` et `android-v0.1.2` l'adresse `/magasin-N-N/`, `android-v0.1.3` de nouveau la racine, trois pages introuvables sur le vrai site. Depuis `android-v0.1.4`, « Ouvrir le drive » ouvre l'accueil de l'enseigne (`https://www.leclercdrive.fr/`). L'assistant lit la version dans « Version et mode d'emploi » (étape 2.4) avant la partie 6.
>
> **Ordre de publication, à respecter avant toute nouvelle application** : si l'agent qu'elle embarque a changé le protocole (agent 0.15.0 : champ `stats` du résultat), l'humain publie **d'abord le front** (par exemple `v0.5.2`), **puis** l'application (`android-v0.1.5`) ; sinon le site rejette les résultats du nouvel agent (« Réponse incomprise ») et n'enregistre rien. Règle : [`architecture.md`](architecture.md) § 12, [`runbook.md`](runbook.md) § 7.4.

- **But** : tester le code le plus récent.
- **Geste** (assistant) : `gh release list -R kcharignon/repas-front --limit 5` : la plus récente `android-dev-AAAA-MM-JJ…` doit avoir été construite **après** la fusion de DRV-320 (repas-front#111) et de DRV-613 partie 2 (la page de la release donne le commit ; `git log` du front le confirme). Sinon il lance le workflow `Android` à la main, depuis `main` seulement (`gh workflow run android.yml --ref main`) : c'est permis pour une pré-version de test, **jamais** pour une version `android-vX.Y.Z`, qui reste ta décision. Il note l'étiquette dans le tableau de signature. Il télécharge ensuite le fichier sur son ordinateur (`gh release download <étiquette> -R kcharignon/repas-front -p repas-drive-debug.apk`, dans un dossier à part) et compare son `sha256sum` avec « Empreinte SHA-256 » de la description de la release.
- **Attendu** : une pré-version à jour, avec `repas-drive-debug.apk` dans « Assets », dont l'empreinte SHA-256 est celle de la description.
- **Si ça diffère** : pas de fichier `.apk` : relancer le workflow, regarder son journal (`gh run list --workflow Android`). Empreinte différente : **ne pas installer**, le dire à l'utilisateur (le fichier a été remplacé ou abîmé).

### 2.2 Installer le fichier

- **But** : avoir l'application sur le téléphone.
- **Geste** : sur le téléphone, ouvre Chrome et va sur `https://github.com/kcharignon/repas-front/releases`. Ouvre la version la plus récente dont le nom commence par `android-dev-` (étiquette « Pre-release »). Touche `repas-drive-debug.apk` dans « Assets ». Quand le fichier est téléchargé, touche « Ouvrir ». Si Android dit qu'il n'autorise pas l'installation depuis Chrome, touche « Paramètres » et active « Autoriser depuis cette source », puis reviens en arrière. Touche « Installer », puis « Ouvrir ».
- **Attendu** : l'application « Repas » s'ouvre sur la page de connexion de Repas.
- **Si ça diffère** : Play Protect avertit parce que l'application ne vient pas de Google Play : touche « Plus de détails », puis « Installer quand même » (seulement si tu as bien pris le fichier sur la page GitHub de Repas). « Application non installée » ou conflit : désinstalle d'abord l'ancienne application « Repas » ou « Repas Drive » (appui long sur l'icône, « Infos sur l'appli », « Désinstaller »), puis recommence. L'application de test « Banc Repas » est différente et peut rester. *La page d'aide de Repas (« Installer l'application Repas ») dit d'ignorer les versions « dev » et « Pre-release » : c'est le bon conseil pour la famille une fois l'APK signé publié, mais pour cette recette c'est justement une pré-version qu'on installe.*

### 2.3 Te connecter à Repas

- **But** : vérifier que Repas fonctionne dans la coquille comme sur le site.
- **Geste** : connecte-toi avec ton adresse e-mail et ton mot de passe de Repas.
- **Attendu** : tes listes et tes recettes sont là, comme sur le site. Tu peux fermer l'application et la rouvrir : tu restes connecté.
- **Si ça diffère** : page d'erreur « Repas ne répond pas pour le moment » : touche « Réessayer ». Toujours rien : le problème vient du réseau ou du site, pas du drive ; dis-le à l'assistant.

### 2.4 Lire les versions

- **But** : écrire dans le tableau de signature ce qui est testé.
- **Geste** : touche le bouton **⋮** au bord droit de l'écran, puis « Version et mode d'emploi ».
- **Attendu** : une ligne du genre « Version 0.0.0-dev (numéro 123), construction a1b2c3d, débogage » et une ligne « Agent : version 0.7.0 (ou plus récente), empreinte … ». Recopie ces deux lignes dans le tableau de la partie 18 (elles ne contiennent aucune donnée personnelle). La fenêtre peut aussi dire si les données de Leclerc sont séparées de celles de Repas (profil séparé) : note-le. L'empreinte de l'agent doit commencer comme celle qui est écrite dans la description de la release.
- **Si ça diffère** : « Agent : absent de l'application » ou « empreinte inattendue » : réinstalle l'application ; si ça persiste, arrête (le fichier est abîmé ou ce n'est pas le bon). « Ta WebView est trop ancienne » : mets à jour « Android System WebView » dans le Play Store.

> **Plus tard, avec l'APK signé (DRV-315)** : quand l'utilisateur aura créé sa clé de signature (étape humaine : `android/README.md` du front, section « Signature »), posé les quatre secrets GitHub et décidé l'étiquette `android-vX.Y.Z`, et publié `https://kantin.eu/.well-known/assetlinks.json` (étape humaine, même README), on refait **uniquement** : désinstaller la version de test (la connexion à Leclerc dans l'application est alors effacée), installer l'APK signé, vérifier sa somme de contrôle (`sha256sum`, runbook § 7.4), contrôler que l'empreinte de l'agent (« Version et mode d'emploi ») commence comme celle de la release, qu'un lien `https://kantin.eu/…` reçu par e-mail s'ouvre dans l'application, et que « Moi » → « Drive » → « Menu de l'application » ouvre le menu (DRV-320, c'est le seul moyen de l'atteindre dans l'APK signé). Facultatif, avec le téléphone branché et l'assistant (recommandations de la revue de sécurité du front) : l'application n'est **pas** débogable ni sauvegardable (`adb shell dumpsys package eu.kantin.repas.drive | grep -i flags` ne contient ni `DEBUGGABLE` ni `ALLOW_BACKUP`) et, après un envoi, `adb logcat -d | grep RepasDrive` ne montre ni adresse, ni cookie, ni contenu. Les parties 5 à 9 ne sont pas à refaire.

## 3. Allumer l'interrupteur (toi seul)

- **But** : sans cela, « Envoyer au drive » n'existe pas. C'est une décision humaine (ADR 0017, ADR 0022 règle 10) : ni l'assistant ni un agent ne le fait.
- **Geste** : l'assistant t'explique le script (une ligne ajoutée dans le fichier `.env` du serveur, redémarrage du conteneur `php`, quelques secondes de coupure du site, comme un déploiement ; les connexions restent valables). Tu le lances toi-même, depuis le dépôt `kcharignon/repas` :
  `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh`
- **Attendu** : `OK : DRIVE_ENABLED=true, le conteneur php le voit et /api/v1/health répond.` Ensuite l'assistant relit `printenv DRIVE_ENABLED` (= `true`) et `GET /drive/settings` (`"enabled": true`).
- **Si ça diffère** : une ligne `ECHEC` : `ssh … 'cd /opt/repas && docker compose logs --tail 60 php'`, puis `references/incident.md`. Ne continue pas tant que le site ne répond pas.

## 4. Choisir ton magasin (lecture depuis l'application)

> **DRV-320 est fusionné** (repas-front#111) : le bouton « Lire mon magasin dans l'application » et le message `drive.setup` existent, mais la **page de choix du magasin du vrai site** n'a été essayée que sur un faux site. L'application attend, pour lancer la lecture, une page dont l'adresse est de la forme `/magasin-<id>-<id>`. Si, après avoir choisi ton magasin, l'agent n'affiche rien, **c'est le point à relever** (étape 4.2) : note ce que tu vois, sans l'adresse. Saisir l'adresse à la main n'est pas un contournement fiable (l'application n'affiche pas l'adresse du magasin, et Chrome sur téléphone montre le site mobile `m-courses…`, qui n'est pas le bon).

### 4.1 Ouvrir les réglages du drive

- **But** : dire à Repas quel magasin tu utilises.
- **Geste** : dans l'application, ouvre « Moi », puis « Réglages du drive ».
- **Attendu** : en haut, une note « Votre vie privée » avec un lien « Lisez la note complète » ; puis « Votre magasin » (« Aucun magasin enregistré. ») ; en bas « Application Repas », avec « Version … » et « Le drive est prêt dans cette application. ».
- **Si ça diffère** : « Le drive ne peut pas fonctionner dans cette version de l'application : mettez-la à jour » : l'agent est absent ou la WebView trop ancienne (étape 2.4). « Vous utilisez Repas dans un navigateur » : tu n'es pas dans l'application ; ouvre l'icône « Repas » installée à l'étape 2.2. « Moi » n'a pas de rubrique « Drive » : l'interrupteur n'est pas allumé (partie 3).

> *Point de vie privée n° 1* : lis ici le texte court, puis « Lisez la note complète » (elle s'ouvre dans « Installer l'application Repas », ancre « Votre vie privée avec le drive »). Elle doit dire que Repas ne voit ni ton identifiant, ni ton mot de passe, ni tes cookies, ni ton panier.

### 4.2 Lire ton magasin dans l'application

- **But** : que ce soit Leclerc qui dise quel est ton magasin (pas toi qui tapes une adresse).
- **Geste** : touche « Lire mon magasin dans l'application ». Le drive Leclerc s'ouvre sur un second écran, avec en haut le message « Connecte-toi à Leclerc si besoin, puis choisis ton magasin. Repas le lira ensuite. Le bouton retour du téléphone annule. ». Choisis ton magasin sur le site de Leclerc, puis **connecte-toi toi-même** (identifiant, mot de passe, et le code reçu si Leclerc le demande ; si un test anti-robot s'affiche, fais-le). Une fois sur une page de ton magasin, l'agent affiche « Enregistrer ce magasin dans Repas ? » avec « magasin n° … ».
- **Attendu** : Repas n'a rien demandé de ta connexion Leclerc : tu l'as saisie sur la page de Leclerc. Le message dit que « seul le magasin est transmis ». Tu touches « Enregistrer ».
- **Si ça diffère** : une page « version mobile » (le nom du site commence par `m-`) ou « Page non prise en charge » : menu du drive (**⋮** en bas à droite) → « Se déconnecter de Leclerc et effacer les données du drive », puis recommence (runbook § 1). « L'agent n'est pas prêt sur cette page » : attends la fin du chargement de la page. Aucun message de l'agent : il n'apparaît que sur une page de magasin ; fais d'abord une recherche (par exemple « farine ») pour y être. S'il n'apparaît toujours pas, la page n'a pas l'adresse attendue (`/magasin-<id>-<id>`) : arrête-toi, note-le (sans l'adresse) et touche le bouton retour du téléphone pour annuler la lecture (« Abandonner la lecture du magasin »). Un message « Lecture du magasin interrompue » à la réouverture de l'application se règle par « Reprendre » ou « Abandonner ».

### 4.3 Enregistrer le magasin dans Repas

- **But** : que Repas retienne le magasin.
- **Geste** : tu reviens dans Repas. Le formulaire dit « Magasin …456 lu dans l'application. Vérifiez, puis enregistrez. » (les trois derniers chiffres de **ton** magasin). Touche « Enregistrer mon magasin ». Facultatif : donne un nom au magasin (« Nom de votre magasin »).
- **Attendu** : « Magasin enregistré. » ; la rubrique « Votre magasin » montre « Magasin …xyz » (trois derniers chiffres). Le texte « C'est un autre magasin : les produits mémorisés pour l'ancien ne seront plus proposés… » n'apparaît que si tu changes de magasin plus tard.
- **Si ça diffère** : « Cette adresse n'est pas celle d'un magasin Leclerc Drive » : l'adresse lue n'a pas la forme `https://….leclercdrive.fr/magasin-<chiffres>-<chiffres>` ; ne la corrige pas à la main et dis-le à l'assistant (écart de contrat à corriger, ticket DRV-320). Erreur réseau : « Réessayer ».

> *Mesure à noter* : la lecture du magasin a-t-elle marché du premier coup ? Combien de temps (de « Lire mon magasin » à « Magasin enregistré ») ?

## 5. Préparer une vraie petite liste

### 5.1 Créer la liste

- **But** : avoir de quoi tester les cas utiles avec peu d'articles.
- **Geste** : dans Repas, crée une liste (ou garde ta liste en cours) de **6 ingrédients que tu achètes vraiment au drive**, de préférence : **I1** un poids (farine, sucre…), **I2** un volume (lait, huile…), **I3** un compté à l'unité (œufs…), **I4** un paquet ou une boîte (pâtes, conserve…), **I5** un frais plus difficile à trouver (un légume), **I6** un assaisonnement que tu as toujours (sel, poivre). Note le nom de ces 6 ingrédients pour la suite (ces noms ne sont pas des données sensibles, tu peux les citer à l'assistant).
- **Attendu** : la liste est « En cours », avec ses 6 articles et un bouton « Envoyer au drive ». Comme le drive est allumé et le magasin enregistré, un bloc « Estimation du coût » peut déjà s'afficher, avec « Aucun prix connu pour le moment : les prix se mémorisent à chaque envoi au drive. » : c'est normal.
- **Si ça diffère** : pas de bouton « Envoyer au drive » alors que la liste est « En cours » et non vide : l'interrupteur est coupé (partie 3). Le bouton mène aux réglages avec « Choisissez d'abord votre magasin… » : le magasin n'est pas enregistré (partie 4).

### 5.2 Noter le panier Leclerc

- **But** : savoir ce qui est à toi dans le panier, pour juger la recette sans te tromper.
- **Geste** : ouvre « Moi » → rubrique « Drive » → « Menu de l'application » → « Ouvrir le drive Leclerc » ; ouvre ton panier sur le site (le lien « panier » de la page) et note son contenu (lignes, quantités, total). Reviens (bouton retour du téléphone).
- **Attendu** : tu es déjà connecté à Leclerc (la connexion est gardée entre deux ouvertures). Le site s'affiche en version ordinateur (le nom du site ne commence pas par `m-`).
- **Si ça diffère** : tu dois te reconnecter : fais-le (il n'y a rien d'anormal). Version mobile : voir l'étape 4.2.

## 6. Premier envoi : aucun produit n'est encore connu (phase B)

> **À vérifier ici (DRV-319)** : l'application ouvre `https://<hôte du magasin>/` au début d'un envoi ; le faux site redirige cette racine vers l'accueil du magasin. Seul le vrai site le confirme : note ce qui s'affiche.

Au premier envoi, Repas ne connaît encore **aucun** de tes produits : toutes les lignes sont « à choisir ». L'agent travaille en deux temps : la **phase A** met au panier les produits déjà connus (rien à faire pour toi) ; la **phase B** te fait choisir un produit pour chaque nouvel ingrédient. Ici il n'y a que la phase B ; la phase A travaillera au deuxième envoi (partie 7).

### 6.1 Envoyer la liste

- **But** : verrouiller la liste et préparer le plan.
- **Geste** : sur la liste, touche « Envoyer au drive ». La fenêtre dit « Envoyer la liste au drive ? La liste ne sera plus modifiable. Vous pourrez la rouvrir. » : touche « Envoyer au drive ».
- **Attendu** : l'écran « Envoi au drive » s'ouvre (« Magasin …xyz »). Le résumé dit **0 produit connu · 6 à choisir · 0 déjà à la maison**. Chaque ligne indique « À acheter : … » et « Nouveau : vous choisirez le produit sur Leclerc. ». En dessous, selon la liste, l'estimation « Aucun prix connu pour le moment… ».
- **Si ça diffère** : « Choisissez d'abord votre magasin dans les réglages du drive » : reprends la partie 4. « Seule une liste en cours peut être envoyée » : active la liste (« Reprendre cette liste »). « La liste est vide » : ajoute un article. La liste est maintenant verrouillée : c'est voulu (on le vérifie à l'étape 9.3).

### 6.2 Passer une ligne dès maintenant

- **But** : essayer « Passer cette fois » depuis l'écran du plan.
- **Geste** : sur la ligne **I3**, touche « Passer cette fois ».
- **Attendu** : la ligne dit « Passé cette fois : rien ne sera mis au panier. » ; le résumé compte « Passés cette fois : 1 ».
- **Si ça diffère** : touche de nouveau pour annuler, puis recommence ; si ça ne bouge pas, note-le.

### 6.3 Ouvrir le drive

> **Constat de la recette du 2026-10-18** : « Ouvrir le drive » ouvre `https://www.leclercdrive.fr/`, qui affiche l'accueil de ton magasin avec « Commencer mes courses » ; un bandeau te dit de toucher ce bouton, puis l'agent démarre tout seul sur la page de ton magasin. L'adresse de la racine du serveur du magasin et `/magasin-N-N/` sont des pages 404 (voir `hypotheses-site-reel.md` § 8).

- **But** : lancer le passage de l'agent.
- **Geste** : touche « Ouvrir le drive ». L'écran du drive Leclerc s'ouvre ; l'agent affiche « Prêt à remplir ton panier » : « 5 nouveaux produits à choisir », « 1 ignoré », « Repas ne vide pas ton panier Leclerc. », « Rien n'est commandé ni payé. ». Touche « Commencer ». *Pendant tout le passage, ne quitte pas l'application ; l'écran reste allumé.*
- **Attendu** : rien ne part avant « Commencer ». Pas de phase A (aucun produit connu) : l'agent passe directement au premier choix.
- **Si ça diffère** : « Ouvre d'abord Repas » : l'agent n'a pas reçu le plan ; reviens dans Repas et refais « Ouvrir le drive ». « Ce n'est pas le bon magasin » (`STORE_MISMATCH`) : le magasin ouvert n'est pas celui des réglages ; dans l'application, change de magasin sur le site ou dans les réglages. « Tu n'es pas connecté » : connecte-toi à Leclerc, puis relance l'envoi.

### 6.4 Choisir les produits (phase B)

- **But** : mémoriser tes produits.
- **Geste** : l'agent affiche, ligne après ligne, une liste de produits proposés (« Il te faut : … »). Pour chaque ingrédient :
  - **I1 et I2** : touche « Choisir » sur le bon produit. L'agent dit combien de produits il faut (par exemple « 800 g demandés, 500 g par produit : 2 ») et le met au panier. Comme il n'y avait aucun produit pour cet ingrédient, il devient ton produit **préféré** sans te poser de question. *Note* si le bon produit était dans les trois premiers de la liste (mesure ci-dessous).
  - **I4** : touche « Passer cette fois ».
  - **I5** : touche « Passer cette fois » (même si le produit existe : on veut une couverture faible pour l'étape 6.7).
  - **I6** : touche « J'en ai toujours » (Repas ne mettra plus ce produit au panier et ne le redemandera plus).
  - Si l'agent demande « Vérifie la quantité » (« Combien de … pour … ? »), entre un nombre de 1 à 99 et touche « Valider », ou « Passer ».
- **Attendu** : à chaque choix, le produit est mis au panier **avec la bonne quantité**. L'écran de progression avance ; l'écran reste allumé ; il y a des pauses (environ une seconde) entre les gestes.
- **Si ça diffère** : « Leclerc n'a pas pu mettre ce produit au panier. Choisis-en un autre. » : choisis-en un autre. « Aucun produit trouvé pour … » : touche « Chercher autre chose » et essaie un autre mot. « Leclerc a ralenti les requêtes » (`BLOCKED`) : **arrête-toi** et va à la partie 14, ne relance pas. « Le site a changé » (`CONTRACT_CHANGED`) : arrête-toi et va à la partie 14.

> *Mesure à noter* : produits trouvés du premier coup = pour I1 et I2 (et I5 si tu l'avais choisi), combien avaient le bon produit parmi les trois premiers proposés, sans « Chercher autre chose » ?

### 6.5 La fin du passage

- **But** : revenir dans Repas avec le résultat.
- **Geste** : l'agent affiche « Panier rempli » avec des compteurs, puis touche « Retourner dans Repas ».
- **Attendu** : compteurs de l'agent proches de : **Ajoutés : 0 · Choisis : 2 · J'en ai toujours : 1 · Passés : 3 · Indisponibles : 0 · Échecs : 0**. Tu reviens dans Repas, sur l'écran « Retour du drive ».
- **Si ça diffère** : « Panier rempli en partie » ou « Envoi interrompu » : lis le code (runbook § 1) ; **ne relance pas tout de suite**. Repas affiche « Réessayer les échecs » si un échec reste : à utiliser une fois, pas en boucle.

### 6.6 Le récapitulatif dans Repas

- **But** : vérifier que Repas a retenu tes choix.
- **Geste** : lis l'écran « Retour du drive » sans rien toucher.
- **Attendu** : le titre « Le panier est rempli » ; un récapitulatif cohérent avec l'agent (**Choisis sur Leclerc : 2 · Déjà à la maison : 1 · Passés : 3**) ; le détail par ligne (« Choisi sur Leclerc, mémorisé comme produit préféré. », « Vous en avez déjà : mémorisé. », « Passé cette fois. ») ; « Vos choix sont enregistrés pour les prochains envois. » ; « Le panier Leclerc n'est pas vidé par Repas. ». Boutons : « Finaliser sur Leclerc », « Reprendre l'envoi », « Terminer la liste », « Rouvrir la liste ».
- **Si ça diffère** : « Vos choix n'ont pas pu être enregistrés » : touche « Réessayer l'enregistrement » une fois. « Ce résultat ne correspond pas à votre dernier envoi » : il est ignoré, rien n'est enregistré ; ouvre la liste et reprends l'envoi. « Aucun résultat à afficher » : la page a été rechargée avant l'enregistrement ; ouvre la liste, « Reprendre l'envoi ».

### 6.7 L'estimation après le premier envoi : couverture faible

- **But** : vérifier le seuil de couverture de 50 % (DRV-611, DRV-612).
- **Geste** : ouvre la liste (« Retour à la liste »). Regarde le bloc « Estimation du coût », puis touche « Voir le détail ».
- **Attendu** : sur les **5 lignes à acheter** (I6 « déjà à la maison » ne compte pas), **2** ont un prix (I1, I2) : « 2 articles sur 5 estimés » (si un contenu net n'a pas pu être lu, une ligne peut être « à vérifier » et sortir du total : ce n'est pas une erreur, note-le). Comme c'est moins de la moitié, le bloc **commence par** « Estimation peu fiable : moins de la moitié des articles ont un prix connu, le total ne couvre qu'une partie de la liste. ». Le « Total estimé » est la somme des 2 lignes estimées (nombre de produits × prix) ; la date est « D'après les prix vus le … » (aujourd'hui). Le détail range « Non estimées (3) » d'abord (« pas de produit choisi »), puis « Estimées (2) », puis « Déjà à la maison (1) », et dit « Ces articles ne sont pas dans le total : Repas ne devine jamais un prix. ».
- **Si ça diffère** : « Aucun prix connu » alors que tu as choisi des produits : l'agent n'a pas lu les prix ; note-le (mesure « prix lus »). Pas de bloc du tout : le magasin n'est pas enregistré ou la fonction est coupée.

> *Mesure à noter* : le temps du premier passage, de « Ouvrir le drive » au récapitulatif ; le nombre d'échecs.

## 7. Deuxième envoi : produits connus (phase A), puis nouveaux produits (phase B)

On rejoue la **même liste** : l'envoi se rejoue sans danger, car l'agent fixe la **quantité absolue** de chaque produit au panier (rien ne s'ajoute en double).

### 7.1 Reprendre l'envoi

- **But** : voir la phase A travailler seule.
- **Geste** : sur la liste (qui est « Envoyée au drive le … »), touche « Reprendre l'envoi ».
- **Attendu** : le plan est relu : **2 produits connus (I1, I2) · 3 à choisir (I3, I4, I5) · 1 déjà à la maison (I6)**. I1 et I2 montrent leur produit préféré (« 1. … ») avec le nombre de produits (« 2 produits »). La ligne I3 n'est plus « passée » (« Passer cette fois » ne se mémorise pas).
- **Si ça diffère** : une ligne connue est « à choisir » : le choix ne s'est pas enregistré à l'étape 6.6 ; note-le.

### 7.2 Changer un produit connu

- **But** : essayer « Choisir un autre produit » et la question du rôle du nouveau produit.
- **Geste** : sur la ligne **I2**, touche « Choisir un autre produit ». Elle devient « Vous choisirez un autre produit sur Leclerc, à la place de : … ».
- **Attendu** : le résumé passe à **1 produit connu · 4 à choisir**.
- **Si ça diffère** : touche de nouveau pour annuler le changement.

### 7.3 Ouvrir le drive : phase A

- **But** : vérifier que les produits connus sont mis au panier sans rien demander.
- **Geste** : touche « Ouvrir le drive », puis « Commencer ».
- **Attendu** : l'écran de progression montre « I1 : ajouté » sans question. Le panier garde **la même quantité** qu'au premier envoi (pas de doublon). Le préféré est pris tant qu'il est disponible.
- **Si ça diffère** : « I1 : plus disponible, à choisir ensuite » : normal si le produit n'est plus en stock ; l'agent passera au produit de secours s'il y en a un, sinon il te le fait choisir en phase B. « Vérifie la quantité » : la quantité n'a pas pu être calculée (contenu inconnu) ; entre-la. Un doublon de quantité (le double du premier envoi) : **arrête-toi** et dis-le à l'assistant, c'est un défaut grave.

### 7.4 Phase B : choisir le rôle du nouveau produit

- **But** : essayer le rôle (préféré, secours, une seule fois) et la recherche libre.
- **Geste** :
  - **I2** : touche « Choisir » sur **un autre produit** que le premier. L'agent demande « Que fais-tu de « … » ? ». Touche **« Produit de secours »**.
  - **I3** : touche « Chercher autre chose », tape un mot (par exemple « œufs plein air »), touche « Chercher », puis « Choisir » sur un produit.
  - **I4** : touche « Choisir » sur un produit de la liste.
  - **I5** : touche « Passer cette fois ».
- **Attendu** : pour I2, le nouveau produit est mis au panier ; le premier y reste aussi (Repas ne retire jamais rien). Les autres choix sont mis au panier avec la quantité annoncée. Pour I3, la recherche libre affiche « Résultats pour « … ».
- **Si ça diffère** : aucun résultat : essaie un mot plus simple. « Leclerc a ralenti les requêtes » : voir la partie 14.

### 7.5 Fin du deuxième passage

- **But** : lire le résultat.
- **Geste** : « Retourner dans Repas ».
- **Attendu** : agent proche de **Ajoutés : 1 · Choisis : 3 · J'en ai toujours : 1 · Passés : 1 · Indisponibles : 0 · Échecs : 0**. Dans Repas, « Retour du drive » : la ligne I2 dit « Choisi sur Leclerc, mémorisé comme produit de secours. » ; I1 figure parmi les produits ajoutés (avec « × quantité ») et le compteur « Prix lus » dit combien de prix ont été vus ; « Vos choix sont enregistrés pour les prochains envois. ».
- **Si ça diffère** : comme à l'étape 6.5 et 6.6.

> *Mesure à noter* : temps du deuxième passage (de « Ouvrir le drive » à « Retourner dans Repas »), dont le temps de la phase A seule ; nombre de lignes où l'agent a dû demander la quantité.

## 8. Vérifier le panier Leclerc

### 8.1 Ouvrir le panier dans l'application

- **But** : comparer ce que Repas dit avec ce qui est vraiment dans le panier de Leclerc.
- **Geste** : « Moi » → rubrique « Drive » → « Menu de l'application » → « Ouvrir le drive Leclerc », puis ouvre ton panier sur le site.
- **Attendu** : le panier contient (en plus de ce que tu avais noté à l'étape 5.2) : **I1** (produit préféré, quantité du plan) ; **I2** : le premier produit **et** le produit de secours choisi au deuxième passage (les deux : Repas n'en retire aucun) ; **I3** et **I4** (produits choisis, quantité annoncée). **I5** : rien ; **I6** : rien. Rien n'a été ajouté deux fois.
- **Si ça diffère** : une quantité fausse : note la ligne (produit, quantité attendue, quantité vue) ; si c'est une erreur d'arrondi (par exemple 1 500 g demandés avec des produits de 1 kg : 2), c'est le comportement prévu (arrondi supérieur). Un produit absent alors que l'agent le disait « ajouté » : note-le et refais le contrôle une fois, sans relancer d'envoi.

### 8.2 Retirer à la main ce que tu ne veux pas

- **But** : remettre le panier en ordre (Repas ne le vide jamais).
- **Geste** : retire le produit de secours de I2 si tu ne le veux pas, et tout ce que la recette a ajouté et que tu ne veux pas acheter.
- **Attendu** : le panier se met à jour.
- **Si ça diffère** : rien de particulier.

### 8.3 Finaliser sur Leclerc : s'arrêter avant de commander

- **But** : vérifier que le bouton de Repas mène à Leclerc, sans commander.
- **Geste** : retourne dans Repas (« Retour du drive » ou la liste), touche « Finaliser sur Leclerc ». *Tu ne valides rien.*
- **Attendu** : ton **navigateur** s'ouvre sur le site de ton magasin (page d'accueil du magasin). Il se peut que Leclerc te demande de te reconnecter : le navigateur et l'application ont chacun leur connexion. Ton panier est le même (il est lié à ton compte).
- **Si ça diffère** : le navigateur n'ouvre rien : note-le. Le bouton « Finaliser sur Leclerc » n'est plus là (le résultat du retour n'est gardé que pendant la visite : si Android a fermé la page de Repas, il disparaît) : ce n'est pas un défaut ; ouvre toi-même le site de Leclerc dans le navigateur.

### 8.4 Noter l'adresse de la page panier (pour le ticket DRV-501)

- **But** : que le front pointe un jour directement sur le panier (`BASKET_PATH`).
- **Geste** : dans le navigateur, ouvre ton panier. Lis l'adresse. Écris-la **en remplaçant** le numéro de magasin par `123456` et le nom d'hôte par `exemple.leclercdrive.fr` (la forme est `https://exemple.leclercdrive.fr/magasin-123456-123456/…`, suivie du chemin de la page) ; donne **seulement cette forme** à l'assistant.
- **Attendu** : tu as une adresse sans donnée personnelle.
- **Si ça diffère** : si l'adresse ne contient pas de chemin reconnaissable, décris-la avec des mots.

## 9. Mes produits, estimation, liste verrouillée

### 9.1 « Mes produits »

- **But** : vérifier ce que Repas a mémorisé (DRV-410).
- **Geste** : « Moi » → « Mes produits du drive ». Ouvre **I2**.
- **Attendu** : la liste montre **I1** (« Préféré : … »), **I2** (préféré + « 1 produit de secours »), **I3**, **I4** (préféré), **I6** (« Vous en avez toujours »). **I5** n'y est pas, et **rien d'autre que ce que tu as choisi** n'y figure (aucun produit ni ingrédient que tu ne reconnais pas). La fiche d'I2 montre « Produits acceptés » (du préféré aux produits de secours, avec « Monter », « Descendre », « Retirer », « Ne plus jamais prendre »), « Produits refusés » et « Oublier cet ingrédient ».
- **Si ça diffère** : un ingrédient manque : le choix n'a pas été enregistré (étape 7.5) ; note-le.

> *Point de vie privée n° 2* : cet écran ne montre que **tes** produits.

### 9.2 L'estimation complète

- **But** : vérifier le bloc « Estimation du coût » quand la couverture est bonne (DRV-611, DRV-612).
- **Geste** : ouvre la liste et lis le bloc. Compare « Total estimé » avec ce que la recette a mis dans le panier : le total du panier Leclerc après l'étape 8.2, moins le total que tu avais noté à l'étape 5.2.
- **Attendu** : sur 5 articles à acheter, **4 estimés** (I1 à I4) → « 4 articles sur 5 estimés » ; **pas** d'avertissement « Estimation peu fiable » (la couverture est de 80 %, au-dessus de 50 %) ; I5 est « Non estimée » (« pas de produit choisi »). La phrase « D'après les prix vus le … » (ou « entre le … et le … ») donne la date des prix. L'avertissement du back, « Estimation d'après les derniers prix vus ; le prix réel est celui du drive. », reste visible. Le « Total estimé » est du même ordre que le panier ; un petit écart est normal (promotion, prix qui a bougé). C'est **un ordre de grandeur daté, jamais un prix garanti**.
- **Si ça diffère** : un écart de plus de 10 % sans promotion évidente : note les lignes concernées (produit, prix estimé, prix vu dans le panier) ; c'est une anomalie à regarder. Un total qui ne bouge pas quand tu changes la liste : recharge l'écran.

> *Mesure à noter* : écart entre l'estimation et le panier, en euros et en pour cent.

### 9.3 La liste envoyée est verrouillée

- **But** : vérifier le verrou de l'ADR 0023.
- **Geste** : la liste est « Envoyée au drive le … ». Regarde ce que l'écran permet : ajouter un article, changer le nombre de personnes d'une recette, cocher une ligne, renommer la liste.
- **Attendu** : la liste est en lecture seule (« Cette liste est envoyée au drive : elle n'est plus modifiable. », cases désactivées, pas de « + » ni de « − », pas de « Renommer »). Les actions permises sont « Reprendre l'envoi », « Terminer », « Rouvrir la liste » et « Supprimer ».
- **Si ça diffère** : un article s'ajoute ou une ligne se coche : c'est un défaut (le verrou `SHOPPING_LIST_SENT` ne s'applique pas) ; note l'action exacte.

### 9.4 Corriger un ordre, refuser, oublier

- **But** : vérifier les gestes de gestion des produits. *Cette étape change ce que Repas retient : elle vient en dernier dans cette partie.*
- **Geste** : dans « Mes produits », sur **I2**, touche « Monter » sur le produit de secours, puis enregistre (« Produits enregistrés. »). Touche « Ne plus jamais prendre » sur un produit (il passe dans « Produits refusés »), puis « Accepter de nouveau » (il revient en dernier), puis enregistre. Sur **I4**, touche « Oublier cet ingrédient », puis « Oublier ».
- **Attendu** : « … : produits oubliés. » pour I4, qui disparaît de la liste. Les autres modifications sont conservées après un rechargement de la page. Sur la liste de courses, la ligne I4 redevient « pas de produit choisi » dans l'estimation (3 sur 5 estimés, 60 % : toujours pas d'avertissement).
- **Si ça diffère** : « Hors connexion » : réseau coupé ; recommence plus tard. Un bouton qui refuse : la limite de 5 produits acceptés ou de 20 refusés est atteinte.

## 10. Rouvrir, interrupteur coupé, terminer

### 10.1 Rouvrir la liste (interrupteur allumé)

- **But** : vérifier la réouverture explicite.
- **Geste** : touche « Rouvrir la liste » ; la fenêtre dit « Le panier Leclerc ne sera pas vidé. » ; confirme. Ajoute un article.
- **Attendu** : la liste est « En cours », modifiable, l'article s'ajoute. Si tu avais une autre liste en cours, elle est passée « En pause » (une seule liste active à la fois).
- **Si ça diffère** : « Cette liste n'est plus envoyée au drive » : elle était déjà rouverte (autre appareil) ; recharge.

### 10.2 Renvoyer, puis couper l'interrupteur

- **But** : vérifier qu'une coupure d'urgence ne fige pas les listes déjà envoyées (ADR 0023 §9, DRV-613).
- **Geste** : touche « Envoyer au drive » puis confirme ; sur l'écran du plan, **ne touche pas** « Ouvrir le drive » : retourne à la liste (« Retour à la liste »). Elle est « Envoyée au drive le … ». L'assistant t'explique le script, puis tu lances :
  `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh`
- **Attendu** : `OK : DRIVE_ENABLED=false, le conteneur php le voit et /api/v1/health répond.` Dans Repas (recharge la page) : « Moi » n'a plus de rubrique « Drive » (le « Menu de l'application » disparaît aussi ; le bouton ⋮ de la version de test reste) ; `/drive/reglages` dit que l'envoi n'est pas activé ; la liste reste « Envoyée au drive le … », lisible, **avec** « Rouvrir la liste » et « Terminer ». Touche « Rouvrir la liste » puis confirme : la liste redevient « En cours ». L'assistant peut le confirmer dans les journaux de la bordure (`docker compose logs --since 10m edge`, lecture seule) : l'appel `reopen` a réussi alors que le drive est coupé.
- **Si ça diffère** : « Rouvrir la liste » absent ou grisé interrupteur coupé : c'est un défaut du front ; note-le. Si la réouverture échoue en `404`, c'est le back (`reopen` ne doit pas être derrière l'interrupteur) : arrête et préviens.

### 10.3 Rallumer, renvoyer, terminer

- **But** : essayer « Terminer ». *Terminer une liste est ce que tu ferais après tes courses ; si tu veux garder cette liste telle quelle, fais ce dernier essai sur une petite liste de test à part (2 articles).*
- **Geste** : relance l'interrupteur (`'bash -s -- true'`, même commande qu'à la partie 3, attendu `OK : DRIVE_ENABLED=true`). Envoie la liste (« Envoyer au drive », confirme, retour à la liste), puis touche « Terminer » (sur l'écran « Retour du drive », le bouton s'appelle « Terminer la liste » et demande une confirmation : « La liste sera terminée. Le panier Leclerc ne sera pas vidé. »).
- **Attendu** : la liste est « Terminée » (« Courses terminées ») ; le panier Leclerc n'a pas changé.
- **Si ça diffère** : si « Terminer » est refusé pour une liste envoyée, c'est un défaut : note le message.

## 11. Mode diagnostic et test automatique

Ces deux contrôles servent aussi plus tard, quand Leclerc change son site (runbook § 3). Ici tu vérifies qu'ils marchent et que leur rapport ne contient rien de personnel.

### 11.1 Le diagnostic de l'agent (lecture seule)

- **But** : un contrôle rapide qui ne touche pas au panier.
- **Geste** : « Moi » → rubrique « Drive » → « Menu de l'application » → « Ouvrir le drive Leclerc », fais une recherche (par exemple « farine ») pour être sur une page de magasin. Touche **⋮** en bas à droite → « Diagnostic de l'agent ». Attends la fin, touche « Copier le rapport ».
- **Attendu** : chaque ligne est `OK`, `KO` ou `NON TESTÉ`. Normalement : version de l'agent (`OK`), page prise en charge (`OK`), origine de Repas (`NON TESTÉ` dans l'application : normal), stockage de session (`OK`), écran allumé (`OK` ou toléré), **recherche d'un mot courant (`OK`)**, contenu net des produits (`OK`, tous ou presque), cadre de même origine (`NON TESTÉ`, normal). Le rapport dit « Aucune écriture au panier. » et ne contient ni cookie, ni identifiant, ni adresse, ni numéro de magasin entier.
- **Si ça diffère** : lis la colonne « Si c'est KO » du runbook § 3.1. `KO CONTRACT_CHANGED` sur la recherche : Leclerc a changé. `KO BLOCKED` : anti-robot (partie 14).

### 11.2 Le test automatique (touche vraiment au panier, puis le remet)

- **But** : prouver l'écriture au panier (la partie la plus fragile), en un geste (DRV-318). Il n'existe que dans la version de test.
- **Geste** : sur une page de magasin, touche **⋮** en bas à droite → « Test automatique (débogage) ». Ne touche plus à rien : un écran de l'agent montre l'avancement (environ une minute). Ne le lance **qu'une fois**.
- **Attendu** : l'écran « Rapport du test automatique » s'ouvre, le rapport est déjà copié et commence par **« Verdict : OK »**. Il dit que le panier est « revenu à son état initial ». Il fait 12 recherches, puis 3 produits au plus en 3 exemplaires au plus, remis à zéro.
- **Si ça diffère** : le rapport dit « à retirer à la main » : retire les produits listés. « Arrêt : UNSUPPORTED_PAGE » : tu n'étais pas sur une page de magasin ; recommence **une** fois. « Arrêt : BLOCKED » : partie 14.

> *Point de vie privée n° 3* : colle le rapport à l'assistant seulement après relecture : il ne doit contenir ni adresse e-mail, ni mot de passe, ni code, ni nom de ville.

## 12. Points de contrôle de vie privée

À cocher à la fin de la recette, avec la [note de vie privée](note-vie-privee.md) sous les yeux (section « À garder vrai »).

| # | Point | Comment le voir | Attendu |
|---|---|---|---|
| 1 | La note est lisible depuis deux écrans | Réglages du drive (texte court + « Lisez la note complète »), puis « Installer l'application Repas » | Les deux existent et disent la même chose que `note-vie-privee.md` |
| 2 | Repas n'a jamais demandé ta connexion Leclerc | Tout le parcours | Tu t'es connecté **sur la page de Leclerc**, jamais dans un écran de Repas |
| 3 | L'application n'a qu'une permission | Android : appui long sur l'icône → « Infos sur l'appli » → « Autorisations » | Aucune autorisation accordée (Internet n'est pas listée : elle est implicite) |
| 4 | Le panier n'est jamais vidé ni réduit | Étapes 8.1 et 10.3 | Ce que tu avais avant y est encore ; les produits de secours ou remplacés restent |
| 5 | Une liste envoyée ne coche rien | Liste après l'envoi | Aucune ligne n'est cochée par l'envoi |
| 6 | Le serveur ne garde ni plan ni résultat dans ses journaux | **Assistant**, lecture seule : `ssh … 'cd /opt/repas && docker compose logs --since 3h php edge < /dev/null' \| grep -ci "leclercdrive"` | `0`, ou seulement des lignes que l'assistant sait expliquer ; aucun numéro de magasin, aucun nom de produit dans une adresse (le contenu d'une requête n'est pas journalisé) |
| 7 | « Oublier » efface bien | Étape 9.4 | I4 n'apparaît plus dans « Mes produits » ni dans le plan suivant |
| 8 | « Se déconnecter de Leclerc et effacer les données du drive » efface la connexion | **En dernier**, car il te déconnecte de Leclerc : menu du drive (**⋮**) → ce choix → « Effacer » | « Données du drive effacées. » ; le drive te redemande ton identifiant Leclerc ; **Repas** reste connecté (profils séparés). Sur un téléphone dont la WebView ne les sépare pas, la fenêtre prévient que Repas sera déconnecté aussi |
| 9 | Aucune requête de Repas vers Leclerc | Tout le parcours | Le back ne contacte jamais Leclerc : seuls l'application (sur ton téléphone) et ton navigateur le font, avec les services que les pages de Leclerc appellent d'elles-mêmes (anti-robot, mesure d'audience : comme dans un navigateur). L'assistant n'a **pas** ouvert Leclerc de son côté |
| 10 | Aucun secret dans le dépôt, le chat ni les rapports | Relecture du chat et des rapports collés | Aucun mot de passe, code, cookie, jeton, adresse e-mail, numéro de magasin entier ni ville |
| 11 | Ce que Repas a mémorisé est ce que tu as choisi | Étapes 6.6, 7.5 et 9.1 | Rien d'étranger dans « Mes produits » ni dans le récapitulatif. Limite connue : la page de Leclerc fait partie de la base de confiance de l'envoi et pourrait, en théorie, fausser un résultat que Repas enregistre aussitôt (revue de sécurité du front, constat C1) ; DRV-614 (à faire) limitera l'enregistrement aux lignes du plan |

## 13. Facultatif : essais de robustesse (20 minutes)

À faire seulement si les parties 6 à 11 sont réussies et si tu as envie de pousser. Chaque essai se fait **une fois**. Il te faut une liste « Envoyée au drive » : si ta liste est « Terminée » (étape 10.3), crée une petite liste de test de 2 articles et envoie-la comme à l'étape 6.1.

### 13.1 Abandonner un envoi

- **Geste** : liste « Envoyée » (renvoie-la si besoin) → « Reprendre l'envoi » → « Ouvrir le drive » → « Commencer ». Quand l'agent te demande de choisir un produit, touche **⋮** (menu du drive) → « Abandonner l'envoi » → « Abandonner ».
- **Attendu** : tu reviens dans Repas, « Passage arrêté : vous avez arrêté le passage : ce qui était déjà dans le panier y reste. » ; la liste reste « Envoyée ».

### 13.2 Fermer l'application en plein envoi

- **Geste** : relance un envoi comme ci-dessus ; à un choix de produit, ferme complètement l'application (liste des applications récentes, balaie-la). Rouvre-la.
- **Attendu** : une fenêtre « Envoi interrompu » propose « Reprendre » ou « Abandonner ». « Reprendre » rouvre le drive ; l'agent propose « Reprendre où tu t'étais arrêté ? » ; « Reprendre » ne refait pas les lignes déjà faites.

### 13.3 Couper le réseau

- **Geste** : relance un envoi jusqu'à l'écran « Prêt à remplir ton panier » de l'agent. **Avant** de toucher « Commencer », active le **mode avion** ; touche « Commencer » ; attends le message ; coupe le mode avion.
- **Attendu** : l'agent s'arrête sur « Le site ne répond pas » (`NETWORK`, après une seule reprise) ; dans Repas, le résultat est « partiel » ou « échoué », ce qui était déjà au panier y reste et « Réessayer les échecs » est proposé ; ce bouton, utilisé **une fois**, termine le travail.

## 14. Si Leclerc bloque (DataDome) ou a changé

Un blocage est **possible** : DataDome peut signaler le compte ou l'adresse de l'utilisateur. Le chantier l'a prévu (requêtes lentes, en série, à la demande ; l'agent s'arrête après deux refus de suite).

1. **Arrête-toi.** Ne relance pas, ne recommence pas « pour voir ». Ne change pas de réseau pour contourner.
2. Note **sans donnée personnelle** : l'étape, le message exact (« Leclerc a ralenti les requêtes », code `BLOCKED`, ou « Le site a changé », code `CONTRACT_CHANGED`), l'heure, et si ça marchait quelques minutes avant.
3. Suis [`runbook.md`](runbook.md) : § 1 (reconnaître), § 3 (diagnostic), puis **§ 4 (couper)** si ça touche plusieurs personnes, dure plus d'un jour, ou si tu as un doute.
4. Pour `BLOCKED` : attends plusieurs heures avant d'essayer de nouveau, une seule fois. Pour `CONTRACT_CHANGED` : l'assistant te guide pour le relevé et la correction (runbook § 7) ; la recette est **reportée** jusqu'à la nouvelle version de l'application.
5. Si le **compte Leclerc** lui-même est suspendu ou bloqué : coupe aussitôt pour tous (runbook § 4) ; c'est toi qui contactes le service client de Leclerc, Repas ne le fait jamais.
6. Dans le tableau de signature, écris « non signée » et la cause. Une recette où l'anti-robot bloque dès la première liste **n'est pas réussie**.

## 15. Retour arrière

À suivre si la recette échoue ou si tu veux tout arrêter. Du plus léger au plus lourd ; arrête-toi dès que c'est réglé.

1. **Couper** : `! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- false' < .claude/skills/repas-ops/references/ecrire-drive-enabled.sh`. Rien n'est effacé ; plus personne ne peut envoyer de liste.
2. **Les listes déjà envoyées** : chacun peut rouvrir la sienne depuis l'interface, même interrupteur coupé. Pour toutes d'un coup (utile surtout avant de remettre une version du back qui ne connaît pas le statut `SENT`) : script `rouvrir-listes-envoyees.sh`, **d'abord sans argument** (lecture seule), puis avec `--appliquer` après ton accord (sauvegarde automatique avant) : [`../production.md`](../production.md), « Listes `SENT` ».
3. **Remettre une version du back** : [`../production.md`](../production.md), « Retour arrière » (le schéma reste, c'est sans danger ; couper et rouvrir les listes **avant**).
4. **Le panier Leclerc** : retire à la main ce qui vient de la recette. Repas ne l'a pas vidé.
5. **Les données de la recette** : « Mes produits » → « Oublier cet ingrédient » pour chaque ingrédient testé ; la note de vie privée explique le reste.
6. **L'application de test** : désinstalle-la (appui long sur l'icône, « Infos sur l'appli », « Désinstaller »). C'est une pré-version publique et débogable (étape 1.6) : elle ne reste pas sur le téléphone. Sa connexion à Leclerc est effacée avec.

## 16. Critères de réussite

La recette est **réussie** si, et seulement si, **tout** ceci est vrai. Sinon elle est « non signée » avec la liste de ce qui manque.

1. Aucune commande n'a été validée ni payée ; aucun identifiant ou cookie n'a transité par Repas ou le chat.
2. Parties 6 et 7 : les deux envois vont au bout sans erreur bloquante ; les choix sont enregistrés (« Vos choix sont enregistrés pour les prochains envois ») et réapparaissent dans « Mes produits ».
3. La phase A du deuxième envoi met au panier les produits connus **sans rien demander et sans doubler les quantités**.
4. Le panier Leclerc (partie 8) correspond au récapitulatif de Repas, ligne par ligne, aux arrondis près.
5. L'estimation (étapes 6.7 et 9.2) est affichée, datée, avec l'avertissement sous 50 % de couverture et du même ordre de grandeur que le panier.
6. La liste envoyée est verrouillée, « Rouvrir » et « Terminer » marchent, y compris **interrupteur coupé** pour « Rouvrir ».
7. Le diagnostic est `OK` partout (hors `NON TESTÉ`) et le test automatique rend « Verdict : OK » avec le panier revenu à son état initial.
8. Les points de vie privée de la partie 12 sont tous cochés.
9. L'anti-robot n'a pas bloqué pendant la recette (aucun `BLOCKED`).
10. Aucune erreur `5xx` dans les journaux du serveur sur la période (`docker compose logs --since 3h php`, lecture seule par l'assistant), pas de rafale d'erreurs sur `PUT /api/v1/drive/choices`, l'espace disque du serveur (volume `pgdata`) n'a pas bougé de façon anormale (`df -h`), et la sauvegarde de nuit qui suit est réussie.

Cibles **indicatives**, non bloquantes ([`analyse.md`](analyse.md) § 1) : au moins 70 % des lignes à acheter ont le bon produit parmi les premiers proposés à la première liste ; de « Ouvrir le drive » à « Retourner dans Repas » **moins de 3 minutes** une fois les produits mémorisés (étape 7) ; **moins de 5 %** d'échecs.

## 17. Mesures à noter (pour le backlog)

Tu les remplis en route ; l'assistant les recopie dans les tickets du chantier (sans donnée personnelle).

| Mesure | Passage 1 | Passage 2 | Remarque |
|---|---|---|---|
| Lignes à acheter / lignes de la liste | | | |
| Produits trouvés du premier coup (bon produit dans les 3 premiers, sans recherche libre) | / | / | |
| Lignes passées / « j'en ai toujours » | | | |
| Temps de « Ouvrir le drive » à « Retourner dans Repas » | | | dont phase A seule : |
| Lignes où la quantité a été demandée (« Vérifie la quantité ») | | | |
| Produits dont le contenu net n'a pas pu être lu | | | |
| Échecs (codes `BLOCKED`, `NETWORK`, `CONTRACT_CHANGED`, autres) | | | |
| Prix lus (compteur « Prix lus ») | | | |
| Écart estimation / panier (euros, pour cent) | | | |
| Lecture du magasin : réussie du premier coup ? temps | | | |
| Diagnostic : lignes `KO` éventuelles | | | |
| Test automatique : verdict, durée | | | |
| Adresse de la page panier (forme, sans donnée personnelle) | | | |

Les idées qui viennent pendant la recette (un libellé peu clair, une étape trop longue, un produit toujours mal trouvé) se notent à part : elles alimentent le backlog (DRV-601 suggestions automatiques, DRV-606 changement de magasin…), pas la signature.

## 18. Tableau de signature

À remplir **à la fin**, par l'utilisateur (l'assistant recopie dans ce fichier par une PR). Aucune donnée personnelle : pas de numéro de magasin complet, pas de ville, pas d'adresse.

| Champ | Valeur |
|---|---|
| Date de la recette | |
| Version de l'application (ligne « Version … » de « Version et mode d'emploi ») | |
| Pré-version utilisée (étiquette `android-dev-…`) | |
| Version de l'agent (ligne « Agent : version … ») | |
| Version du back (`state/ok-back`) | |
| Version du front (`state/ok-front`) | |
| Téléphone (modèle) et version d'Android | |
| Version de « Android System WebView » (si connue) | |
| Parties réussies (1 à 12) | |
| Critères de réussite 1 à 10 | |
| Écarts relevés (étape, ce qui a été vu) | |
| `BLOCKED` ou `CONTRACT_CHANGED` rencontré ? | |
| Décision sur `DRIVE_ENABLED` à la fin | allumé / coupé |
| Résultat | **signée** / **non signée** |
| Signature de l'utilisateur | |

## 19. Après la signature

Ce que l'assistant fait ensuite, sauf les points marqués **Toi**, qui sont des décisions ou des gestes de l'utilisateur :

1. Recopier le tableau et les mesures dans ce fichier ; cocher DRV-505 dans [`roadmap.md`](roadmap.md) et passer son statut à « fait » (PR, date) ; renseigner `BASKET_PATH` (ticket du front) avec l'adresse relevée à l'étape 8.4.
2. **Toi** : décider de laisser `DRIVE_ENABLED=true` (la famille peut l'utiliser ; prévenir avec le message du runbook § 6) ou de le couper (`false`). L'ADR 0022 et l'ADR 0023 sont déjà `Accepted` ; si une règle a changé pendant la recette, les amender dans la même PR.
3. Fermer l'issue DRV-505 (`gh issue close 87 -c "Fait par la PR #…"`) puis DRV-501 quand le reste est fait (parcours dans l'émulateur, DRV-319 partie 2, et `BASKET_PATH`).
4. Mettre à jour `CLAUDE.md` (section « Chantier en cours » → « usage ») et le skill `repas-ops` si la procédure d'allumage a changé.
5. Ouvrir des tickets pour chaque écart de la recette (backlog ou correctif), un par PR.
6. **Toi** : désinstaller la pré-version de test du téléphone (étape 1.6 et partie 15), puis, quand il existera, l'APK signé (DRV-315 : clé, secrets GitHub, `assetlinks.json`) et la recette courte de l'encadré « Plus tard » de la partie 2. L'assistant propose alors de supprimer les anciennes pré-versions `android-dev-…` et les bancs `android-lab-…` publics (revue de sécurité du front, constat C6), avec ton accord.
