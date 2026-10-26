# VOX-305 — Planifier un repas (une recette) à la voix

- **Dépôt** : back (`Voice`, `Repas`, `User`, `Shared`) ; le front n'a rien à faire, sauf le texte de `/confidentialite` (une phrase)
- **Jalon** : M3
- **Taille** : XL
- **Dépend de** : VOX-304 ; la recette réelle de la bêta chez la compagne du propriétaire (2026-10-26)
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test) ; l'essai réel se fait avec le propriétaire (scénarios 15 à 20 de [`../seance-en-direct.md`](../seance-en-direct.md))
- **Statut** : en cours (décisions validées par le propriétaire le 2026-10-26)
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
- **Modèle d'interaction** : type `RECIPE` (une liste de plats courants, un indice pour Amazon et non une limite : le créneau accepte les autres noms), `generate-model.mjs`, `make voice-model`.
- Textes dans `VoiceReplies`, budgets d'écriture `planifier-repas-par-la-voix*`, `docs/voice/alexa.md`, ADR 0029 (décision 13), page `/confidentialite` (Alexa « planifie aussi un repas »).

## Critères d'acceptation

- Étant donné une recette « hachis parmentier » à l'utilisateur, quand il dit « planifie le repas hachis parmentier », alors le repas est planifié dans sa liste active pour le nombre de personnes de son compte et Alexa le dit ; avec « pour 6 personnes », pour 6.
- « Ajoute un hachis parmentier » reste un ajout de produit ; sans produit proche, Alexa propose aussi « ou une recette ? ».
- Une recette d'un autre compte n'est ni proposée ni planifiée ; une recette inconnue donne trois propositions et aucune création.
- Une recette déjà dans la liste n'est jamais ajoutée deux fois : Alexa demande le nouveau total, et rien n'est écrit sans nombre différent.
- Aucune liste active : une liste est créée ; liste envoyée : jamais modifiée.
- `make test`, `make quality` verts ; budgets SQL figés.

## À refaire dans la console Amazon avant l'essai

Recoller `docs/voice/skill/interaction-model.fr-FR.json` (Build → Interaction Model → JSON Editor → Save → Build skill) après la mise en production de la version qui contient ce ticket ; revérifier Build → Endpoint (HTTPS, `https://kantin.eu/api/voice/alexa`).
