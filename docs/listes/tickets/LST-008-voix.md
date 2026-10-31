# LST-008 — Voix : Alexa et les types de liste

- **Dépôt** : back — **Taille** : M — **Effort** : high — **Dépend de** : LST-005
- **Statut** : fait (PR de la branche `feat/lst-008-voix-et-types`)
- **Lire d'abord** : ADR 0029, 0035, 0036 ; `docs/voice/alexa.md` (« Listes d'un type ») ; skill `repas-po` (réponses d'Alexa).

## À faire

- **Ajouter** (produit ou repas) va dans la liste de la destination (ports et Open Host Services existants, `ShoppingListItemCommandPort`, `MealPlanCommandPort`) ; la réponse nomme la liste quand ce n'est pas la principale (« C'est ajouté à Boucherie. ») ; toujours deux phrases au plus, 15 mots, jamais de relance (`VoiceReplies`, `VoiceRepliesTest`).
- **Demander et retirer** (`CheckItemIntent`, `RemoveItemIntent`, retrait d'un repas) cherchent dans toutes les listes en cours ; un produit présent dans deux listes est dit avec son total par liste.
- `docs/voice/alexa.md` mis à jour ; budgets SQL des commandes vocales inchangés sans type.

## Critères d'acceptation

- [x] Sans type, aucune réponse d'Alexa ne change (tests existants inchangés dans leur logique).
- [x] « Ajoute de la viande hachée » avec Viande dans Boucherie : « C'est ajouté à Boucherie. » (Alexa dit « boucherie » : les noms sont dits en minuscules, comme tous les noms de `VoiceReplies`.)

## Réalisé

- **Ajouter un produit** : `AddSpokenIngredientHandler` lit la destination (`ListRoutingRepository::placementsFor`, qui remplace la lecture de l'unité d'achat : même nombre de requêtes). Liste principale : comportement d'avant (création, « liste précédente partie au drive »). Liste d'un type : `ActiveListForType::forType` (ouverture à la demande, jamais une liste `SENT`), `AddedItem::$listName`, jamais « nouvelle liste » ; `VoiceReplies` nomme la liste (quantité, total d'une ligne existante, choix deviné, proposition choisie, produit créé).
- **Demander** : `ActiveShoppingListReader` lit toutes les listes en cours en deux requêtes (principale d'abord, puis les types par date) ; une ligne par liste, `ActiveListLine::$listName` (`null` = principale). Un produit dans deux listes n'est pas un départage (`isTied` compte les produits) : « Oui : 1 paquet dans ta liste, 2 paquets dans la liste boucherie. » ; plusieurs produits qui se valent : un total par produit, toutes listes ensemble.
- **Retirer** : la ligne est retirée de la liste principale d'abord, sinon de la plus ancienne liste d'un type qui la porte (`findOneActiveWithRow`, une lecture : le même ordre que « diminuer un produit » de LST-005). Choix documenté dans « À relire » de la PR ; la réponse nomme la liste.
- **Planifier** : `PlanSpokenMealHandler` passe par `PlannedMealHandler::plan` avec `newMainListId` (la liste principale n'est créée que si un produit y va) ; « déjà prévu » = la recette est dans une liste en cours (`findOneActiveWithRecipe`) ; le résultat nomme les listes de type où le repas est allé.
- **Retirer un repas / baisser ses personnes / changer son total** : le repas est cherché dans toutes les listes en cours, l'écriture agit sur tout le groupe (`PlannedMealGroup`) ; une liste du groupe `SENT` refuse tout et Alexa dit « Ta liste est déjà partie au drive. ».
- Aucune nouvelle intention : `interaction-model.fr-FR.json` est inchangé.
- **Budgets** : sans type, aucun budget vocal ne monte, sauf `retirer-ingredient-par-la-voix-sans-liste` (1 -> 2, la résolution des alias précède désormais la recherche de la liste, qui sert aussi aux listes de type ; le webhook ne l'appelle jamais sans liste, la lecture dit d'abord « Tu n'as pas de liste en cours. ») ; les scénarios qui planifient un repas baissent de 3 requêtes (la recette est cherchée en une lecture qui n'hydrate rien). Nouveaux : `ajouter-ingredient-par-la-voix-dans-un-type`, `…-ouvert-a-la-demande`, `ajouter-article-dit-a-alexa-dans-un-type`, `retirer-ingredient-par-la-voix-dans-un-type`, `retirer-article-dit-a-alexa-dans-un-type`, `planifier-repas-dit-a-alexa-eclate`, `retirer-repas-dit-a-alexa-eclate`.
- Tests : `SpokenTypedListsHandlersTest`, `ActiveShoppingListLinesPostgreSQLLoaderTest`, `ActiveShoppingListContentsPostgreSQLRepositoryTest`, `ActiveListLookupCommandOHSTest`, `VoiceRepliesTest`, `AddSpokenItemHandlerTest`, `RemoveSpokenItemHandlerTest`, `RemoveSpokenMealHandlerTest`, `AddSpokenMealHandlerTest`, `GetSpokenItemInListQueryHandlerTest`, `Repas*AdaptersTest`, `AlexaWebhookApiFlowTest`.
