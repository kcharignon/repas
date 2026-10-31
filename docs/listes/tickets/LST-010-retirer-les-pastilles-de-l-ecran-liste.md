# LST-010 — Retirer les pastilles de l'écran « Liste » : on change de liste par « Mes listes »

- **Dépôt** : front (web) — **Taille** : S — **Effort** : high — **Dépend de** : LST-007
- **Statut** : à faire.
- **Lire d'abord** : skills `repas-front-dev`, `repas-po` ; ADR 0036 ; `src/features/shopping-lists/ShoppingListView.vue`, `ShoppingListPills.vue`, `ShoppingListsView.vue`, `ShoppingListHeader.vue` (prop `several`) ; `e2e/open-lists.spec.ts`.

## Contexte

Demande du propriétaire (2026-10-31, après la recette de LST-009) : « quand je suis sur une liste, je ne veux pas voir les boutons en haut pour les autres types de liste ouverts ; je dois repasser par Mes listes ». LST-007 avait posé en haut de l'écran « Liste » une pastille par liste en cours (« Principale 0 à cocher », « Boucherie 1 à cocher »…). Elles sont retirées : l'écran montre **une seule liste**, et on en change en passant par « Mes listes ». Une liste en cours ouverte depuis « Mes listes » arrive déjà sur cet écran avec `?liste=` ; les listes envoyées et terminées ont leur propre page (`ShoppingListDetailView`), qui n'a jamais eu de pastilles.

## À faire

- **Ne plus afficher les pastilles** sur `ShoppingListView.vue`. Supprimer `ShoppingListPills.vue` et son test (code mort), ainsi que les textes devenus inutiles (`shoppingList.openLists`, `mainPill`, `toTick`) s'ils ne servent plus ailleurs.
- **Garder tout le reste du choix de la liste** : `?liste=` (« Voir » du message de planification, « Mes listes », rechargement), repli sur la liste principale puis sur la première liste en cours quand il n'y a que des types, attente de la lecture des listes avant d'afficher, chute sur la principale quand la liste demandée n'est plus en cours. Seul le moyen de **changer** de liste sur place disparaît (`choose`).
- **`several` du `ShoppingListHeader`** : lire à quoi il sert et le conserver tel quel s'il dit « il y a plusieurs listes en cours » (ne plus le dériver de « les pastilles sont visibles »).
- **Le lien « Mes listes »** déjà présent sous la liste et dans l'écran « aucune liste » reste le chemin pour changer. Ne rien ajouter d'autre sans l'avis de `repas-po`.
- **Sans type de liste, rien ne change** (l'écran est déjà celui de la liste principale seule).
- Tests : les specs et l'e2e `open-lists.spec.ts` qui touchaient une pastille passent par « Mes listes » (ou par `?liste=`) ; ajouter un test : avec un type de liste ouvert, aucune pastille n'est affichée et la liste choisie par `?liste=` est celle qui s'affiche ; lire la capture de l'écran à 360 px avant de fusionner.
- Docs front dans le même commit (skill `repas-front-dev`, `CLAUDE.md` du front s'il parle des pastilles). Les docs du back (LST-007, roadmap des listes, ADR 0036) sont mises à jour à part par l'assistant principal.

## Ordre de livraison

Release du site seule, sans le back ni l'application.
