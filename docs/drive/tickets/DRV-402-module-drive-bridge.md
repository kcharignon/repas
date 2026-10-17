# DRV-402 — Module `driveBridge`

- **Dépôt** : front, `src/features/drive/bridge/`
- **Jalon** : M4 Front
- **Taille** : M
- **Dépend de** : DRV-302
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 8, 9 ; DRV-302 (protocole) ; `docs/adr/0005-session-du-front.md` et `0006-pwa-et-lecture-hors-ligne.md` du front.

## Contexte

Le front parle à l'agent par fragment d'URL. Ce module isole tout ce qui concerne le protocole, l'ouverture de Leclerc et le brouillon de passage, pour que les écrans (DRV-403 à 405) n'en connaissent pas les détails.

## Portée

1. `buildPlanMessage(plan: DrivePlan, overrides, runId): PlanMessage` : à partir du plan du back (`GET /api/v1/drive/plans/{id}`, types générés) et des surcharges de l'utilisateur par ligne ; traduit chaque `retailerProductId` du plan en `id` du protocole et conserve l'ordre de priorité de `products` (5 au plus) et la liste `rejected` (20 au plus) ; surcharge `replace` : la ligne `KNOWN` devient `NEW` avec `products: []`, `rejected` conservé et `replaces` = le produit préféré ; surcharge `skip` : la ligne devient `SKIP` ; limite de 100 lignes (au-delà : erreur claire).
2. `buildLeclercUrl(baseUrl, message): string` = `baseUrl + '#repas=' + encodeMessage(message)` ; vérifie que `baseUrl` correspond au motif autorisé.
3. `openLeclerc(url)` : ouvre Chrome complet. Si l'application est installée (`matchMedia('(display-mode: standalone)')`), utiliser un lien d'intention Android `intent://<hôte><chemin>#Intent;scheme=https;package=com.android.chrome;end` (hôte et chemin issus de l'URL ; **le fragment du message est placé dans le paramètre `S.browser_fallback_url` ou dans le chemin selon ce que DRV-002 a validé**) ; sinon `window.location.assign`/`window.open`. Le comportement exact (lien d'intention ou lien normal) est celui décidé à G1 (`DRV-005`).
4. `readResult(hash: string): ResultMessage | SetupResultMessage` : décode et valide (`validateResult`), refuse tout le reste ; puis `clearHash()` (remplacer l'URL par `history.replaceState` pour ne pas laisser le fragment dans la barre d'adresse ni l'historique).
5. Brouillon de passage : `saveDraft({runId, listId, startedAt})`, `loadDraft()`, `clearDraft()` dans `localStorage` (clé `repas.drive.run`), durée de vie 24 h, valeur validée à la lecture ; ne contient aucune donnée personnelle. `matchesDraft(result)`: le `runId` du résultat doit être celui du brouillon, sinon le résultat est ignoré (`stale`).
6. Version minimale de l'agent : `MIN_AGENT_VERSION` et `isAgentCompatible(agentVersion)` ; résultat d'un agent trop ancien → message « mets à jour le favori ».
7. Aucune requête réseau dans ce module ; aucune logique métier (les conditionnements viennent du plan).

## Critères d'acceptation

- `buildLeclercUrl` produit une URL dont le fragment décode en un plan valide ; une `baseUrl` hors motif est refusée.
- Une ligne à deux produits classés et un produit refusé sort du constructeur avec les mêmes produits dans le même ordre, chacun avec ses `packs`, et le refusé dans `rejected`.
- `readResult` rejette un résultat invalide, un `runId` inconnu, un agent trop ancien ; le fragment est effacé de l'URL.
- Le brouillon expire après 24 h et survit à un rechargement.

## Tests exigés

Vitest : toutes les fonctions (jsdom, `localStorage` simulé, `matchMedia` simulé).

## Definition of Done

Celle du front.

## Pièges connus

- Un fragment très long peut être tronqué par certains navigateurs : le plan est limité à 100 lignes et la taille vérifiée (< 16 Ko).
- Ne jamais afficher ni journaliser le message complet.
