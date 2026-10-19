# DRV-702 — Interrupteur par enseigne `DRIVE_RETAILERS`

- **Dépôt** : back (`src/Shared/`, `src/Drive/`, `deploy/`, `docs/production.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-701
- **Statut** : à faire. Effort recommandé : `high` (touche `deploy/` : `make deploy-smoke`).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) § 7 ; [`../architecture.md`](../architecture.md) § 5.3 (interrupteur) ; DRV-103 (`DRIVE_ENABLED`) ; skill `repas-ops` (`references/drive.md`) ; ADR 0017.

## Contexte

`DRIVE_ENABLED` coupe tout le drive. Pour réparer ou mettre en service **une** enseigne sans toucher l'autre, il faut un interrupteur par enseigne. L'humain seul l'allume (`consignes-agents.md`).

## Portée

1. Variable `DRIVE_RETAILERS` : liste d'identifiants séparés par des virgules, **par défaut `leclerc`** (`${DRIVE_RETAILERS:-leclerc}` dans `deploy/compose.yaml`, puis `deploy/.env.example`, `docs/production.md`, `.env`/`.env.test`/`phpunit.dist.xml` pour les tests). Un identifiant inconnu du registre est ignoré et journalisé une fois au démarrage (jamais une erreur qui empêche de démarrer).
2. Port `Shared\Application\Interface\DriveFeature` : `isRetailerEnabled(string $retailer): bool` et `enabledRetailers(): list<string>` ; implémentation `EnvDriveFeature` ; double `ControlledDriveFeature` des tests.
3. Les handlers du contexte `Drive` qui reçoivent une enseigne la refusent si elle est désactivée : `DriveFeatureException::retailerDisabled()` → `404 DRIVE_RETAILER_DISABLED` (après la sécurité et l'interrupteur global). Routes concernées : celles qui portent `retailer` dans le corps ou la requête, et plans/estimations.
4. `GET /drive/settings` ne liste que les enseignes activées dans `retailers`. Si `DRIVE_ENABLED=false`, rien ne change (`enabled: false`).
5. `DriveRoutesAreGuardedBySwitchTest` : tableau de toutes les routes avec enseigne ; `reopen` et la purge du compte restent permis quelle que soit l'enseigne.
6. Runbook (`docs/drive/runbook.md` § « couper ») et `docs/production.md` : « couper une seule enseigne » ; skill `repas-ops`.

## Hors portée

Allumer une enseigne en production (l'humain) ; l'interrupteur dans l'interface ; la sélection (DRV-703).

## Critères d'acceptation

- Étant donné `DRIVE_RETAILERS` absent, alors la production se comporte comme avant (Leclerc seul), tous les tests de l'interrupteur global passent sans changement d'attente.
- Étant donné `DRIVE_RETAILERS=` (vide), alors aucune enseigne : `PUT /drive/settings` répond `404 DRIVE_RETAILER_DISABLED`, `GET /drive/settings` répond `retailers: []`.
- Étant donné une enseigne désactivée, ses données restent en base et reparaissent quand elle est réactivée (test de base).
- `reopen` d'une liste `SENT` répond `204` même si toutes les enseignes sont désactivées.

## Tests exigés

`EnvDriveFeatureTest` (analyse de la liste, espaces, doublons, inconnus) ; handlers en unitaire avec `ControlledDriveFeature` ; `DriveRoutesAreGuardedBySwitchTest` ; `make deploy-smoke`.

## Definition of Done

Celle du skill `repas-dev` ; `make deploy-smoke` vert ; variable listée avec sa valeur par défaut dans `deploy/compose.yaml`, `deploy/.env.example` et `docs/production.md` ; `docs/api/openapi.json` (nouveau code `404 DRIVE_RETAILER_DISABLED`) ; roadmap cochée.

## Pièges connus

- Ne pas confondre avec un retrait de l'enseigne du registre : désactiver n'efface rien.
- Le code `DRIVE_DISABLED` existant ne change pas (l'application le connaît) ; le nouveau code est distinct.
- Un agent n'allume rien : la variable se règle dans `/opt/repas/.env` par l'humain.
