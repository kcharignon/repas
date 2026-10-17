# DRV-305 — Orchestrateur, phase A (produits connus)

- **Dépôt** : front, `drive-agent/src/flow/`
- **Jalon** : M3 Agent
- **Taille** : M
- **Dépend de** : DRV-302, DRV-303, DRV-304
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 (comportement) ; DRV-303 (interface `RetailerAdapter`).

## Contexte

L'orchestrateur lit le message, le valide, demande confirmation, met au panier les produits connus, puis laisse la place à la phase B (DRV-306) et construit le résultat. C'est une machine d'états testable sans réseau ni DOM.

## Portée

1. Machine d'états : `START → VALIDATED → CONFIRMED → PHASE_A → PHASE_B → DONE` (+ `ABORTED`, `ERROR`). Dépendances injectées : `RetailerAdapter`, `ui`, `clock`, `storage`, `navigator` (Wake Lock), `location`.
2. Démarrage : lit `location.hash` (`#repas=…`), `decodeMessage` + `validatePlan` ; message absent : écran d'accueil ; invalide : `INVALID_MESSAGE` ; magasin du message ≠ magasin de la page : `STORE_MISMATCH` ; type `setup` : DRV-307.
3. Confirmation : récapitulatif (DRV-304) ; rien n'est envoyé avant le « Commencer ».
4. **Phase A** : pour chaque ligne `KNOWN` dans l'ordre du message : si `packs` est nul ou `packsStatus` vaut `TO_CHECK`, écran « Quantité à vérifier » ; `setCartQuantity(product.id, packs)` ; succès → issue `ADDED` ; stock 0 → issue `UNAVAILABLE` et la ligne passe en phase B avec `replaces` ; erreur `BLOCKED` après deux réponses consécutives → arrêt, résultat `partial`, lignes restantes `FAILED` (`BLOCKED`) ; `NOT_LOGGED_IN` → arrêt immédiat, résultat `error` ; `NETWORK` → une reprise puis `FAILED`.
5. Lignes `HAVE_AT_HOME` → issue `HAVE_AT_HOME` sans action ; `SKIP` → issue `SKIPPED` ; `NEW` → phase B.
6. **Écran allumé** : `navigator.wakeLock.request('screen')` si disponible (échec toléré), libéré à la fin.
7. **Reprise** : état sauvegardé dans `sessionStorage` sous `repas:run:<runId>` (issues par ligne, phase) après chaque ligne ; au lancement, si un état existe pour le même `runId`, proposer « Reprendre » ou « Recommencer » ; état supprimé à la fin. Aucune donnée personnelle dedans.
8. **Résultat** : construire `ResultMessage` (`agentVersion`, `outcome` : `completed` si aucune erreur et aucune ligne `FAILED`/`UNAVAILABLE`, `partial` sinon, `aborted` si l'utilisateur arrête, `error` avec code), l'encoder, naviguer vers `REPAS_ORIGIN + returnPath + '#result=' + …` (`returnPath` ∈ liste blanche). L'arrêt par l'utilisateur renvoie aussi un résultat (`aborted`) avec ce qui a été fait.
9. Le marqueur `__REPAS_ORIGIN__` non remplacé : refus de naviguer, message d'erreur (`UNSUPPORTED_PAGE`).

## Hors portée

Choix guidé (DRV-306), mode configuration (DRV-307), diagnostic (DRV-308).

## Critères d'acceptation

- Étant donné un plan de 4 lignes (2 `KNOWN`, 1 `HAVE_AT_HOME`, 1 `SKIP`) et un adaptateur simulé, quand l'utilisateur confirme, alors 2 écritures `setCartQuantity` sont faites en série, le résultat contient `ADDED`, `ADDED`, `HAVE_AT_HOME`, `SKIPPED`, `outcome: completed`.
- Étant donné une réponse 403 deux fois de suite, alors le passage s'arrête, `outcome: partial`, les lignes restantes sont `FAILED`.
- Étant donné un produit connu indisponible, alors il passe en phase B (stock vérifié avant ou à l'écriture).
- Étant donné un rechargement de page au milieu, quand l'agent est relancé, alors il propose de reprendre au bon endroit sans réécrire les lignes déjà faites.
- La navigation de retour n'utilise que l'origine en dur et un `returnPath` autorisé.

## Tests exigés

Vitest : toutes les transitions avec adaptateur, interface et horloge simulés ; reprise ; Wake Lock absent ; résultats (`completed`, `partial`, `aborted`, `error`).

## Definition of Done

Celle du front.

## Pièges connus

- Un seul passage actif à la fois : si deux `runId` coexistent dans `sessionStorage`, proposer le plus récent et ignorer l'autre.
- Les erreurs ne contiennent jamais de contenu de page ni de cookies.
