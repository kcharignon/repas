# DRV-305 — Orchestrateur, phase A (produits connus)

- **Dépôt** : front, `drive-agent/src/flow/`
- **Jalon** : M3 Agent
- **Taille** : M
- **Dépend de** : DRV-302, DRV-303, DRV-304 (et la méthode de disponibilité retenue par DRV-002, ligne 19 du rapport)
- **Statut** : fait (PR [repas-front#80](https://github.com/kcharignon/repas-front/pull/80), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 (comportement) ; DRV-303 (interface `RetailerAdapter`).

## Contexte

L'orchestrateur lit le message, le valide, demande confirmation, met au panier les produits connus, puis laisse la place à la phase B (DRV-306) et construit le résultat. C'est une machine d'états testable sans réseau ni DOM.

## Portée

1. Machine d'états : `START → VALIDATED → CONFIRMED → PHASE_A → PHASE_B → DONE` (+ `ABORTED`, `ERROR`). Dépendances injectées : `RetailerAdapter`, `ui`, `clock`, `storage`, `navigator` (Wake Lock), `location`.
2. Démarrage : lit `location.hash` (`#repas=…`), `decodeMessage` + `validatePlan` ; message absent : écran d'accueil ; invalide : `INVALID_MESSAGE` ; magasin du message ≠ magasin de la page : `STORE_MISMATCH` ; type `setup` : DRV-307.
3. Confirmation : récapitulatif (DRV-304) ; rien n'est envoyé avant le « Commencer ».
4. **Phase A** : pour chaque ligne `KNOWN` dans l'ordre du message, essayer ses `products` **dans l'ordre de priorité** : (a) vérifier la disponibilité du produit par la méthode retenue au spike DRV-002 (stock lu dans la recherche par libellé, ou réponse du panier) ; (b) si disponible, si `packs` est nul ou `packsStatus` vaut `TO_CHECK`, écran « Quantité à vérifier », puis `setCartQuantity(product.id, packs)` avec **les `packs` de ce produit** → issue `ADDED` avec `usedRank` (rang du produit utilisé, 1 = préféré) et `unavailableIds` (les produits précédents, indisponibles) ; (c) si indisponible, passer au produit suivant sans rien demander ; (d) si aucun produit de la liste n'est disponible, la ligne passe en phase B avec la liste des produits indisponibles (`unavailableIds`) et ses `rejected`. **Ne jamais** mettre au panier un produit absent de `products` ni un produit de `rejected`. Erreur `BLOCKED` après deux réponses consécutives → arrêt, résultat `partial`, lignes restantes `FAILED` (`BLOCKED`) ; `NOT_LOGGED_IN` → arrêt immédiat, résultat `error` ; `NETWORK` → une reprise puis `FAILED`.
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
- Étant donné une ligne `KNOWN` avec deux produits classés dont le préféré est indisponible, alors le second est mis au panier avec ses propres `packs`, sans question, et l'issue est `ADDED` avec `usedRank: 2` et `unavailableIds: [préféré]` ; si les deux sont indisponibles, la ligne passe en phase B avec les deux identifiants.
- Étant donné un produit présent dans `rejected`, alors il n'est jamais mis au panier, même si tous les produits acceptés sont indisponibles.
- Étant donné un rechargement de page au milieu, quand l'agent est relancé, alors il propose de reprendre au bon endroit sans réécrire les lignes déjà faites.
- La navigation de retour n'utilise que l'origine en dur et un `returnPath` autorisé.

## Tests exigés

Vitest : toutes les transitions avec adaptateur, interface et horloge simulés ; produits de secours (préféré indisponible, tous indisponibles, produit refusé) ; reprise ; Wake Lock absent ; résultats (`completed`, `partial`, `aborted`, `error`).

## Definition of Done

Celle du front.

## Pièges connus

- Un seul passage actif à la fois : si deux `runId` coexistent dans `sessionStorage`, proposer le plus récent et ignorer l'autre.
- Les erreurs ne contiennent jamais de contenu de page ni de cookies.

## Points laissés par DRV-302 et DRV-303 (2026-10-17)

- Entrée : `validatePlan(await decodeMessage(...))` via `Transport.listen` ; adaptateur : `new LeclercAdapter(browserDeps(window))`. Mettre `error.code` de `LeclercError` dans les issues de ligne ou globale ; comparer `CartResult.obtained` à la quantité voulue (`ok`) ; `contentSource === 'prices'` peut justifier `packsStatus: 'TO_CHECK'`.
- **Décision (prix, D22)** : une ligne `ADDED` doit aussi remonter le **prix lu** du produit (la phase A relit la recherche pour le stock) afin que Repas rafraîchisse le prix mémorisé à chaque envoi. Étendre le module `drive-agent/protocol/` (variante `ADDED` : `price?` comme `ProductPrice`, schémas, exemples, tests) et le dire dans `DRV-204` (le back accepte le prix d'une ligne `ADDED` pour mettre à jour le produit connu).

## Réalisation (2026-10-18, PR repas-front#80)

`drive-agent/src/flow/index.ts` : `new Flow(deps)` avec `start(message)`, `invalid()`, `history` ; `FlowDeps.phaseB(context)` (DRV-306 : reçoit `pending {line, unavailable}`, `record(ResultLine)`, `stopRequested()`, `showProgress()`, `adapter`, `ui` ; rend `'done' | 'cancelled'` ; une ligne non enregistrée devient `UNAVAILABLE`) et `FlowDeps.onSetup(message)` (DRV-307). L'accueil (`welcome`) n'est pas affiché par `agent.ts` : DRV-308 le fait. Exports : `settleKnownLine`, `priceOf`, `buildResult`, `endingOf`, `endProps`, `resultLines`, `RunStore`, `RUN_PREFIX`, `keepAwake`. Pont attendu : `window.repasHost` (`postMessage(texte)`, `addEventListener('message', …)`) ; `run(win, options)` accepte `origin`, `navigate`, `flow` pour les tests. Choix : une ligne `NEW` sans phase B sort `UNAVAILABLE` (passage `partial`) ; `CONTRACT_CHANGED` ou incident imprévu arrête en `error` sans citer le message ; `BLOCKED` met les lignes restantes qui demandent le site en `FAILED` ; reprise proposée seulement pour le même `runId`. Le prix des lignes `ADDED` est porté par `AddedLine.price?` (décision D22). `dist/agent.js` : 38 718 octets, version 0.2.0.

## Réalisation : corrections de la recette réelle (2026-10-18 et 19)

- [repas-front#135](https://github.com/kcharignon/repas-front/pull/135) (agent 0.12.0, ticket M) : `putInCart` (`flow/keep-cart.ts`) : la quantité du plan est un plancher ; l'agent n'écrit que si elle dépasse celle déjà au panier. Écart au libellé du ticket (« écris max(panier, plan) ») : rien n'est écrit quand le panier a déjà autant ou plus.
- [repas-front#130](https://github.com/kcharignon/repas-front/pull/130) (agent 0.11.0, ticket D) : un produit connu que le libellé complet ne retrouve pas est cherché par les formes dérivées du libellé avant d'être dit « plus disponible ».
- [repas-front#136](https://github.com/kcharignon/repas-front/pull/136) (agent 0.13.0, ticket K) : devant la page de défi le passage se suspend ; « Reprendre » refait la ligne en cours.
- Lignes du jalon : [`roadmap.md`](../roadmap.md) § M3c.
