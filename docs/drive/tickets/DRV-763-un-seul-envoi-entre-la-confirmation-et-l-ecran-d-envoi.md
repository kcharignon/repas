# DRV-763 — Un seul envoi possible entre la confirmation et l'écran d'envoi

- **Dépôt** : front (`src/features/shopping-lists/`), site seul
- **Jalon** : M7 Plusieurs enseignes (restes de la recette réelle de 1.6.4)
- **Taille** : S
- **Dépend de** : [DRV-762](DRV-762-apres-l-envoi-la-liste-disparait.md)
- **Statut** : fait (front PR #316 fusionnée le 2026-11-02, site v1.19.2 déployé ; relue par un agent indépendant ; à rejouer sur le téléphone avec une route lente). **Nuance sur le constat** : un second `POST` n'était en réalité **pas** possible (le bouton de confirmation restait déjà bloqué pendant la navigation, la mutation étant `isPending` jusqu'à la fin de son `onSuccess`). Ce qui se produisait : le bouton « Envoyer au drive » restait actif et une seconde boîte de confirmation s'ouvrait avec un bouton mort ; même chose pour « Supprimer » dans le menu ⋮. La correction garde la confirmation « en cours » jusqu'à la fin de la navigation, grise les deux boutons, et ne laisse pas l'écran bloqué si la navigation échoue ; le test de DRV-762 a maintenant des dents (un mutant « relecture lancée en parallèle de la navigation » est détecté, sur l'envoi comme sur la suppression). Restes connus, faibles : trois gardes redondantes sans test (`askToSend`, `confirmSend`, `confirmDelete`), le retour arrière du navigateur pendant la navigation n'est pas testé, et si la navigation échoue alors que la liste envoyée était la seule en cours, l'écran dit « Aucune liste en cours » (état réel, pas bloquant)
- **Lire d'abord** : DRV-762, `ShoppingListHeader.vue` (`confirmSend`, `confirmDelete`), `queries.ts` (`useSendShoppingList`, `useDeleteShoppingList`), `ShoppingListSent.spec.ts`

## Contexte

La relecture indépendante de la PR #314 (DRV-762) a relevé deux restes, jugés faibles mais réels.

1. **Réentrée.** `confirmSend` ferme la boîte de confirmation avant le `router.push` vers l'écran d'envoi. Pendant le chargement de cette route, l'écran de la liste reste affiché (la liste est encore en cache « en cours »), la boîte fermée, et le bouton « Envoyer au drive » n'est pas désactivé par l'envoi en cours. Un second geste pourrait rouvrir la confirmation et relancer un envoi sur une liste déjà envoyée (sans effet ou refusé par le back, mais le geste n'a rien à faire là). Même fenêtre à vérifier pour la suppression d'une liste.
2. **Test sans dents.** Le test de DRV-762 sonde le DOM avec un `setInterval(0)` et ne détecte pas une relecture lancée en parallèle de la navigation : un mutant (`void invalidateQueries(...)` juste avant la navigation) laisse tous les tests passer. La garantie vient aujourd'hui de la structure de TanStack Query (le `onSuccess` de la mutation est attendu avant son `onSettled`), pas du test.

## À faire

1. Écrire d'abord les tests (ils échouent pour la bonne raison) : deux gestes rapprochés donnent un seul envoi ; un test qui échoue sur le mutant ci-dessus (réponse simulée de la relecture retardée, ordre des événements enregistré).
2. Empêcher tout second envoi entre la confirmation et l'arrivée sur l'écran d'envoi (bouton désactivé, ou boîte gardée « en cours » jusqu'à la fin de la navigation) ; même traitement de la suppression si elle a la même fenêtre. Une navigation qui échoue (route qui ne se charge pas) ne laisse pas l'écran bloqué.

## Non vérifié

- Le comportement sur le téléphone avec une route lente : à rejouer en recette.

## Livraison

Front seulement (le site) : release du site à faire approuver par le propriétaire. L'application et le back ne changent pas.
