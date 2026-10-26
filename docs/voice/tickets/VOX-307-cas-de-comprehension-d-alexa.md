# VOX-307 — Les cas de compréhension d'Alexa et le script qui les rejoue

- **Dépôt** : back (`docs/voice/skill/`, node, `tests/Voice`) ; ni le front ni le serveur ne changent
- **Jalon** : M3
- **Taille** : M
- **Dépend de** : VOX-306
- **Faisable sans téléphone** : oui pour tout ce qui est écrit ; **le propriétaire a connecté la CLI à son compte développeur (le 2026-10-26) et a autorisé le fil principal (jamais un sous-agent) à lancer le profileur et à envoyer le modèle en développement : aucun sous-agent ne contacte Amazon, aucun identifiant n'entre dans le dépôt)
- **Statut** : fait (2026-10-26, demande du propriétaire : « automatiser un max de tests sur Alexa », avec une copie des tests attendus dans le dépôt)
- **Lire d'abord** : [`../skill/README.md`](../skill/README.md) (« Vérifier ce qu'Amazon comprend ») ; [`../seance-en-direct.md`](../seance-en-direct.md) (« Si Alexa répond bizarrement ») ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) (Conséquences)

## Contexte

Nos 6 000 tests vérifient notre serveur. Ils ne voient pas ce **qu'Amazon comprend** d'une phrase, et c'est là que sont nées les pannes réelles des 25 et 26 octobre : « deux paquets de croquettes » ajoutait 1 (le créneau `AMAZON.NUMBER` restait vide), « planifier une fondue » n'était pas compris (le modèle n'avait aucun article après « planifie »). Chaque fois, c'est la recette à la main, sur l'Echo, qui l'a trouvé.

## Ce qui a été écarté, et pourquoi

- **L'outil « NLU Evaluation » d'Amazon** (jeux d'annotations de phrases attendues, relancés après chaque changement du modèle) : c'est exactement le besoin, mais Amazon l'a **déprécié, il n'est plus supporté** ([fonctions dépréciées](https://developer.amazon.com/docs/alexa/ask-overviews/deprecated-features.html)). Idem l'API de détection de conflits d'énoncés (depuis le 2023-05-17).
- **Rejouer des dialogues entiers (`ask dialog --replay`)** : cela appelle le vrai serveur et écrit dans la vraie liste du propriétaire ; l'outil d'évaluation de dialogues d'Alexa Conversations est lui aussi retiré. Non retenu pour l'instant.

## Décisions

1. **Le profileur d'énoncés** ([API](https://developer.amazon.com/docs/smapi/utterance-profiler-api.html)) : `POST …/interactionModel/locales/{locale}/profileNlu`, piloté par la CLI officielle (`ask smapi profile-nlu -u <phrase> -s <skill> -g development -l fr-FR`). Il rend l'intention retenue, les créneaux (valeur dite, identifiant résolu) et les intentions écartées ; il n'appelle ni notre serveur ni le skill, n'écrit rien chez Amazon et ne touche à aucune liste.
2. **Une copie versionnée des attendus** : `docs/voice/skill/nlu-cases.fr-FR.json`, environ 130 cas (ajouter, retirer, consulter, planifier et retirer un repas, réponses de dialogue, intentions d'Amazon), dont **toutes les phrases réelles** du propriétaire (2026-10-25 et 26) et une note par cas disant pourquoi il existe. Il n'y a **rien à coller dans la console** : le fichier reste dans le dépôt, c'est le script qui le rejoue. Un cas : intention attendue, créneaux (`{"value"}` la valeur dite, `{"id"}` l'identifiant résolu d'un type personnalisé, `null` = doit rester vide ; les créneaux non listés sont libres), `alsoIntents` (autres intentions que le webhook comprend aussi, d'après l'état du dialogue), `knownGap` (échec connu toléré, compté à part).
3. **Le script** `docs/voice/skill/nlu-check.mjs` : node pur, aucune dépendance ; il ne fait qu'exécuter `ask` (binaire `ASK_BIN`), **ne lit jamais `~/.ask`**, tire l'identifiant du skill de `ALEXA_SKILL_ID` ou de `--skill-id` (écrit nulle part) ; une demande à la fois, une pause entre deux, quatre reprises au plus en doublant l'attente sur 429 et 5xx, arrêt immédiat si la connexion est refusée (code 2) ; rapport en français, code 1 s'il y a un échec non connu ; `--only`, `--json`, `--dry-run`. `make voice-nlu-check` le lance, **jamais en CI**.
4. **Des tests sans Amazon** : `DocsVoiceNluCasesTest` (`make test`) garde le fichier cohérent avec le modèle (intentions, créneaux, identifiants qui existent, phrases uniques, chaque intention couverte, phrases réelles conservées) **et fige les ambiguïtés des phrases d'exemple** (une phrase portée par deux intentions une fois les créneaux masqués ; une phrase qui devient celle d'une autre intention quand on lui retire un créneau) : les ambiguïtés connues sont listées avec leur raison, une nouvelle fait échouer le test ; `make voice-nlu-selftest` (node) joue le script contre un **faux `ask`** (réussite, échec de créneau, mauvaise intention, créneau qui doit rester vide, écart connu, 429 puis succès, 429 sans fin, sortie illisible, 401, CLI absente).
5. **Limite assumée** : l'auto-test node ne tourne que sur la machine (modifier `.github/workflows/` n'est pas permis à l'assistant ; le propriétaire peut l'ajouter à la CI si une étape node y existe).

## Critères d'acceptation

- Étant donné un modèle collé et construit, quand le propriétaire lance `ALEXA_SKILL_ID=… make voice-nlu-check`, alors le rapport donne le nombre de cas conformes et, pour chaque échec, la phrase, l'attendu, l'obtenu et les intentions envisagées.
- Étant donné un cas qui échoue à cause d'une limite connue d'Amazon, alors `knownGap` le tolère et le rapport le compte à part ; le jour où il réussit, le rapport demande de retirer la marque.
- Étant donné un faux `ask`, alors le script gère 429, 5xx, sortie illisible, connexion refusée et CLI absente sans boucle infinie.
- Étant donné un cas qui cite une intention, un créneau ou un identifiant absent du modèle, alors `make test` échoue.
- Étant donné deux intentions qui partagent par erreur une phrase d'exemple, alors `make test` échoue.

## Ce qui reste au propriétaire

Installer la CLI d'Amazon dans un dossier à part, s'y connecter (`ask configure --no-browser`), puis lancer `make voice-nlu-check` après chaque *Build skill* (pas à pas dans `skill/README.md`). Les premiers essais décideront des cas à marquer `knownGap` ou à corriger ; le format exact de la sortie de `ask smapi profile-nlu` n'a pas pu être vu sans connexion.

## Premier passage réel (2026-10-26)

Le script a marché du premier coup (125 cas sur 133, 8 échecs), puis a servi de boucle de réglage du modèle (envoi par `ask smapi set-interaction-model`, build, rejeu) :

- **« planifie / planifier / prévois / prépare une fondue »** n'était PAS compris, même avec les articles de la v1.1.8 : Amazon choisissait `AnswerUnitIntent`, `ChooseKindIntent` ou `ChooseOptionIntent` (leurs exemples « une {unit} », « une {kind} », « la {rank} » attrapent n'importe quel mot) ou `AddItemIntent` (« pizza », « lasagne », « fondue » sont aussi des produits du type `INGREDIENT`). Retirer ces exemples déplaçait seulement la panne d'une intention à l'autre. **Remède : le nom de la recette devient du texte libre** (`AMAZON.SearchQuery`) ; les 20 variantes sondées passent.
- Conséquence voulue d'Amazon : ce type ne se combine avec aucun autre créneau, donc « pour six personnes » reste au bout du texte (le webhook le lit déjà).
- « la recette fondue » dit seul était rangé dans `RemoveMealIntent` : deux exemples exacts (« le repas {recipe} », « la recette {recipe} ») dans `AddMealIntent`.
- Le webhook lit désormais « du pain s'il te plait » (politesse retirée) et « vingt et un yaourts » (`yaourts` rangé dans `unit`, `item` vide).
- Le modèle d'Amazon se réentraîne à chaque envoi : un même cas peut passer ou échouer d'un build à l'autre (« ajoute trois croquettes » a échoué deux builds puis repassé) ; en cas d'échec isolé, renvoyer le modèle et rejouer avant de conclure.
- Écarts connus restants (5, tolérés) : « pain » seul (rayon), « à l'aide », une phrase hors sujet qui tombe sur `RemoveItemIntent`, et les deux cas que le webhook rattrape.
