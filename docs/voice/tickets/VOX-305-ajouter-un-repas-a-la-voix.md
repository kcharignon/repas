# VOX-305 — Planifier un repas (une recette) à la voix

- **Dépôt** : back (`Voice`, `Repas`, `User`, `Shared`) ; le front n'a rien à faire, sauf le texte de `/confidentialite` (une phrase)
- **Jalon** : M3
- **Taille** : XL
- **Dépend de** : VOX-304 ; la recette réelle de la bêta chez la compagne du propriétaire (2026-10-26)
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test) ; l'essai réel se fait avec le propriétaire (scénarios 15 à 20 de [`../seance-en-direct.md`](../seance-en-direct.md))
- **Statut** : fait (2026-10-26, décisions validées par le propriétaire le même jour)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 2 et 3 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 5, 11, 12 et 13 ; la commande `PlannedMeal` (`src/Repas/Application/Command/PlannedMeal/`) et `UpdateServingMeal` ; `.claude/skills/repas-po/SKILL.md` (les textes d'Alexa).

## Contexte

Demande du propriétaire : « Alexa, demande à Jarvis d'ajouter un hachis parmentier » : on ne précise pas le nombre de personnes, c'est celui du compte (`AuthenticatedUser::getDefaultServing()`). L'application sait déjà planifier un repas dans la liste (`PlannedMeal`, avec un nombre de personnes) : il manque seulement la voix. Le propriétaire craint qu'un produit trop proche d'une recette lui vole toujours le choix (« œufs », « œuf au plat ») : **les deux recherches sont séparées**, et c'est l'utilisateur qui aiguille quand Alexa ne comprend pas.

## Décisions validées (propriétaire, 2026-10-26)

1. **Deux recherches séparées, jamais mélangées** : les produits (comme aujourd'hui, `SpokenIngredientResolver`) et les recettes (les siennes seulement, jamais celles d'un autre compte). Plus de dialogue « un produit ou une recette ? ».
2. **Quand Alexa cherche une recette** : la phrase contient « repas » ou « recette » (« ajoute le repas hachis parmentier », « ajoute la recette… », « planifie la recette… »), OU le verbe est « planifie », « prévois », « prépare » ou « mets au menu » (« planifie un hachis parmentier »). « Ajoute un hachis parmentier » sans ces mots reste un **produit**, comme aujourd'hui. Nouvelle intention `AddMealIntent` (créneaux `recipe` et `servings`). Quand Amazon range tout de même « le repas hachis parmentier » dans `AddItemIntent`, le webhook reconnaît le mot (`SpokenMealPhrase`) et fait la même chose.
3. **Nombre de personnes** : celui du compte, SAUF si l'utilisateur dit « pour 6 » ou « pour 6 personnes » : ce nombre est alors forcé. Jamais demandé à l'ajout.
4. **Réponse** : « C'est noté : hachis parmentier, pour 4 personnes. » (tutoiement, 15 mots, aucune relance).
5. **Recette reconnue sûrement** : planifiée. **Recette inconnue** : les trois recettes les plus proches (même classeur que les produits, plancher 0,80) et l'issue « ou un produit ? ». **Jamais de création de recette à la voix.** Choisir une proposition planifie le repas ET retient la phrase (table à part, `spoken_recipe_phrase_choice`).
6. **L'utilisateur aiguille dans les deux sens** :
   - ajout de **produit** sans correspondance (trois produits proches + « un nouveau produit ») : Alexa ajoute l'issue « ou une recette ? » ; s'il la choisit, la phrase d'origine est cherchée dans ses recettes (trouvée sûrement : planifiée ; sinon trois recettes proches) ;
   - recherche de **recette** sans correspondance (trois recettes + « ou un produit ? ») : s'il choisit « un produit », la recherche de produits repart avec la phrase d'origine (reconnu : ajouté ; sinon le dialogue ordinaire d'un produit inconnu : trois produits, création).
   - **Pas de ping-pong** : l'état retient que l'autre côté a déjà été essayé ; une fois les deux essayés, plus d'issue d'aiguillage (« Je ne trouve rien qui s'appelle … », ou, côté produit, la proposition de création habituelle).
7. **Recette déjà dans la liste** (le domaine refuse deux fois la même recette, `ShoppingListException::recipeAlreadyInList`) : jamais deux fois. Alexa dit qu'elle y est déjà ET pour combien de personnes, et demande si l'utilisateur veut **ne rien changer** (elle s'est trompée) ou pour combien il la veut. Il répond « 6 » ou « 6 personnes » : c'est le **nouveau total**, pas une addition. Le même nombre ou « non » / « rien » : rien n'est écrit. La question est posée même si l'utilisateur avait dit « pour 6 » (on ne devine pas). Le changement passe par la logique d'`UpdateServingMeal` (quantités des lignes ajustées).
8. **Aucune liste active** : une liste est créée et dite, comme pour un produit ; une liste envoyée au drive n'est jamais modifiée (comme pour l'ajout d'un produit, une nouvelle liste est commencée et Alexa dit que la précédente est partie au drive).
9. **Retirer un repas** ou baisser le nombre de personnes : **VOX-306** (ticket écrit, pas codé ici).

## Portée technique

- **Ports `Shared`** : `SpokenRecipeResolver::resolveOrSuggest` / `remember` (recettes de l'utilisateur, classeur de noms, mémoire de phrase), `VoiceMealPlanner::plan` / `changeServings` (implémenté par `Repas`, qui réutilise `PlannedMealHandler` et la logique d'`UpdateServingMealHandler`) et `UserDefaultServing::of` (implémenté par `User`, une requête sur la colonne).
- **Voice, domaine** : `AlexaIntent` gagne `AddMealIntent`, `ChooseKindIntent` (« une recette », « un produit » : créneau `kind`), `AnswerServingsIntent` (créneau `servings`) et `KeepMealIntent` (« rien », « ne change rien »). Alexa choisit l'intention sans connaître notre état : `SpokenAnswerInterpreter` décide d'après l'ÉTAT, quelle que soit l'intention reçue. `SpokenMealPhrase` (pur) lit le mot « repas » / « recette » en tête de phrase et le « pour 6 personnes » en queue. `SpokenDialogueState` gagne la cible (`item` ou `meal`), l'indication « l'autre côté a déjà été essayé », le nombre de personnes dit, l'étape `servings` et le nombre de personnes déjà planifié.
- **Voice, application** : commande `AddSpokenMeal` ; `AnswerSpokenDialogue` gère l'aiguillage, le choix d'une recette et la réponse « combien ? ».
- **Repas** : `SpokenRecipeCandidates` (une requête sur les recettes de l'utilisateur), table `spoken_recipe_phrase_choice` (migration additive, purgée avec le compte, effacée avec la recette), commandes `PlanSpokenMeal` et `ChangeSpokenMealServings`.
- **Modèle d'interaction** : le nom de la recette est du texte libre (`AMAZON.SearchQuery`, 2026-10-26 : un type à nous, `RECIPE`, faisait partir « planifie une fondue » vers les intentions des produits ou des réponses, constaté au profileur d'énoncés, VOX-307), `generate-model.mjs`, `make voice-model`.
- Textes dans `VoiceReplies`, budgets d'écriture `planifier-repas-par-la-voix*`, `docs/voice/alexa.md`, ADR 0029 (décision 13), page `/confidentialite` (Alexa « planifie aussi un repas »).

## Ce qui est livré

1. **Skill** : quatre intentions de plus (dix-huit avec celles d’Amazon), `AddMealIntent` (94 phrases, texte libre : planifie / prévois / prépare / mets au menu, avec ou sans « le repas » / « la recette », et « ajoute / mets / note le repas / la recette »), `ChooseKindIntent` (« une recette », « un produit »), `AnswerServingsIntent`, `KeepMealIntent` ; un type de plus, `KIND` (le type `RECIPE` a été retiré le 2026-10-26). Aucun « un » ni « une » comme mot de liaison dans les phrases de `AddMealIntent`.
2. **Voice** : `SpokenMealPhrase` (le mot, la queue « pour six personnes », les réponses brèves), `AddSpokenMeal`, l'état du dialogue (`SpokenTarget`, `otherSideTried`, `servings`, `plannedServings`, étape `servings`), les nouveaux genres de réponse (`SWITCH_TO_MEAL`, `SWITCH_TO_ITEM`, `NEW_MEAL`, `PICK_SERVINGS`), les textes de `VoiceReplies`.
3. **Repas** : `SpokenRecipeResolverAdapter` (recettes de l'utilisateur, même classeur de noms, mémoire à part), `PlanSpokenMeal` et `ChangeSpokenMealServings` (ils réutilisent `PlannedMealHandler` et `UpdateServingMealHandler`), migration `spoken_recipe_phrase_choice`, effacée avec la recette (`RemoveRecipeHandler`) et avec le compte.
4. **User** : `UserDefaultServingPostgreSQL` (le nombre de personnes du compte, une requête).

## Décisions prises à l'écriture

- **Un choix incertain est proposé, jamais deviné** (deux recettes dont les noms se touchent) : l'ajout d'un produit ajoute une ligne, un repas en ajoute beaucoup. Le classeur de noms est celui des produits, sans habitude (une recette n'a pas de rayon ni d'historique d'usage ici).
- **Deux recettes de même nom** : la première, dans l'ordre stable des identifiants (la voix ne les départage pas).
- **Table à part** pour la mémoire des phrases de recettes (`spoken_recipe_phrase_choice`), pour que les deux recherches ne se mélangent jamais.
- **Une recette dont un ingrédient n'a pas de conversion vers son unité d'achat** fait échouer toute la commande, comme dans l'application : Alexa répond « Je n'ai pas pu faire ça. Réessaie dans un instant. », rien n'est écrit, aucune liste n'est créée. Limite connue, sans phrase dédiée.
- **Le mot « repas » / « recette » en tête annonce une recette** : un produit dont le nom commence par « repas » (« repas pour chien ») dit sans article est pris pour une recette ; « des repas pour chien » reste un produit. Le propriétaire corrige en répondant « un produit ».
- **Le nombre de personnes dit est de 1 à 99** (comme une quantité) : « zéro » ou « cent » (dans le créneau, ou en fin de phrase : « pour 0 », « pour 100 personnes », « pour cent personnes ») reçoivent « Combien ? Redis ta demande avec un nombre de 1 à 99. », rien n'est écrit, et le nombre n'est jamais gardé dans le nom de la recette. Un lecteur de nombres commun (`SpokenNumber` : chiffres jusqu'à trois, mots de « un » à « quatre vingt dix neuf », avec ou sans trait d'union, « cent ») sert à la fin de phrase, aux réponses et au nombre laissé en tête d'un produit (`SpokenIngredientNamer`, qui lit donc aussi « trente », « quarante »… quand ils sont seuls en tête).
- **Revue de la PR** : un choix dit comme un nombre (« deux ») que l'Amazon range dans `servings` ou `quantity` est le rang correspondant à toutes les étapes de choix ; à l'étape « combien de personnes ? », une réponse rangée dans `recipe` (« six personnes », « rien », « non ») reste la réponse ; la phrase d'origine entière (mot « repas » compris) est gardée dans l'état d'une recette inconnue, pour qu'un produit « repas pour chien » soit retrouvé sous son nom entier après « un produit » ; « aucun » ou « un nouveau produit » à une question de recettes aiguille vers « un produit » (décision gardée).
- **Le nouveau total n'est pas une addition** : « six » à « déjà prévu pour 4 personnes » donne 6.
- **« Planifie les lasagnes pour six » à la question du nombre de personnes** est un autre repas (`NEW_MEAL`), pas un total.
- **L'aide d'Alexa** (« Dis par exemple : ajoute du pain… ») ne parle pas encore des repas ; l'exemple « Dis par exemple : planifie le repas hachis parmentier. » n'est dit que quand « planifie » arrive sans recette.
- **Budgets** : `supprimer-compte` 25 → 26 et `supprimer-recette` 11 → 12 (une suppression de plus pour la mémoire des recettes) ; nouveaux : `planifier-repas-par-la-voix` 31 (`-sans-liste` 32, `-liste-envoyee` 32, `-deja-planifie` 10), `modifier-portions-repas-par-la-voix` 26, `memoriser-phrase-de-recette-alexa` 7, `planifier-repas-dit-a-alexa` 33 (`-sans-liste` 34, `-introuvable` 2), `choisir-recette-proposee-alexa` 34, `changer-portions-repas-dit-a-alexa` 26.

## Critères d'acceptation

- Étant donné une recette « hachis parmentier » à l'utilisateur, quand il dit « planifie le repas hachis parmentier », alors le repas est planifié dans sa liste active pour le nombre de personnes de son compte et Alexa le dit ; avec « pour 6 personnes », pour 6.
- « Ajoute un hachis parmentier » reste un ajout de produit ; sans produit proche, Alexa propose aussi « ou une recette ? ».
- Une recette d'un autre compte n'est ni proposée ni planifiée ; une recette inconnue donne trois propositions et aucune création.
- Une recette déjà dans la liste n'est jamais ajoutée deux fois : Alexa demande le nouveau total, et rien n'est écrit sans nombre différent.
- Aucune liste active : une liste est créée ; liste envoyée : jamais modifiée.
- `make test`, `make quality` verts ; budgets SQL figés.

## À refaire dans la console Amazon avant l'essai

Recoller `docs/voice/skill/interaction-model.fr-FR.json` (Build → Interaction Model → JSON Editor → Save → Build skill) après la mise en production de la version qui contient ce ticket ; revérifier Build → Endpoint (HTTPS, `https://kantin.eu/api/voice/alexa`).
