# VOX-304 — Retirer un produit de la liste et demander ce qu'elle contient

- **Dépôt** : back (`Voice`, `Repas`, `Shared`) ; le front n'a rien à faire
- **Jalon** : M3
- **Taille** : L
- **Dépend de** : VOX-204, VOX-303
- **Faisable sans téléphone** : oui (faux Amazon, signatures de test) ; l'essai réel se fait avec le propriétaire (scénarios 13 et 14 de [`../seance-en-direct.md`](../seance-en-direct.md))
- **Statut** : fait (2026-10-25)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) §§ 2 et 3 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décisions 5, 11 et 12 ; `.claude/skills/repas-po/SKILL.md` (les textes d'Alexa).

## Contexte

Demande du propriétaire, après l'essai réel du 2026-10-25 : « Si j'ai dit ajouter un paquet de croquettes et que j'entends un total de 2, cela ne me va pas : j'avais juste oublié que j'en avais déjà mis dans ma liste. Il faut pouvoir dire *enlève* ou *déduis*, tout comme on ajoute. Idem pour avoir des infos sur ma liste en cours : *est-ce qu'il y a des croquettes dans la liste ?* → *oui, tu en as X*, ou *non*. »

## Ce qui est livré

1. **`RemoveItemIntent`** (créneaux `item`, `quantity` du type `QUANTITY`, `unit`) : « enlève / retire / déduis / supprime / ôte », avec ou sans « de la liste ». Quantité facultative, 1 par défaut. Commande `RemoveSpokenItem` (Voice) : lecture de la liste par le port `SpokenListLookup`, retrait par le port `VoiceShoppingList::removeItem` (commande `RemoveSpokenIngredient`, Repas, qui renvoie `RemovedItem`).
2. **`CheckItemIntent`** (créneau `item`) : lecture seule, query `GetSpokenItemInList` (Voice) par le même port `SpokenListLookup`. Rien n'est écrit, aucune liste n'est créée.
3. **Domaine** : `ShoppingList::removeQuantity` (la ligne perd la quantité, disparaît si elle est épuisée avec la part ajoutée à la main de son ingrédient, jamais de quantité nulle ou négative, trois décimales pour la poussière de virgule flottante) ; `SpokenLineQuantity` (service d'application, la règle d'unité de l'ajout extraite sans changer une requête : unité dite convertie, laissée de côté ou prise telle quelle) partagée par `AddSpokenIngredientHandler` et `RemoveSpokenIngredientHandler`.
4. **Choix de l'ingrédient** : `SpokenListLookupAdapter` (Repas) lit les lignes de la liste active (`ActiveShoppingListLines`, deux requêtes), puis le classeur de l'ajout désigne l'ingrédient ; `SpokenIngredientRanker::equivalents()` rend tous ceux qui valent la phrase par leur nom, quelle que soit la confiance (les habitudes qui tranchent n'en cachent plus aucun), et ceux qui sont dans la liste passent avant (un seul : aucune question ; deux doublons de même nom et même rayon : la ligne de plus grande quantité). Jamais de proposition, de création ni de mémoire de phrase.
5. **Plusieurs lignes qui se valent** : pour retirer, le départage par le rayon de la décision 11, avec `SpokenDialogueState::action` (`remove`, écrit seulement pour un retrait, refusé par `fromArray` hors d'un départage) ; pour consulter, une phrase énumère les lignes.
6. **Textes** (`VoiceReplies`) : « C'est retiré, il t'en reste 2 paquets dans ta liste. », « C'est retiré : croquette. Il n'y en a plus dans ta liste. », « Il n'y a pas de croquette dans ta liste. », « Tu n'as pas de liste en cours. », « Ta liste est déjà partie au drive. », « Oui, tu en as 2 paquets. », « Non, il n'y a pas de croquette dans ta liste. », « Oui : 2 tomates en fruit et légume, 1 en surgelé. », « D'accord, je ne retire rien. ».
7. **Skill** : deux intentions de plus (quatorze), 240 phrases pour retirer, 164 pour consulter, sans trait d'union ni « un » / « une » comme mots de liaison (`DocsVoiceSkillTest`).

## Décisions prises à l'écriture

- **Jamais de question ouverte** à « enlève » seul : la réponse brève « du pain » serait comprise comme un ajout (Alexa choisit l'intention sans connaître l'état de la séance). Un exemple est dit, la séance se ferme (ou reste ouverte sans relance si elle l'était).
- **Une liste envoyée au drive n'est jamais lue ni modifiée** : « Ta liste est déjà partie au drive. » (revue du 2026-10-25 : « Tu n'as pas de liste en cours. » était trompeur) ; `ActiveShoppingListLines::hasSentList` ne pose la question que sans liste active, une requête. Limite connue, documentée : une consultation de la liste envoyée n'existe pas dans cette version.
- **Doublons non fusionnés** (même nom ET même rayon, qu'une question ne distinguerait pas) : la ligne de la liste ; s'ils y sont tous les deux, la ligne de plus grande quantité (choix le plus simple, la fusion règle le fond).
- **Un reste inférieur à 0,005 est épuisé** (`ShoppingList::NEGLIGIBLE_QUANTITY` = `RemovedLine::NEGLIGIBLE_QUANTITY`, un test les compare) : Alexa dit deux décimales, jamais « il t'en reste 0 litre ».
- **Réponse courte** : quand « C'est retiré : X. Il n'y en a plus dans ta liste. » dépasserait 15 mots (unité et produit longs), seule « C'est retiré : X. » est dite.
- **« Enlève … » et « est-ce qu'il y a … ? » au milieu d'une question** sont de nouvelles demandes (`NEW_REMOVAL`, `NEW_CHECK`), comme « ajoute … » : le dialogue est abandonné, la demande est faite, la séance se ferme.
- **Retirer plus que ce qu'il y a** vide la ligne (« C'est retiré : 2 croquettes. Il n'y en a plus dans ta liste. » dit ce qui a réellement été retiré), jamais d'erreur.
- **Une ligne qui vient aussi d'une recette** : seule la part ajoutée à la main (même unité) baisse d'autant sans passer sous zéro ; la ligne, elle, perd toute la quantité retirée. Retirer à la voix ne retire jamais un repas planifié.
- **Annuler le dernier ajout** (« annule ») reste hors périmètre : « enlève » la remplace.

## Critères d'acceptation

- Un retrait de 1 sur 3 donne 2 ; de plus que la ligne la supprime ; sans liste, sans ligne ou liste envoyée : rien n'est écrit ni créé (`RemoveSpokenIngredientHandlerTest`, `VoiceShoppingListFlowTest`, `AlexaWebhookApiFlowTest`).
- Deux « tomate » dans la liste : la question du rayon, puis le retrait de la ligne choisie, rien de retenu ; un état forgé ne retire rien chez un autre utilisateur (`AlexaWebhookApiFlowTest`).
- La consultation répond oui avec la quantité, non, ou pas de liste, sans écrire (version de la liste inchangée).
- `make test`, `make quality` verts ; budgets SQL figés.

## À refaire dans la console Amazon avant l'essai

Recoller `docs/voice/skill/interaction-model.fr-FR.json` (Build → Interaction Model → JSON Editor → Save → Build skill) après la mise en production de la version qui contient ce ticket ; revérifier Build → Endpoint (HTTPS, `https://kantin.eu/api/voice/alexa`).
