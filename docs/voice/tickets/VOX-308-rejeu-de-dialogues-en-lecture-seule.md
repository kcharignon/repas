# VOX-308 — Rejouer des dialogues de bout en bout, en lecture seule

- **Dépôt** : back (`docs/voice/skill/`, node, `tests/Voice`) ; ni le front ni le serveur ne changent
- **Jalon** : M3
- **Taille** : M
- **Dépend de** : VOX-307
- **Faisable sans téléphone** : oui pour tout ce qui est écrit (faux `ask`, aucun appel chez Amazon) ; **le fil principal le lance** avec la connexion du propriétaire, qui l'a accepté le 2026-10-26 (« Option 2, sur mon compte », en lecture seule) ; aucun sous-agent ne contacte Amazon
- **Statut** : fait (2026-10-26, demande du propriétaire : « je ne veux plus tester à la main sur mon téléphone, il est temps d'automatiser »)
- **Lire d'abord** : [`../skill/README.md`](../skill/README.md) (« Rejouer des dialogues de bout en bout ») ; [`VOX-307`](VOX-307-cas-de-comprehension-d-alexa.md) ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) ; `src/Voice/Domain/Service/AlexaDialogue.php` (ce qui n'écrit rien).

## Contexte

Le profileur d'énoncés (VOX-307) dit ce qu'Amazon COMPREND d'une phrase ; il n'appelle pas notre serveur. Restait à vérifier toute la chaîne (Amazon, liaison de compte, webhook, lecture de la liste, texte dit, séance ouverte ou fermée, plusieurs tours) sans que le propriétaire parle à son téléphone. Amazon sait **simuler** ce que dit un client : `ask smapi simulate-skill` appelle le vrai webhook avec le compte lié du propriétaire (essai du 2026-10-26 : « demande à jarvis s'il y a du pain dans la liste » → « Oui, tu en as 5,5. », 700 ms), et `ask smapi get-skill-simulation` rend ce que notre serveur a reçu et répondu.

## Ce qui a été écarté, et pourquoi

- **Rejouer aussi ce qui écrit** (ajouter, retirer, planifier) : la simulation appelle le VRAI serveur, donc elle écrirait dans la vraie liste du propriétaire. Non : ce qui écrit reste prouvé par les tests PHP (le webhook avec un faux Amazon, budgets d'écriture) et par le profileur (VOX-307). Si le besoin revient, un compte Repas de test lié au skill (`--allow-writes`, jamais utilisé sur le compte du propriétaire).
- **`ask dialog --replay`** : l'outil d'évaluation de dialogues d'Alexa Conversations est retiré par Amazon ; la simulation couvre le besoin.
- **Un scénario « déjà prévu, rien »** (planifier un repas déjà dans la liste, puis « rien ») : il n'écrit rien seulement si le repas y est déjà ; sinon il planifierait. Le contenu de la liste n'est pas garanti, donc non retenu.

## Décisions

1. **Une copie versionnée de scénarios** : `docs/voice/skill/simulation-scenarios.fr-FR.json`, 16 scénarios (lancement, aide dans la séance et en appel direct, stop, annule, arrête, consulter un produit en appel direct et en séance ouverte, produit inexistant, deux consultations de suite, repli hors sujet, oui et non sans question, parcours entier). Chaque tour : ce que le client dit (`say`, avec l'invocation au premier tour), ce que le profileur doit retenir après l'invocation (`heard`), l'intention et les créneaux que NOTRE serveur doit recevoir, une expression régulière de la réponse, séance fermée ou ouverte, relance ou non, séance neuve ou continuée. Le contenu de la liste varie : les consultations acceptent toutes les issues de lecture.
2. **Le script** `docs/voice/skill/simulation-check.mjs` : node pur, aucune dépendance ; il ne fait qu'exécuter `ask` (`ASK_BIN`), **ne lit jamais `~/.ask`**, tire l'identifiant du skill de `ALEXA_SKILL_ID` ou `--skill-id` ; une seule demande à la fois (Amazon ne gère pas les demandes concurrentes), des pauses, 4 reprises au plus sur 429 et 5xx, 20 relectures au plus d'un résultat de simulation, résultat expiré (404) = écart de ce scénario. Sortie « 14 / 16 scénarios OK », codes 0, 1, 2 et 3.
3. **Quatre gardes contre l'écriture** (le serveur est le vrai) : **(a)** un scénario dont `writes` n'est pas exactement `false` est refusé avant toute commande ; **(b)** AVANT chaque tour hors lancement, le profileur dit l'intention d'Amazon pour la phrase : hors des intentions de lecture seule, le tour est **bloqué**, aucune simulation lancée ; **(c)** APRÈS chaque simulation, l'intention que notre serveur a reçue doit être de lecture seule (ou un lancement, ou la fin de séance) sinon **arrêt immédiat** (code 3, dit très fort) ; **(d)** APRÈS chaque simulation, notre réponse ne porte aucun état de dialogue, sinon arrêt immédiat (une question en attente ferait écrire le tour suivant). Les intentions de lecture seule : `CheckItemIntent` (une lecture), `AMAZON.HelpIntent` et `AMAZON.FallbackIntent` (phrases fixes), `AMAZON.StopIntent` et `AMAZON.CancelIntent` (« À bientôt. »), `AMAZON.YesIntent` et `AMAZON.NoIntent` (sans question en cours : la garde (d) l'impose, le webhook invite ou dit au revoir) ; jamais `AddItemIntent`, `RemoveItemIntent`, les intentions de repas ni les réponses de dialogue. Le webhook enregistre aussi `last_used_at` de la liaison (au plus toutes les 10 minutes) : ce n'est pas une donnée de la liste.
4. **Le jeton n'est jamais montré** : la requête reçue par notre serveur porte le jeton d'accès de la liaison, l'identifiant de l'utilisateur Amazon et celui de l'appareil. Le script ne garde que le type, l'intention, les valeurs des créneaux et `session.new`, masque toute valeur secrète croisée (même si une erreur de la CLI la répète) et sa sortie `--json` n'en contient aucun.
5. **Des tests sans Amazon** : `DocsVoiceSimulationScenariosTest` (`make test`) garde le fichier de scénarios sûr et cohérent (`writes: false` partout, aucune phrase qui contient un verbe qui écrit, intentions de lecture seule seulement, créneaux qui existent, séances enchaînées correctement, expressions régulières valides qui reconnaissent les VRAIS textes de `VoiceReplies` et jamais la phrase d'échec) et vérifie que la liste de lecture seule du script est exactement la liste justifiée ; l'auto-test node (`make voice-sim-selftest`, un faux `ask`) prouve les quatre gardes, les reprises, les erreurs et l'absence de tout jeton sur toute sortie.
6. **Limite assumée** : l'auto-test node ne tourne que sur la machine (`.github/workflows/` n'est pas modifiable par l'assistant) ; le rejeu lui-même demande la connexion du propriétaire et ne tourne jamais en CI.

## Critères d'acceptation

- Étant donné un skill construit et lié, quand le fil principal lance `ALEXA_SKILL_ID=… make voice-sim-check`, alors le rapport donne le nombre de scénarios conformes et, pour chaque écart, le tour, la phrase dite, l'attendu et l'obtenu, et la liste du propriétaire n'a pas bougé.
- Étant donné un scénario `writes: true`, alors le script le refuse avant toute commande.
- Étant donné une phrase qu'Amazon range dans `AddItemIntent`, alors le tour est bloqué et aucune simulation n'est lancée.
- Étant donné un serveur qui reçoit une intention qui n'est pas de lecture seule, ou qui ouvre un dialogue, alors le rejeu s'arrête tout de suite avec le code 3.
- Étant donné une simulation dont le résultat porte un jeton, alors aucune sortie (standard, erreur, JSON) ne le contient.
- Étant donné une expression régulière de réponse qui accepterait « Je n'ai pas pu faire ça. Réessaie dans un instant. », alors `make test` échoue.

## Ce qui reste

Les premières exécutions réelles diront quelles phrases d'appel direct (« demande à jarvis aide », « … s'il y a du pain dans la liste ») Amazon range bien : un tour bloqué se corrige en changeant la phrase du scénario ou, si c'est le modèle, par VOX-307. Pour jouer des scénarios qui écrivent, créer un compte Repas de test lié au skill.
