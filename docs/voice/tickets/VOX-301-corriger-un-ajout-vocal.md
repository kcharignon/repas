# VOX-301 — Corriger un ajout vocal : origine « voix », « Pas le bon ? » depuis l'application

- **Dépôt** : back puis front (VOX-403)
- **Jalon** : M3
- **Taille** : M
- **Dépend de** : VOX-204
- **Faisable sans téléphone** : oui
- **Statut** : à faire (après la première recette sur l'Echo) ; **périmètre réduit le 2026-10-25** : la mémoire de phrase existe (VOX-303)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) § 4 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décision 3 ; `docs/ingredients/fusion.md` (alias, « Pas le même ») ; ADR 0016 (versions de la liste).

## Contexte

La **mémoire de phrase existe depuis VOX-303** : quand l'utilisateur choisit l'une des propositions d'Alexa, Repas retient « cette phrase veut dire cet ingrédient » (`spoken_phrase_choice`, lue par `resolve` avant le classement, purgée, effacée avec l'ingrédient, repointée par la fusion). Reste ici de corriger **après coup**, depuis l'application, un ajout que le classeur avait choisi tout seul (« J'ai mis tomate cerise, fruit et légume » : ce n'était pas la bonne).

## Portée

1. **Origine de la ligne** : colonne `shopping_list_row.origin` (nullable, `voice`), migration additive ; posée par `VoiceShoppingList::addItem` ; exposée en lecture (`ShoppingListRowDTO.origin`, clé toujours écrite) ; **jamais** utilisée pour une règle métier.
2. **Mémoire de phrase** : déjà là (VOX-303). La correction l'écrit par la même commande `RememberSpokenPhrase` (`SpokenIngredientResolver::remember`), avec la clé de phrase retenue à l'ajout.
3. **Correction** : `POST /api/v1/voice/corrections` `{rowId, ingredientSlug}` (JWT) : remplace l'ingrédient de la ligne par un ingrédient de l'utilisateur (alias résolus), écrit la mémoire (clé de la phrase d'origine retenue à l'ajout dans `voice_added_item(row_id, phrase)`), version de la liste +1, `assertEditable()` (`409 SHOPPING_LIST_SENT`), `assertVersion`.
4. Une ligne cochée ou une liste `SENT` n'est pas corrigeable (`409`).

## Critères d'acceptation

- « tomate » ajoutée → *tomate cerise* ; corrigée en *tomate* ; au prochain « tomate », le ranker renvoie *tomate*, `SURE`, même si les achats disent l'inverse.
- Corriger la ligne d'un autre utilisateur : `403`. Ingrédient d'un autre : `404`. Liste envoyée : `409`.
- Aucune ligne `origin = voice` n'est traitée autrement par les autres cas d'usage (fusion, envoi au drive).

## Tests exigés

Handler en unitaire ; dépôts et loader sur base réelle ; `WriteBudget` ; `IngredientReferencesAreAllRepointedTest` et `UserOwnedDataCoverageTest` verts (`voice_added_item` référence une ligne et porte une phrase : purge du compte, suppression de la ligne).

## Pièges connus

- Toute table qui référence un ingrédient doit être repointée par la fusion (ADR 0028 / `fusion.md`). Limite connue de la mémoire (VOX-303) : si l'utilisateur crée plus tard un ingrédient au nom exact d'une phrase retenue, la mémoire gagne encore.
- Ne pas stocker la phrase brute de l'utilisateur au-delà de la forme normalisée.
