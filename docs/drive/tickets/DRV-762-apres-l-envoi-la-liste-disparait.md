# DRV-762 — Après « Envoyer au drive », l'écran dit « Aucune liste en cours » au lieu d'ouvrir l'envoi

- **Dépôt** : front (`src/features/shopping-lists/`), site seul (l'application charge le site : pas de nouvelle version d'application)
- **Jalon** : M7 Plusieurs enseignes (recette réelle du 2026-11-02, application 1.6.3)
- **Taille** : S
- **Dépend de** : rien
- **Statut** : fait (front PR #314 fusionnée le 2026-11-02, site v1.19.1 déployé ; relue par un agent indépendant ; recette réelle faite sur le téléphone de test). **Cause confirmée mais plus étroite que l'hypothèse** : le mécanisme (rappel de `mutate()` perdu au démontage de l'en-tête) est exact, mais il ne joue que quand la liste envoyée est **une liste d'un type** et la seule en cours (avec une liste principale seule, `useActiveShoppingList` garde `lastId` et l'en-tête reste monté). La suppression de la seule liste d'un type avait le même défaut. Correction : la navigation se fait dans le `onSuccess` de `useMutation`, qui s'exécute même après démontage et avant la relecture.
- **Lire d'abord** : `src/features/shopping-lists/ShoppingListHeader.vue` (`confirmSend`), `src/features/shopping-lists/queries.ts` (`useSendShoppingList`), `src/features/shopping-lists/ShoppingListView.vue` (le cas `id === null`), `src/features/shopping-lists/__tests__/ShoppingListSent.spec.ts` (le test ajouté par la PR #292) ; [DRV-760](DRV-760-recette-reelle-de-la-1-6-2.md) pour la recette

## Contexte

Le geste central du produit est : la personne touche « Envoyer au drive », confirme, et le drive s'ouvre tout seul sur son panier (`ouvrir=1`, ADR 0023 ; DRV-622). Pendant la recette réelle de 1.6.3 (téléphone de test, compte de démo, 2026-11-02), après la confirmation, l'écran affiche **« Aucune liste en cours. Crée une liste pour y planifier des recettes et noter tes courses. »** avec les boutons « Créer ma liste » et « Mes listes ». Le drive ne s'ouvre pas.

Un novice croit que sa liste a disparu. Elle n'a pas disparu : « Mes listes » la montre « Envoyée au drive le … », et « Reprendre l'envoi » fonctionne. Mais le geste qui devait mener au drive en un seul pas mène à un cul-de-sac.

## Constats (mesurés)

1. **Reproduit deux fois** (recette de 1.6.0 le 2026-11-01, « une fois, … au lieu de l'écran d'envoi » ; recette de 1.6.3 le 2026-11-02). La seconde fois dans ce cas précis : **une seule liste en cours** (la liste « Marche », rouverte), envoyée depuis l'onglet Liste. Après la confirmation, l'application reste sur l'écran du front (jamais sur l'écran du drive), la route n'est pas `drive-send`.
2. La liste est bien passée à `SENT` (back : `POST /api/v1/shopping-lists/{id}/send` réussi). Seule la navigation après l'envoi manque.

## Cause probable (lue dans le code, à confirmer par un test qui échoue d'abord)

1. `useSendShoppingList` renvoie, dans `onSettled`, la **promesse** de `invalidateQueries` : TanStack Query la **attend**. La relecture des listes se termine donc **avant** les rappels passés à `mutate(…, { onSuccess })`.
2. La liste envoyée n'est plus « en cours ». S'il n'y a **aucune autre liste en cours**, `ShoppingListView` passe à `id === null` (le bloc « Aucune liste en cours ») et **démonte `ShoppingListHeader`**.
3. Dans TanStack Query v5, les rappels passés à `mutate()` **ne s'exécutent pas** si le composant est démonté avant la fin de la mutation. `confirmSend` fait son `router.push({ name: 'drive-send', … })` dans ce rappel : il ne part jamais.
4. La PR #292 (« l'envoi ouvre l'écran d'envoi sur la liste envoyée ») a réglé le cas voisin « une autre liste prend la place » et son test le dit en toutes lettres (« la principale a déjà été vue : l'en-tête reste monté quand l'accueil bascule dessus »). Le cas « aucune autre liste en cours », le plus courant pour quelqu'un qui n'a qu'une liste, n'est pas couvert.

## À faire

1. **Un test qui échoue d'abord** : une seule liste en cours, la confirmation de l'envoi, la relecture répond « aucune liste en cours » ; on attend la route `drive-send` sur l'identifiant de la liste envoyée avec `ouvrir=1`. Il échoue aujourd'hui s'il reproduit bien le démontage ; sinon la cause ci-dessus est fausse et il faut la chercher ailleurs (dire laquelle).
2. **La navigation ne dépend plus d'un composant qui peut disparaître** : la faire au niveau de la mutation (rappel de `useMutation`, pas de `mutate()`), ou avant la fin de la relecture, au choix du développeur ; garder « l'identifiant est pris avant l'envoi » (PR #292) et ne jamais ouvrir `drive-send` deux fois.
3. **Aucun clignotement** : la personne ne voit jamais « Aucune liste en cours » entre la confirmation et l'écran d'envoi (garder l'écran de la liste, ou un état d'attente, tant que l'envoi n'est pas fini).
4. **Même motif ailleurs** : relire les autres `mutate(…, { onSuccess })` des écrans de listes que la relecture des listes peut démonter (suppression d'une liste qui était la seule en cours : `confirmDelete` pousse `shopping-lists` dans un rappel de `mutate()`, « Terminer » et « Rouvrir » : `status.mutate`, `confirmReopen`) ; corriger ceux qui sont touchés, avec un test chacun, ou dire pourquoi ils ne le sont pas.

## Non vérifié

- La cause (point 1 à 3 ci-dessus) n'a pas été reproduite en test : elle est tirée de la lecture du code et de la recette. Le premier test de « À faire » la confirme ou la renverse.
- Le comportement après la correction sur le téléphone : à rejouer en recette (liste unique envoyée : l'écran d'envoi s'ouvre, puis le drive).

## Livraison

Front seulement (le site). Release du site à faire approuver par le propriétaire, comme d'habitude. L'application et le back ne changent pas. Recette : téléphone de test, une seule liste en cours, « Envoyer au drive », confirmer : l'écran d'envoi s'ouvre et le drive s'ouvre seul.
