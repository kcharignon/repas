# VOX-301 — Corriger un ajout vocal : origine « voix », « Pas le bon ? », mémoire de phrase

- **Dépôt** : back puis front (VOX-403)
- **Jalon** : M3
- **Taille** : M
- **Dépend de** : VOX-204
- **Faisable sans téléphone** : oui
- **Statut** : à faire (après la première recette sur l'Echo)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) § 4 ; [ADR 0029](../../adr/0029-commande-vocale-alexa.md) décision 3 ; `docs/ingredients/fusion.md` (alias, « Pas le même ») ; ADR 0016 (versions de la liste).

## Contexte

L'apprentissage des habitudes : quand l'utilisateur corrige l'ingrédient choisi par la voix, Repas retient « cette phrase veut dire cet ingrédient » et ne se trompe plus.

## Portée

1. **Origine de la ligne** : colonne `shopping_list_row.origin` (nullable, `voice`), migration additive ; posée par `VoiceShoppingList::addItem` ; exposée en lecture (`ShoppingListRowDTO.origin`, clé toujours écrite) ; **jamais** utilisée pour une règle métier.
2. **Mémoire de phrase** : table `voice_phrase_choice(user_id, phrase, ingredient_slug, times, updated_at)` (purgée avec le compte) ; l'adaptateur de VOX-102 lit le slug mémorisé de la phrase normalisée et le passe au ranker.
3. **Correction** : `POST /api/v1/voice/corrections` `{rowId, ingredientSlug}` (JWT) : remplace l'ingrédient de la ligne par un ingrédient de l'utilisateur (alias résolus), écrit la mémoire (phrase d'origine retenue à l'ajout dans `voice_added_item(row_id, phrase)`), version de la liste +1, `assertEditable()` (`409 SHOPPING_LIST_SENT`), `assertVersion`.
4. Une ligne cochée ou une liste `SENT` n'est pas corrigeable (`409`).

## Critères d'acceptation

- « tomate » ajoutée → *tomate cerise* ; corrigée en *tomate* ; au prochain « tomate », le ranker renvoie *tomate*, `SURE`, même si les achats disent l'inverse.
- Corriger la ligne d'un autre utilisateur : `403`. Ingrédient d'un autre : `404`. Liste envoyée : `409`.
- Aucune ligne `origin = voice` n'est traitée autrement par les autres cas d'usage (fusion, envoi au drive).

## Tests exigés

Handler en unitaire ; dépôts et loader sur base réelle ; `WriteBudget` ; `IngredientReferencesAreAllRepointedTest` et `UserOwnedDataCoverageTest` verts (la mémoire référence un ingrédient : la **fusion** doit la repointer).

## Pièges connus

- Toute table qui référence un ingrédient doit être repointée par la fusion (ADR 0028 / `fusion.md`).
- Ne pas stocker la phrase brute de l'utilisateur au-delà de la forme normalisée.
