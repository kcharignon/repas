# Séance en direct : mettre Alexa en service avec le propriétaire

Écrit le 2026-10-25 pour l'**assistant**, pas pour l'utilisateur : il ne veut pas suivre une doc, il veut qu'on fasse les choses ensemble, en temps réel. L'assistant mène, **une étape par message**, dit le but en une phrase, donne UNE action (une commande `!` ou un clic précis), attend le résultat, vérifie de son côté, passe à la suite. Aucun secret dans le chat (ADR 0017). Détails d'exploitation : skill `repas-ops`, `references/alexa.md` et `references/acces-lecture-seule.md`.

Durée prévue : 1 h 30 à 2 h. Tickets couverts : VOX-001, VOX-002, VOX-501 (constat), VOX-602, et l'installation de l'accès SSH en lecture seule.

## Ce que l'utilisateur doit avoir sous la main

L'ordinateur (terminal du projet ouvert), le téléphone avec l'application Alexa **connectée au compte Amazon de l'Echo**, le mot de passe de ce compte Amazon, l'adresse et le mot de passe de son compte Repas, l'Echo allumé. Rien d'autre.

## Avant la séance (assistant, déjà fait ou à faire seul)

- PR du dialogue « produit inconnu » fusionnée, CI verte sur `main`.
- Releases du back et du front créées (`gh release create`), runs suivis jusqu'à l'attente d'approbation : l'utilisateur n'a plus qu'à cliquer. Version **1.1.0** (nouvelle fonction, rien ne casse).
- Je connais les valeurs à lui dire : adresse du webhook, adresse d'autorisation, adresse d'échange des jetons, identifiant de client, portée (tableau de l'étape 6).

## Étape 1 : mettre la version en production (5 min)

But : le serveur connaît la commande vocale (les tables `voice_*` et `spoken_phrase_choice` naissent à ce moment). Rien ne s'allume.

1. Lui dire : « GitHub → Actions → le run `v1.1.0` → *Review deployments* → *production* → *Approve*. » Une fois pour le back, une fois pour le site. Moi : `gh run watch`, puis contrôle de santé et migrations (`etat`, `rapport migrations` ou, tant que l'ancien accès SSH existe, `docker compose exec …`).
2. Vérifier : `ALEXA_ENABLED` absent ou `false`, `POST https://kantin.eu/api/voice/alexa` répond `404`.

Si le run échoue : le retour arrière est automatique ; lire le journal du run, ne rien improviser.

## Étape 2 : les six décisions (5 min, une question par message)

Poser D1 à D6 de l'ADR 0029 en français simple, une par message, avec la valeur par défaut déjà choisie. À la fin : ADR 0029 `Accepted`, case VOX-001 cochée (PR de ma part).

- D1 nom du skill (« Jarvis »). **Risque connu** : Amazon peut refuser le nom à l'étape 4 (règles françaises : pas d'article seul avec un autre mot, pas de verbe d'appel comme « ouvre » ou « demande »). Repli à proposer à ce moment : « mes emplettes », « courses maison », « ma liste du soir ».
- D2 réponses courtes, D3 produit inconnu (**réécrite le 2026-10-25** : trois propositions, mémoire, création guidée), D4 qui peut s'en servir, D5 aucune liste active, D6 quantité.

## Étape 3 : accès SSH en lecture seule (10 min)

But : qu'il n'y ait plus de clé root dans mes mains. Une seule commande `!` pour l'utilisateur :

```
! ssh-keygen -t ed25519 -N '' -C repas-lecture-seule -f ~/.ssh/repas_lecture_seule && ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 "bash -s -- '$(cat ~/.ssh/repas_lecture_seule.pub)'" < .claude/skills/repas-ops/references/installer-acces-lecture-seule.sh
```

Attendu : `OK : accès en lecture seule installé…`. Puis, une étape par message :

1. Il installe l'enveloppe locale, hors du dépôt (je ne peux pas la modifier en passant) : `! mkdir -p ~/repas-outils && install -m 755 .claude/skills/repas-ops/references/lecture.sh ~/repas-outils/lecture.sh`. Elle fixe la clé, l'hôte et les options ; la revue du 2026-10-25 a montré qu'une règle `ssh -i … deploy@hôte *` laisserait passer `-o ProxyCommand=…` placé après l'hôte.
2. Moi, trois essais : `~/repas-outils/lecture.sh aide`, `… etat`, et la preuve du refus côté serveur (`ssh -i ~/.ssh/repas_lecture_seule -o BatchMode=yes -o IdentitiesOnly=yes deploy@64.227.124.100 "ls /root"` → `Demande refusée`). Le premier essai déclenche une demande de permission : il choisit « Yes, and don't ask again » (la règle `Bash(~/repas-outils/lecture.sh *)`).
3. Il retire l'ancienne règle large `Bash(ssh * root@64.227.124.100 *)` (`/permissions`, onglet Allow ; je lui montre où cliquer).
4. **Les trois portes que la revue a trouvées**, une question chacune, en une phrase : (a) les règles `Bash(docker run *)` et `Bash(bash -e -c ' *)` sont des portes ouvertes (on peut monter `~/.ssh` dans un conteneur) : les retirer ? (b) interdire à l'assistant de lire ses clés (`deny` : `Read(~/.ssh/id_ed25519)`, `Read(~/.ssh/repas_deploy)`, `Bash(ssh *)`) ? (c) mettre une phrase de passe sur ces deux clés ? Il décide ; je ne modifie jamais mes propres permissions sans qu'il me le dise.

Si `deploy` n'existe pas ou ne peut pas lancer docker : lire le message, corriger le script (PR), ne pas élargir la clé.

## Étape 4 : le compte développeur Amazon et le skill (20 min)

But : Amazon sait où envoyer ce que dit l'Echo.

1. Vérifier le compte de l'Echo : application Alexa → *Plus* → *Paramètres* → *Compte Alexa* (l'adresse e-mail affichée). C'est ce compte qui doit ouvrir la console.
2. `https://developer.amazon.com/alexa/console/ask` → se connecter avec ce compte → accepter les conditions (usage personnel, pas commercial).
3. *Create Skill* → nom `Jarvis` → langue **French (FR)** → type *Other* / modèle **Custom** → hébergement **Provision your own** → modèle de départ **Start from scratch** → *Create skill*. Attendre « Full Build Successful ».
4. Mettre le fichier du modèle dans son presse-papiers **par PowerShell, jamais `clip.exe`** (il casse les accents : « à » devient « ├á » et Amazon refuse le modèle) : c'est moi qui le fais depuis WSL, `powershell.exe -NoProfile -Command "Set-Clipboard -Value (Get-Content -Raw -Encoding UTF8 -LiteralPath '<chemin Windows du fichier>')"`, puis je relis le presse-papiers (nom `jarvis`, `ListChoicesIntent` présent, aucun « ├ »). Console : *Build* → *Interaction Model* → *JSON Editor* → tout sélectionner, coller → *Save* → *Build skill* (selon la version de la console : *Save Model* / *Build Model*). Le nom d'invocation vient du JSON : **coller un ancien modèle renomme le skill**. S'il y a une erreur, il me lit la ligne rouge ; je corrige le fichier (PR) et on recommence.
5. *Build* → *Endpoint* : **HTTPS**, région par défaut `https://kantin.eu/api/voice/alexa`, certificat « *My development endpoint has a certificate from a trusted certificate authority* ». *Save Endpoints*. Il me donne l'**identifiant du skill** (`amzn1.ask.skill.…`, ce n'est pas un secret). **Revérifier cet écran après chaque collage du modèle** : le 2026-10-25 il est revenu seul sur « AWS Lambda ARN » avec la démo d'Amazon (voir « Si Alexa répond bizarrement »).

## Étape 5 : liaison de compte dans la console (10 min)

*Build* → *Account Linking*. Désactiver « Allow users to enable skill without account linking ». Puis :

| Champ | Valeur |
|---|---|
| Authorization Grant Type | Auth Code Grant |
| PKCE Authorization | activé |
| Your Web Authorization URI | `https://kantin.eu/alexa/lier` |
| Access Token URI | `https://kantin.eu/api/voice/oauth/token` |
| Your Client ID | `repas-alexa` |
| Your Authentication Scheme | HTTP Basic (Recommended) |
| Scope | `voice:shopping_list:write` |
| Domain List | vide (ajouter `kantin.eu` seulement si la page d'autorisation reste blanche) |

Le secret : il le fabrique **sans l'afficher** et le met dans son presse-papiers : `! openssl rand -base64 36 | tr -d '\n=+/' | clip.exe`, puis le colle dans *Your Secret*. *Save*. Il me lit alors les trois **Alexa Redirect URLs** affichées en bas (pas secrètes) ; on s'en sert à l'étape 6.

## Étape 6 : écrire la configuration sur le serveur (5 min)

But : le serveur connaît l'identifiant du skill, les adresses de retour et l'empreinte du secret (jamais le secret). **Dans son propre terminal, sans `!`** (la saisie du secret ne doit pas passer par la conversation) :

```
ssh -t -i ~/.ssh/id_ed25519 root@64.227.124.100 "$(cat .claude/skills/repas-ops/references/ecrire-alexa-env.sh)"
```

Il colle l'identifiant du skill, les trois adresses (séparées par des virgules, exactement comme affichées), puis le secret (toujours dans le presse-papiers, collé deux fois, sans écho). Attendu : `Écrit dans /opt/repas/.env … Empreinte du secret : xxxxxxxx…`. Je contrôle : `drapeau ALEXA_ENABLED` répond `false` et la santé reste `200`.

## Étape 7 : allumer (2 min)

`! ssh -i ~/.ssh/id_ed25519 root@64.227.124.100 'bash -s -- true' < .claude/skills/repas-ops/references/ecrire-alexa-enabled.sh` (clé root : c'est une écriture, elle reste à lui). Attendu : `OK : ALEXA_ENABLED=true…`. Le site coupe quelques secondes. Moi : `POST /api/voice/alexa` sans signature répond maintenant `400`, plus `404`.

## Étape 8 : lier le compte depuis le téléphone (10 min, VOX-501 constaté ici)

1. Application Alexa → *Plus* → *Skills et jeux* → *Vos skills* → onglet **Développement** → *Jarvis* → *Activer pour utiliser*.
2. La page d'autorisation de Repas s'ouvre : il se connecte, touche « Autoriser ». **Constat VOX-501** : la page s'ouvre-t-elle dans le navigateur d'Alexa, ou l'application Repas l'avale-t-elle ? Si l'application l'avale : noter ce qui se passe, corriger les liens d'application d'Android (dépôt de l'application) plus tard ; contournement du soir : ouvrir le lien depuis un autre navigateur.
3. Moi, pendant ce temps : `journal php 100` à la recherche de `voice` / `alexa`. Refus de requête Amazon = une catégorie (`chain`, `certificate`, `signature`, `application`…). Si `invalid_client` : secret ou identifiant différents entre console et serveur (étape 6). Si `invalid_grant` à l'échange : regarder si Amazon envoie `redirect_uri` (inconnue levée ici).

## Étape 9 : l'essai sur l'Echo (VOX-602, 20 min, un scénario par message)

Dans l'ordre, en regardant la liste dans l'application Repas :

1. Console, onglet *Test* → « Development » → écrire `ouvre Jarvis` puis `ajoute du pain` (trace JSON visible).
2. Echo : « Alexa, demande à Jarvis d'ajouter du pain ». Attendu : « C'est noté : pain. »
3. « Alexa, ouvre Jarvis » → « du pain de mie » → « C'est noté : pain de mie. » (aucune relance ; la séance reste ouverte sans question) → « stop ».
4. **Tomate** : « … d'ajouter des tomates » → *tomate* (pas *tomates pelées*).
5. **Produit proche** : « … d'ajouter du pain de campagne » (si *pain de mie* et *pain* existent mais pas *pain de campagne*) → « Je ne connais pas pain de campagne. Tu veux dire pain de mie, … ou un nouveau produit ? » ; choisir « le premier » ; recommencer la même phrase : doit tomber tout de suite sur le bon (mémoire). Une faute de frappe d'un produit existant (« ciboulete » avec *ciboulette*) est choisie sans question.
6. **Corriger une proposition** : « … d'ajouter des patates » (le catalogue n'a pas « patates ») → trois pâtes proposées ; répondre « pomme de terre » → « C'est noté : pomme de terre. Je m'en souviendrai. » ; recommencer « … d'ajouter des patates » : doit donner tout de suite la pomme de terre, sans question. **Variante, rien à corriger** : « … d'ajouter de la ciboulette » (rien de proche → « Je le crée ? ») puis « non, du pain » → « C'est noté : pain. » SANS « Je m'en souviendrai » ; plus tard « … de la ciboulette » doit reposer la question (la phrase n'est retenue que si des propositions ont été faites).
7. **Nouveau produit sans rien de proche** : « … d'ajouter de la ciboulette » (si elle n'existe pas) → « Je ne connais pas ciboulette. Je le crée ? » → « oui » → « Dans quel rayon ? Dis lesquels pour les entendre. » → **« lesquels ? »** (Alexa lit les rayons, puis « Lequel ? ») → « fruits et légumes » → « Quelle unité ? … » → **« lesquelles ? »** (les unités) → « pièce » → « Je crée ciboulette, … C'est bon ? » → « oui » → « C'est créé et noté : ciboulette, rayon fruit et légume. » (le rayon est répété) ; il est dans sa liste et dans ses ingrédients (vérifier le rayon et l'unité dans l'application). Variante : refuser à la confirmation (« non ») ne crée rien.
8. **Deux ingrédients de même nom** (à essayer avec deux « tomate » dans deux rayons, par exemple créés à la main dans l'application) : « … d'ajouter des tomates » → « Tomate : en fruit et légume ou en surgelé ? » → « en surgelé » → « C'est noté : tomate, surgelé. Je m'en souviendrai. » ; recommencer : directement le surgelé. Si l'un des deux est très acheté (habitude), aucune question.
9. **Liste envoyée au drive** : si possible, vérifier qu'Alexa crée une nouvelle liste et le dit.
10. **Quantité** (validée le 2026-10-25 sur le compte de l'auteur avec la version 1.1.5, type de créneau `QUANTITY`) : « … d'ajouter trois croquettes » → « C'est noté : 3 croquettes. » (vérifier la ligne dans l'application : 3). « … d'ajouter deux paquets de croquettes » → la ligne gagne 2 (« C'est ajouté, tu en as maintenant N paquets dans ta liste. » si elle existait). « … d'ajouter deux kilos de tomates » → si la tomate ne connaît pas le kilo, « C'est noté : 2 tomates. » (pas de kilos) ; si elle le connaît, « C'est noté : 2 kilos de tomate. ». « … d'ajouter zéro tomate » → « Combien ? Redis ta demande avec un nombre de 1 à 99. », rien d'écrit. Noter ce qu'Alexa met dans les créneaux `quantity`, `unit` et `item` (console, onglet *Test*, trace JSON, **sans copier les blocs `session` et `context`** : jeton d'accès) : « deux » à l'étape *choisir une proposition* doit rester un rang.
11. **Ligne déjà présente** : « … d'ajouter du pain » deux fois de suite → la seconde : « C'est ajouté, tu en as maintenant 2 dans ta liste. ».
12. Un proche (compte Amazon différent) : doit entendre l'invitation à lier le compte, rien d'autre (D4).
13. **Retirer** (VOX-304, ADR 0029 décision 12 ; recoller le modèle d'abord) : avec trois croquettes dans la liste, « … d'**enlever** des croquettes » → « C'est retiré, il t'en reste 2 dans ta liste. » (vérifier la ligne dans l'application : 2) ; « … de **retirer deux paquets de croquettes** » (l'unité inconnue de la ligne est laissée de côté, Alexa le dit) ; « … de **déduire** dix croquettes » → « C'est retiré : 2 croquettes. Il n'y en a plus dans ta liste. » (la ligne a disparu, jamais de quantité négative) ; « … d'enlever des croquettes » une fois la ligne partie → « Il n'y a pas de croquette dans ta liste. » ; un produit que tu n'as jamais eu (« de la ciboulette ») → « Il n'y a pas de ciboulette dans ta liste. » **sans** « de quoi tu parles ? » ni création ; « … d'enlever » tout court → « Dis par exemple : enlève du pain. » (jamais une question ouverte) ; avec deux « tomate » dans la liste (fruit et légume, surgelé) → « Tomate : en fruit et légume ou en surgelé ? » → « en surgelé » → seule la surgelée perd la quantité. Aucune liste : « Tu n'as pas de liste en cours. » ; liste partie au drive : « Ta liste est déjà partie au drive. » (elle n'est ni lue ni modifiée) ; rien n'est créé. « Enlève des croquettes » dit au milieu d'une question d'Alexa (par exemple après « Je ne connais pas ciboulette… ») est fait tout de suite, la question est abandonnée.
14. **Demander ce que la liste contient** (VOX-304) : « … : **est-ce qu'il y a** des croquettes dans la liste ? » → « Oui, tu en as 3. » (ou avec l'unité de la ligne : « Oui, tu en as 2 paquets. ») ; « … combien de croquettes dans la liste » ; un produit absent → « Non, il n'y a pas de croquette dans ta liste. » ; avec deux « tomate » dans la liste → « Oui : 2 tomates en fruit et légume, 1 en surgelé. » ; aucune liste en cours → « Tu n'as pas de liste en cours. » ; liste partie au drive → « Ta liste est déjà partie au drive. ». **Rien ne s'écrit** : la liste garde sa version (la vérifier dans l'application). Limite connue : seule la liste active est lue (celle partie au drive n'est pas lue, la phrase le dit).

15. **Planifier un repas** (VOX-305, ADR 0029 décision 13 ; recoller le modèle d'abord ; avoir une recette « hachis parmentier » dans l'application, sans produit de ce nom) : « … de **planifier le repas hachis parmentier** » → « C'est noté : hachis parmentier, pour N personnes. » (N = le nombre du compte, réglage « Moi » ; vérifier le repas et ses ingrédients dans la liste) ; « … de **prévoir la recette lasagnes pour six personnes** » → « … pour 6 personnes. » ; « … de **préparer un hachis parmentier** » (sans « repas ») doit aussi chercher une recette. **Contre-épreuve** : « … d'**ajouter un hachis parmentier** » (sans le mot) reste un produit : « Je ne connais pas hachis parmentier. … Ou un nouveau produit, ou une recette ? » — répondre « une recette » : « C'est noté : hachis parmentier, pour N personnes. » (si le repas y est déjà : la question du scénario 17). **Nombres hors limites** : « … de planifier le repas hachis parmentier pour cent personnes » (ou « pour zéro ») → « Combien ? Redis ta demande avec un nombre de 1 à 99. », rien n'est écrit ; « … pour trente personnes » → « … pour 30 personnes. » (les nombres en lettres jusqu'à quatre vingt dix neuf sont lus).
16. **Recette inconnue** : « … de planifier le repas gratin » (avec « gratin dauphinois » et « gratin de pâtes », sans « gratin ») → « Je ne trouve pas la recette gratin. Tu veux dire … ou …  ? Ou un produit ? » ; « le deuxième » → « C'est noté : … Je m'en souviendrai. » ; recommencer la même phrase (sur une liste où le repas n'est pas encore) : directement la bonne recette. **Rien n'est créé** (vérifier : aucune recette de plus). Une recette qui n'existe pas du tout (« xyz ») → « Je ne trouve pas la recette xyz. C'est un produit ? ».
17. **Déjà planifié** : planifier deux fois le même repas → « Le repas hachis parmentier est déjà prévu pour N personnes. Tu le veux pour combien, ou on ne change rien ? » → « six » → « C'est changé : hachis parmentier, pour 6 personnes. » (vérifier : UN seul repas, 6 personnes, les quantités ont suivi) ; recommencer et répondre « rien » (ou « non », ou le même nombre) → « D'accord, je ne change rien. » (la liste ne bouge pas). Dire « pour douze » au départ ne change pas la question : on ne devine pas. La réponse est le plus souvent rangée par Amazon dans un créneau qui n'est pas le sien (« six personnes » comme nom de recette, « six » comme produit) : le webhook la comprend d'après l'étape, il ne doit jamais répondre « Je ne trouve pas la recette six personnes ».
18. **Aiguillage** : « … d'ajouter xyz » (ni produit ni recette) → « … Je le crée ? Ou c'est une recette ? » → « une recette » → « Je ne trouve rien qui s'appelle xyz. » (fin : plus d'issue). Dans l'autre sens : « … de planifier le repas pain » (un produit « pain », pas de recette) → « Je ne trouve pas la recette pain. C'est un produit ? » → « oui » (ou « un produit ») → « C'est noté : pain. ». Un produit dont le nom commence par « repas » (« repas pour chien ») : « … d'ajouter le repas pour chien » → « Je ne trouve pas la recette pour chien. C'est un produit ? » → « oui » → « C'est noté : repas pour chien. » (le produit est cherché sous son nom entier). **Un choix dit comme un nombre** : à « Tu veux dire A ou B ? », répondre « deux » doit choisir B, même quand Amazon range « deux » dans la quantité ou le nombre de personnes. **Contre-épreuve de séparation** : un produit « œufs » et une recette « œuf au plat » : « … d'ajouter des œufs » va au produit, « … de planifier le repas œuf au plat » à la recette, jamais l'inverse.
19. **Aucune liste, liste envoyée** : sans liste active, planifier un repas dit « J'ai commencé une nouvelle liste avec …, pour N personnes. » ; avec la seule liste partie au drive, elle ajoute « Ta liste précédente est partie au drive. » et la liste envoyée n'a pas bougé.
20. **Pas la recette d'un autre** (compte de la compagne et compte de l'auteur) : une recette que seul l'autre compte possède n'est ni trouvée ni proposée ; « Je ne trouve pas la recette … ».
21. **Retirer un repas** (VOX-306, ADR 0029 décision 12 ; recoller le modèle d'abord ; avoir « hachis parmentier » planifié pour 4 personnes) : « … d'**enlever le repas hachis parmentier** » → « C'est retiré : le repas hachis parmentier. » (vérifier : le repas et ses lignes ont quitté la liste, aucune liste n'a été créée). Replanifier le repas pour 4, puis « … d'enlever le repas hachis parmentier **pour 2 personnes** » → « C'est changé : hachis parmentier, pour 2 personnes. » (les quantités ont baissé de moitié) ; « … de retirer la recette hachis parmentier pour 6 personnes » → le repas quitte la liste (on en enlève plus qu'il n'y en a). **« Enlève du pain » reste un produit** : le repas ne bouge pas.
22. **Repas introuvable, propositions de la liste** (avec « gratin dauphinois » et « gratin de pâtes » planifiés, et une recette « gratin de chou-fleur » qui n'est PAS dans la liste) : « … d'enlever le repas gratin » → « Je ne trouve pas le repas gratin dans ta liste. Tu veux dire gratin de pâtes ou gratin dauphinois ? Ou un produit ? » (jamais le gratin de chou-fleur : il n'est pas dans la liste) ; « le deuxième » → « C'est retiré : le repas … ». Avec « … pour 2 personnes » dit au départ, les 2 personnes sont gardées pendant la question. **Deux repas de même nom** (une recette copiée, « hachis parmentier » deux fois, planifiés pour 4 et pour 2) : « Tu veux dire hachis parmentier pour 4 ou hachis parmentier pour 2 ? Ou un produit ? » ; « le deuxième » (ou « deux », dit seul) retire celui-là ; jamais l'un des deux au hasard.
23. **Aiguillage du retrait, dans les deux sens** : « … d'enlever le hachis parmentier » (sans le mot « repas ») → « Il n'y a pas de hachis parmentier dans ta liste. C'est un repas ? » → « oui » (ou « un repas ») → « C'est retiré : le repas hachis parmentier. ». Dans l'autre sens : « … d'enlever le repas pain » (un produit « pain » dans la liste, aucun repas de ce nom) → « Je ne trouve pas le repas pain dans ta liste. C'est un produit ? » → « oui » → le pain diminue. Rien trouvé de part et d'autre : « Je ne trouve rien qui s'appelle … dans ta liste. » et plus de question. **Rien n'est créé** (ni produit, ni recette, ni liste) et aucune phrase n'est retenue (la même phrase redonne la même question).
24. **Retirer sans liste, liste envoyée** : sans liste active « enlève le repas … » dit « Tu n'as pas de liste en cours. » ; avec la seule liste partie au drive : « Ta liste est déjà partie au drive. » et elle n'a pas bougé.

25. **Après avoir recollé le modèle et lancé *Build skill*** (VOX-307, avant de parler à l'Echo) : `make voice-nlu-check` rejoue ce qu'Amazon comprend d'environ 130 phrases (les pannes des 25 et 26 octobre comprises, « deux paquets de croquettes », « planifier une fondue »…) sans toucher à ta liste ni appeler notre serveur. Il faut « 0 échec » ; chaque échec donne la phrase, l'attendu et l'obtenu. Une fois pour toutes : installer la CLI d'Amazon et s'y connecter (voir [`skill/README.md`](skill/README.md), « Vérifier ce qu'Amazon comprend »), puis `ALEXA_SKILL_ID=… make voice-nlu-check`. Cela ne remplace pas les scénarios ci-dessus (ils jugent nos réponses, pas la reconnaissance d'Amazon).
26. **Rejouer des dialogues entiers, en lecture seule** (VOX-308, après le profileur) : `make voice-sim-check` fait dire à Amazon « ouvre jarvis », « aide », « stop », « est-ce qu'il y a du pain dans la liste »… contre notre vrai webhook, sur ton compte, sans rien écrire dans ta liste (quatre gardes, voir [`skill/README.md`](skill/README.md) « Rejouer des dialogues de bout en bout »). C'est le fil principal, avec ton accord, qui le lance ; il faut « 16 / 16 scénarios OK ». Ce qui écrit (ajouter, retirer, planifier) se prouve avec les tests PHP et le profileur, pas avec ce rejeu.

À chaque échec : copier la réponse d'Alexa et la trace de la console, lire le journal, corriger, relancer. Noter les surprises dans `docs/voice/roadmap.md` (inconnues levées : certificat « serveur TLS », `redirect_uri`, nom d'invocation, « du pain » dit seul).

## Si Alexa répond bizarrement (appris le 2026-10-25)

| Ce qu'on voit | Cause | Remède |
|---|---|---|
| Alexa répond « Intent: AddItemIntent. Locale: fr-FR. Slots: … » | l'endpoint de la console est revenu sur « AWS Lambda ARN » (`ReflectorTemplateSkill`, démo d'Amazon) ; aucun `POST /api/voice/alexa` dans `journal php` | *Build* → *Endpoint* → **HTTPS** → adresse du webhook → *Save Endpoints* |
| L'éditeur JSON montre `"change me"` et `HelloWorldIntent` | vue périmée de la console (squelette par défaut) | recoller le modèle : tout est écrasé |
| La quantité dite est perdue (« deux paquets » ajoute 1) | `AMAZON.NUMBER` reste vide devant une unité ou un produit | déjà réglé : le créneau `quantity` est du type `QUANTITY` (1 à 99, `id` = le nombre) ; si ça revient, regarder la trace JSON de l'onglet *Test* |
| « Combien ? Redis ta demande avec un nombre de 1 à 99 » | Amazon a bien entendu un nombre mais le serveur n'a pas su le lire (ancienne version du serveur, ou nombre hors de 1 à 99) | vérifier `BACK_TAG` (1.1.5 au moins) |
| Le skill n'est plus appelé « Jarvis » | un ancien modèle a été collé (le nom vient du JSON) | recoller le modèle à jour |

Tester sans parler : l'application Alexa du téléphone a un champ « Demandez à Alexa » où l'on peut taper « demande à Jarvis d'ajouter … » ; l'onglet *Test* de la console fait de même (et montre le JSON reçu).

## Clôture

- Cocher VOX-002, VOX-501 (constat), VOX-602 ; ADR 0029 `Accepted` ; roadmap et mémoire à jour (PR).
- **Séance du 2026-10-25 (soir)** : étapes 1 à 8 faites avec le compte Amazon de l'auteur (test), recette de la quantité, de l'unité et de la création réussie sur l'application Alexa de son téléphone (versions 1.1.1 à 1.1.5).
- **Séance suivante : chez sa compagne (Echo à elle, lundi 2026-10-26 au soir)** : refaire les étapes 4 à 8 avec SON compte (e-mail, mot de passe et double authentification à elle ; l'inscription développeur peut redemander l'identité) : nouveau skill `Jarvis`, modèle collé par PowerShell, endpoint HTTPS, liaison de compte avec un **nouveau secret**, puis `ecrire-alexa-env.sh` dans le terminal de l'auteur (identifiant du skill, adresses de retour et secret nouveaux, **secret saisi en premier**) : le skill de l'auteur ne sera plus accepté par le serveur (un seul identifiant à la fois). Elle active le skill dans l'onglet *Développement* de son application Alexa et le lie à **un compte Repas à choisir avec eux**. Recette sur l'Echo avec les scénarios 2, 4, 7, 10 et 11 ci-dessus.
- Rappeler : couper = `ecrire-alexa-enabled.sh false` (les liaisons restent) ; retirer une liaison = « Moi → Assistant vocal ».
- Demander s'il veut les proches (test bêta Amazon ou publication) : décision D4 pour une autre séance.
