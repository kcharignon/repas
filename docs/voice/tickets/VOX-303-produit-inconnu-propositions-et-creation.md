# VOX-303 — Produit inconnu : trois propositions, mémoire de phrase, création guidée

- **Dépôt** : back (`Voice`, `Repas`, `Shared`) ; le front n'a rien à faire
- **Jalon** : M3
- **Taille** : L
- **Dépend de** : VOX-204, VOX-205
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test)
- **Statut** : fait (2026-10-25, PR « feat/voice-produit-inconnu »)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 2 à 4 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 3, 4 et 11 ; `.claude/skills/repas-po/SKILL.md` (les textes d'Alexa) ; `docs/ingredients/fusion.md` (une table qui référence un ingrédient est repointée par la fusion).

## Contexte

Décision du propriétaire du 2026-10-25, qui **remplace** le défaut D3 (« ne rien créer, le dire ») et la décision 4 : quand la phrase ne ressemble à aucun ingrédient de l'utilisateur, Alexa ne répond plus « Je ne trouve pas X » mais demande « de quoi tu parles » et mène un vrai dialogue.

## Ce qui est livré

1. **Propositions** : `SpokenIngredientRanker::suggest` (trois ingrédients de l'utilisateur au plus, sous le seuil du choix automatique, au-dessus du plancher de proximité 0,80 avec un début de mot en commun), port `SpokenIngredientResolver::suggest`. « Je ne connais pas X. Tu veux dire A, B ou C ? Ou un nouveau produit ? » ; aucune proposition : « Je ne connais pas X. Je le crée ? ».
2. **Mémoire de phrase** : table `spoken_phrase_choice` (migration additive), commande `RememberSpokenPhrase` (alias résolus, ingrédient à l'utilisateur, upsert qui compte `times`), port `SpokenIngredientResolver::remember`, lecture AVANT le classement (`resolve`) par `SpokenPhraseMemory` ; purgée avec le compte, effacée avec l'ingrédient, repointée par la fusion.
3. **Création guidée** : « Dans quel rayon ? », « Quelle unité ? » (une seule pour l'achat et la cuisine), « Je crée X, rayon, unité. C'est bon ? » ; port `SpokenIngredientCreator` (catalogue des rayons et unités, création par `CreateIngredientHandler`, `image: ''`), nom tiré de la phrase par `SpokenIngredientNamer`.
4. **Dialogue d'état** : `SpokenDialogueState` (étapes `choose`, `department`, `unit`, `confirm`) dans `session.attributes.dialogue` / `sessionAttributes.dialogue`, lu comme une entrée non fiable ; `SpokenAnswerInterpreter` (toute la matrice état × intention) ; commandes `AddSpokenItem` (étendue) et `AnswerSpokenDialogue` ; tous les textes dans `VoiceReplies`.
5. **Skill** : six intentions de plus (`ChooseOptionIntent`, `CreateItemIntent`, `AnswerDepartmentIntent`, `AnswerUnitIntent`, `AMAZON.YesIntent`, `AMAZON.NoIntent`), types `DEPARTMENT`, `UNIT`, `OPTION_RANK`, forme réduite au créneau (`{item}`, `{department}`…), modèle généré par `docs/voice/skill/generate-model.mjs` (`make voice-model`, `make voice-model-check`).

## Décisions prises à l'écriture

- **Plancher de proximité** : `SearchRelevance` seul (Jaro-Winkler) note 0,81 « fenouil » contre « nouille » et « basilic » contre « ail ». Le plancher est donc 0,80 ET un début de mot en commun de trois lettres ; la table de cas est dans `SpokenIngredientRankerTest`.
- **Un nom très proche** (« ciboulete » contre *ciboulette*, 0,98) est au-dessus du seuil de choix automatique : il est choisi tout seul, il n'y a pas de question. Les propositions couvrent la bande 0,80 à 0,85.
- **Deux incompréhensions de suite** dans la même étape abandonnent (la deuxième n'est pas redemandée) ; « oui » avec plusieurs propositions compte comme une incompréhension.
- **Séance ouverte** pendant tout le dialogue, même après un appel direct.
- **Un état invalide ou forgé** n'est pas un état : la réponse est celle d'une séance sans dialogue. Un état valide mais dont les slugs sont faux fait échouer la commande entière (transaction du bus) : « Je n'ai pas pu faire ça. », sans état.
- **Budgets SQL** : le chemin « introuvable » passe de 3 à 7 requêtes (lectures seulement : mémoire de phrase, puis le classeur relu pour les propositions), `ajouter-article-dit-a-alexa*` +1 (lecture de la mémoire), suppression d'ingrédient, fusion et suppression de compte +1 (la nouvelle table). Les budgets montent, par nature de la fonction ; ils sont figés de nouveau.

## Critères d'acceptation

- « ajoute du sirop de menthe » (qui n'existe pas) → propose *sirop fruit rouge* ; « oui » → ajouté, « Je m'en souviendrai » ; plus tard « du sirop de menthe » donne directement *sirop fruit rouge*.
- « ajoute de la ciboulette » (rien de proche) → « Je le crée ? » → rayon → unité → confirmation → l'ingrédient existe pour l'utilisateur, avec une seule unité, et il est dans sa liste ; « non » à la confirmation n'écrit rien.
- Un état forgé (slug d'un ingrédient d'un autre) n'ajoute rien, ne mémorise rien, et la réponse reste polie.
- `make test` et `make quality` verts.

## Tests exigés (livrés)

Classeur (table de cas du plancher, ordre, habitude, trois au plus) ; interprète (toute la matrice) ; `VoiceReplies` (3 phrases pour la seule question « de quoi tu parles ») ; `AlexaRequest` (état tolérant, créneaux, résolutions) ; `AlexaResponse` (`sessionAttributes`) ; handlers (flux complets, états forgés) ; mémoire (dépôt et Loader sur base réelle, handler) ; adaptateurs et flux avec les vrais bus ; webhook de bout en bout (signatures de test) ; invariants (`UserOwnedDataCoverageTest`, `IngredientReferencesAreAllRepointedTest`, `IngredientSlugWritesAreResolvedTest`, `WriteBudget`) ; `DocsVoiceSkillTest` (ids du modèle = fixtures).

## À vérifier à la recette (VOX-602) ou à la création du skill (VOX-002)

- Que la console accepte une phrase d'exemple réduite au seul créneau (`{item}`, `{rank}`, `{department}`, `{unit}`). Sinon : `node docs/voice/skill/generate-model.mjs --no-bare`.
- Que la résolution d'entité arrive bien sous `request.intent.slots.<nom>.resolutions.resolutionsPerAuthority[].values[].value.id` (c'est le format documenté par Amazon ; le code lit aussi le texte entendu quand l'identifiant manque).
- Que `session.attributes` revient tel qu'écrit dans `sessionAttributes` (limite de taille côté Amazon : l'état tient en quelques centaines d'octets).
- Que « un nouveau produit », « aucun » et « non » sont bien reconnus comme `CreateItemIntent` / `AMAZON.NoIntent` à voix haute ; sinon le texte entendu reste lisible par l'interprète.
