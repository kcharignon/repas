# DRV-402 — Module `driveBridge`

- **Dépôt** : front, `src/features/drive/bridge/`
- **Jalon** : M4 Front
- **Taille** : M
- **Dépend de** : DRV-302
- **Statut** : fait (PR [repas-front#93](https://github.com/kcharignon/repas-front/pull/93), 2026-10-18)
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

## Mise à jour G1 (2026-10-17)

`driveBridge` n'ouvre plus Leclerc dans Chrome : il **lance l'application Android** avec le plan par un lien `repasdrive://open?plan=<base64url>` (lien d'intention `intent://` avec le nom du paquet `eu.kantin.repas.drive`, en repli le lien direct) et lit le retour dans `/drive/retour#result=…`. Le brouillon de passage (`runId`, 24 h) et la validation par les schémas partagés restent. Si le lien n'ouvre rien (application absente), proposer la page d'installation (DRV-316) et le mode assisté. À valider sur un vrai téléphone avec la PWA installée (DRV-314, ligne 11 du rapport DRV-002).

## RÉVISION du 2026-10-18 (application Repas complète, D28)

Le pont est le **pont natif** de l'application Repas (`repasNative`, DRV-317 et DRV-314) : `driveBridge` détecte sa présence, envoie `drive.start` avec le plan et reçoit `drive.result`. Plus de lien `repasdrive://` ni d'ouverture de Chrome. Hors de l'application (navigateur ordinaire), proposer « Installer l'application Repas » (DRV-316) et le mode assisté. Le lien d'intention `intent://` n'est plus nécessaire.

## Réalisation (2026-10-18, PR repas-front#93)

`src/features/drive/bridge/` (`driveBridge.ts`, `native.ts`, `draft.ts`, `errors.ts`, `index.ts`, double `fakeNative`) : `isAvailable()`, `info()` (3 s), `startRun(plan, listId)` (valide `validatePlan`, 64 Ko, brouillon puis envoi `{v:1,type:'drive.start',plan}`), `cancel()` (`{v:1,type:'drive.cancel'}`), `listen()` (à appeler au démarrage dans `App.vue`), `onEvent` (`result`, `stale`, `refused`, `invalid` ; 20 événements gardés sans abonné), brouillon `repas.drive.run` 24 h (`loadDraft`, `clearDraft`, `matchesDraft`). Formats supposés pour l'application (à confirmer par DRV-314) : `drive.result = {v:1,type,result}`, `drive.error = {v:1,type,code,runId?}`. Pas de `buildPlanMessage` (DRV-404 traduit le plan du back).
