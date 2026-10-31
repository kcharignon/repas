# DRV-749 — « Envoyer au drive » ouvre l'écran d'envoi sur la liste envoyée

- **Dépôt** : front (web)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : XS
- **Dépend de** : LST-007 (types de liste, l'accueil ne montre que les listes en cours)
- **Statut** : fait (front PR #292, 2026-10-31), site `v1.16.1`. Test de non-régression : la relecture qui suit l'envoi rend une autre liste en cours, l'écran d'envoi s'ouvre sur la liste envoyée. Effort recommandé : `high`.
- **Lire d'abord** : `src/features/shopping-lists/ShoppingListHeader.vue` (`confirmSend`) ; `src/features/shopping-lists/queries.ts` (`useSendShoppingList`).

## Contexte

Constat de la recette du 2026-10-31 (téléphone de test, compte avec des types de liste). Depuis l'accueil, sur l'onglet d'un type (« Grand Frais »), « Envoyer au drive » puis la confirmation : l'envoi réussit, la liste est bien envoyée, mais l'écran d'envoi s'ouvre sur une **autre liste** (la liste principale, vide) et dit « Cette liste n'est plus envoyée au drive » (409 du plan). « Réessayer » n'y change rien. Reproduit à chaque lancement neuf de l'application ; depuis la page d'une liste (« Mes listes ») l'envoi se passe bien.

Cause : `confirmSend` lisait `props.list.id` dans le `onSuccess` du `mutate`. `useSendShoppingList` invalide les listes dans `onSettled` en rendant la promesse : TanStack Query l'attend avant les rappels de `mutate`. Une fois la liste partie, l'accueil ne montre plus que les listes en cours : `props.list` est devenue la liste principale quand `router.push` lit son identifiant.

## À faire

- Prendre l'identifiant de la liste **avant** l'envoi (`const listId = props.list.id`) et s'en servir pour l'envoi et pour la navigation.
- Un test de non-régression : la relecture qui suit l'envoi rend une autre liste en cours, l'écran d'envoi s'ouvre sur la liste envoyée.

## Ordre de livraison

Release du site seule, sans le back ni l'application.
