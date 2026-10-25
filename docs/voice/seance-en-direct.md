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
3. *Create Skill* → nom `Mes courses` → langue **French (FR)** → type *Other* / modèle **Custom** → hébergement **Provision your own** → modèle de départ **Start from scratch** → *Create skill*. Attendre « Full Build Successful ».
4. Mettre le fichier du modèle dans son presse-papiers sans l'afficher : `! clip.exe < docs/voice/skill/interaction-model.fr-FR.json`. Console : *Build* → *Interaction Model* → *JSON Editor* → tout sélectionner, coller → *Save Model* → *Build Model*. S'il y a une erreur, il me lit la ligne rouge ; je corrige le fichier (PR) et on recommence. Erreur probable : nom d'invocation refusé (voir D1).
5. *Build* → *Endpoint* : **HTTPS**, région par défaut `https://kantin.eu/api/voice/alexa`, certificat « *My development endpoint has a certificate from a trusted certificate authority* ». *Save Endpoints*. Il me donne l'**identifiant du skill** (`amzn1.ask.skill.…`, ce n'est pas un secret).

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

1. Application Alexa → *Plus* → *Skills et jeux* → *Vos skills* → onglet **Développement** → *Mes courses* → *Activer pour utiliser*.
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
10. **Quantité** : « … d'ajouter trois croquettes » → « C'est noté : 3 croquettes. » (vérifier la ligne dans l'application : 3). « … d'ajouter deux kilos de tomates » → si la tomate ne connaît pas le kilo, « C'est noté : 2 tomates. » (pas de kilos) ; si elle le connaît, « C'est noté : 2 kilos de tomate. ». « … d'ajouter zéro tomate » → « Combien ? Redis ta demande avec un nombre de 1 à 99. », rien d'écrit. Noter ce qu'Alexa met dans les créneaux `quantity`, `unit` et `item` (console, onglet *Test*, trace JSON) : « deux » à l'étape *choisir une proposition* doit rester un rang.
11. **Ligne déjà présente** : « … d'ajouter du pain » deux fois de suite → la seconde : « C'est ajouté, tu en as maintenant 2 dans ta liste. ».
12. Un proche (compte Amazon différent) : doit entendre l'invitation à lier le compte, rien d'autre (D4).

À chaque échec : copier la réponse d'Alexa et la trace de la console, lire le journal, corriger, relancer. Noter les surprises dans `docs/voice/roadmap.md` (inconnues levées : certificat « serveur TLS », `redirect_uri`, nom d'invocation, « du pain » dit seul).

## Clôture

- Cocher VOX-002, VOX-501 (constat), VOX-602 ; ADR 0029 `Accepted` ; roadmap et mémoire à jour (PR).
- Rappeler : couper = `ecrire-alexa-enabled.sh false` (les liaisons restent) ; retirer une liaison = « Moi → Assistant vocal ».
- Demander s'il veut les proches (test bêta Amazon ou publication) : décision D4 pour une autre séance.
