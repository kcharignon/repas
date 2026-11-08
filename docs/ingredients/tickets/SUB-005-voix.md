# SUB-005 — Alexa : ajouter, retirer, demander une partie (back, contexte `Voice`)

- **Dépôt** : **back**
- **Jalon** : I8 Sous-ingrédients
- **Taille** : M
- **Effort recommandé pour l'agent** : **high** (textes de la voix, ports et OHS du contexte `Voice`, aucun contact avec Amazon)
- **Dépend de** : SUB-003 fusionné
- **Statut** : fait (2026-11-08)
- **Lire d'abord** : [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 11 et 33 ; `docs/voice/alexa.md` ; `CLAUDE.md` (puces « Webhook d'Alexa », « Retirer un produit… », « Voix et listes d'un type », « Contextes étanches ») ; `AddSpokenIngredientHandler`, `RemoveSpokenIngredientHandler`, `ActiveListLookup`, `SpokenListAnswerer`, `VoiceReplies` ; skill `repas-dev`. **Aucun agent ne contacte Amazon** (le modèle d'interaction ne change pas).

## À livrer

1. **Ajouter** (`AddSpokenIngredientHandler`, port `ShoppingListItemCommandPort::addItem`) : une partie ajoute la quantité dite **à la partie** (`ShoppingList::addPart`, SUB-003) dans la liste de son parent ; `AddedItem` décrit la **ligne du parent** (`quantity` = total, `unitName` du parent) et gagne `foldedInto: ?string` (le nom du parent, `null` pour un produit ordinaire). `VoiceReplies::added` : « C'est ajouté. Ça fait 3 œufs dans ta liste. » (tutoiement, 15 mots, `VoiceRepliesTest`). Le départage « en surgelé ou en fruit et légume ? » (ties) ne change pas.
2. **Retirer** (`RemoveSpokenIngredientHandler`, `ListLineLookup`) : un ingrédient désigné par la phrase qui est une partie dans le **registre** de la liste est retiré du registre (`subtractPart`), la ligne du parent est ajustée, la réponse dit le total (« C'est retiré. Il te reste 2 œufs. ») ; sans entrée : « Il n'y a pas de jaune d'œuf dans ta liste. ». Retirer le parent entier efface ses entrées (SUB-003). `RemovedItem` gagne `foldedInto`.
3. **Demander** (`GetSpokenItemInList`, `SpokenItemInListDTO`, `SpokenListAnswerer`) : « est-ce qu'il y a des jaunes d'œuf ? » lit le registre : « Oui : 2 jaunes d'œuf, ils comptent dans tes 5 œufs. » ; sans entrée : « Non, il n'y a pas de jaune d'œuf dans ta liste. ». `ActiveShoppingListContents` expose les entrées du registre avec le nom de la partie. Aucune requête de plus quand le registre est vide.
4. **Ports et OHS** (ADR 0035) : les types nouveaux (`foldedInto`, l'entrée du registre) sont ceux de `Voice` ; `ShoppingListItemCommandOHS`, `ActiveListLineLookupCommandOHS` et `ListLineLookup*` de `Repas` les rendent ; le consommateur ne connaît pas `Repas`. L'ACL transpose.
5. **Docs** : `docs/voice/alexa.md` (§ « Parties »), `CLAUDE.md` seulement si une règle se précise.

## Tests

- Handlers (`InMemoryRepository`, sans la base) : ajout d'une partie sans œuf dans la liste (« 1 œuf »), avec des œufs (total), avec quantité dite (« trois jaunes »), liste d'un type ; retrait avec et sans entrée, retrait du parent ; demande avec et sans entrée.
- `VoiceRepliesTest` (longueur, tutoiement, aucun jargon) ; `AlexaDialogueTest`/`HandleAlexaRequest` inchangés ; `DocsVoiceSkillTest` inchangé (aucune intention de plus).
- Vraie base (`SpokenPartsFlowTest`) : « 3 œufs » en liste, « ajoute un jaune d'œuf » (3 œufs entiers + 1 jaune = 4, comme la demande du propriétaire), puis « ajoute un blanc d'œuf » (reste 4 : il complète le jaune), « ajoute trois jaunes d'œuf » (3 + 4 jaunes = 7), retrait, demande.
- Budgets : `ajouter-article-dit-a-alexa*`, `retirer-article-dit-a-alexa*` inchangés pour un produit ordinaire ; un budget par geste pour une partie, constants.

## Critères d'acceptation

- [x] `make test`, `make quality` verts ; aucune intention ni modèle d'interaction modifiés.
- [x] PR vers `main` (**À relire** : les quatre phrases nouvelles).

## Réalisation

- **Ajouter** : `AddSpokenIngredientHandler` prépare une partie avant toute écriture (`PartAwareLines`, la quantité dite dans l'unité d'achat de la partie) et la pose par `addPart` dans la liste de son parent ; `AddedItem::$foldedInto` (Repas et Voice) décrit la ligne du parent ; `VoiceReplies` : « C'est ajouté. Ça fait 3 œufs dans ta liste. » (`PART_ADDED*`, `PART_REMEMBERED*`).
- **Retirer** : `ShoppingListRepository::findOneActiveHolding` (une ligne OU une entrée du registre, une requête) puis `subtractPart` (le registre de la liste décide avant toute ligne) ; `RemovedItem::$foldedInto` ; « C'est retiré. Il te reste 4 œufs. », « C'est retiré. Il n'y a plus d'œuf dans ta liste. ».
- **Demander** : `ActiveShoppingListReader::lines` rend les entrées du registre comme des lignes de la partie (`ActiveListLine::$foldedInto`, `ActiveListParent`), sans requête de plus quand le registre est vide et UNE quand il ne l'est pas ; OHS `ActiveListLineDTO::$foldedInto`, ACL `ListedLine`/`ListLine::$foldedInto` (`ParentLine`) ; « Oui : 2 jaunes d'œuf. Ça compte dans tes 5 œufs. ».
- **Les quatre phrases à relire** (écart avec le ticket sur la dernière : « Ça compte dans » au lieu de « ils comptent dans », pour n'accorder ni genre ni nombre) : voir l'amendement du 2026-11-08 (SUB-005) de l'[ADR 0038](../../adr/0038-sous-ingredients.md).
- **Tests** : `SpokenPartsHandlersTest` (en mémoire : ajout sans œuf, avec œufs, quantité dite, blanc après jaune, liste d'un type, liste envoyée, unité inconnue ; retrait avec et sans entrée, plus que l'entrée, le parent entier, liste d'un type, liste envoyée, jaune recollé), `SpokenPartsFlowTest` (vraie base : le scénario du propriétaire 3 œufs puis jaune, blanc, trois jaunes, demande, retrait ; sans jaune ; parent entier ; liste vide ; liste envoyée ; jaune d'un autre compte), `VoiceRepliesTest`, handlers de `Voice`, ACL, `ActiveShoppingListLinesPostgreSQLLoaderTest`, `ShoppingListRepositoryTest`, `ShoppingListItemCommandOHSFlowTest`, `PartsReadsAreConstantTest`.
- **Budgets** : articles ordinaires inchangés ; `ajouter-partie-par-la-voix` 15, `retirer-partie-par-la-voix` 13, `ajouter-partie-dit-a-alexa` 19, `retirer-partie-dit-a-alexa` 20.
