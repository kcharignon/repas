# DRV-703 — Drive habituel, dernière enseigne utilisée, retrait d'un drive et sélection de l'enseigne

- **Dépôt** : back (`src/Drive/`, migration, `docs/api/openapi.json`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701, DRV-702
- **Statut** : à faire. Effort recommandé : `xhigh` (migration, données de l'utilisateur, purge du compte).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 4.4, 5 ; [ADR 0027](../../adr/0027-plusieurs-enseignes-de-drive.md) décisions 4 et 5 ; ADR 0021 (purge) ; `UserOwnedDataCoverageTest` ; `.claude/skills/repas-drive-back/SKILL.md` (migrations, budgets SQL, purge, limiteur).

## Contexte

La règle de choix de l'enseigne (R1 à R5) doit être **une seule règle**, calculée par le back, que le web et tout futur client lisent. Elle a besoin de deux données de la personne : son **drive habituel** (choisi dans « Moi », facultatif) et la **dernière enseigne utilisée**. Il faut aussi pouvoir **retirer** un drive configuré (sinon une enseigne essayée par curiosité impose R3/R4 pour toujours).

## Portée

1. **Service de domaine pur** `RetailerSelection::resolve(list<Retailer> $enabled, list<Retailer> $configured, ?Retailer $default, ?Retailer $lastUsed): Selection` avec `Selection {mode: NONE|AUTO|ASK, retailer: ?Retailer, candidates: list<Retailer>}` : R1 à R5 de `multi-enseignes.md` § 5.1 (une enseigne seule : `AUTO` ; plusieurs et un habituel configuré et activé : `AUTO` ; plusieurs sans habituel : `ASK`, présélection = dernière utilisée si elle est candidate, sinon la première configurée par date d'enregistrement du réglage ; aucune : `NONE`). Test en table exhaustif.
2. **Agrégat** `RetailerPreference` (`userId`, `defaultRetailer ?`, `lastUsedRetailer ?`, `updatedAt`), port `RetailerPreferenceRepository`, table `drive_retailer_preference(user_id VARCHAR(36) PK, default_retailer VARCHAR(20) NULL, last_used_retailer VARCHAR(20) NULL, updated_at TIMESTAMP)` ; migration **compatible avec la version précédente du code** (nouvelle table). Entité Doctrine, dépôt PostgreSQL, double en mémoire.
3. **Écritures** (un cas d'usage = une commande = un contrôleur, `#[RateLimitedPerUser(DriveRateLimit::WRITE)]`, `#[RequiresDrive]`) : `SetDefaultRetailer` → `PUT /api/v1/drive/default-retailer` `{retailer: string|null}` (`422 DRIVE_RETAILER_NOT_CONFIGURED` si l'enseigne n'est pas configurée et activée) ; `RememberLastRetailer` → `PUT /api/v1/drive/last-retailer` `{retailer}` (mêmes règles, `204`) ; `RemoveDriveSettings` → `DELETE /api/v1/drive/settings?retailer=` (retire le **réglage** de l'enseigne, **garde** les choix et les produits ; efface le drive habituel et la dernière enseigne s'ils pointaient vers elle ; `404` si elle n'était pas configurée).
4. **Lecture** : `GET /drive/settings` ajoute `defaultRetailer`, `lastUsedRetailer`, `selection {mode, retailer, candidates}` et `isDefault` par enseigne ; l'enseigne par défaut de DRV-701 (`Retailer::default()`) est **remplacée** par `selection.retailer` pour `plans` et `estimates` sans `?retailer=` (aucun candidat : `409 DRIVE_NOT_CONFIGURED`, comme aujourd'hui).
5. **Purge du compte** : `PurgeUserDriveData` supprime la préférence ; `UserOwnedDataCoverageTest` la voit (`userId`).
6. **Budget SQL** : scénarios `WriteBudget` pour `SetDefaultRetailer`, `RememberLastRetailer`, `RemoveDriveSettings` ; budget de la suppression de compte revu.

## Hors portée

L'écran (DRV-709, 710) ; la reprise des choix d'un magasin à l'autre ; le recalcul de la dernière enseigne par le serveur à partir des résultats (c'est le front qui l'écrit quand la personne ouvre le drive, DRV-710).

## Critères d'acceptation

- Étant donné un utilisateur sans ligne dans `drive_retailer_preference` et une seule enseigne activée, alors `selection` est `AUTO` (magasin enregistré) ou `NONE` (sinon) : **exactement le comportement d'avant**.
- Étant donné deux enseignes de test configurées, un drive habituel A et une dernière utilisée B, alors `selection` = `AUTO` sur A (D-M1 : le plus explicite gagne).
- Étant donné deux configurées sans habituel et la dernière utilisée B, alors `ASK` avec B présélectionnée et les deux candidates.
- Étant donné le drive habituel désactivé par `DRIVE_RETAILERS`, alors `ASK` ou `AUTO` sur les autres, jamais une erreur.
- Étant donné la suppression du compte, alors la ligne de préférence n'existe plus.
- `PUT /drive/default-retailer` avec une enseigne non configurée : `422 DRIVE_RETAILER_NOT_CONFIGURED` ; `204` sinon.

## Tests exigés

`RetailerSelectionTest` (table R1 à R5 et cas limites) ; handlers en unitaire avec `RetailerPreferenceInMemoryRepository` ; dépôt et loader sur base réelle ; contrôleurs avec `MockCommandBus` / `MockQueryBus` ; `DriveRoutesAreGuardedBySwitchTest` et `DriveRoutesAreRateLimitedTest` étendus ; parcours de bout en bout (`DriveSettingsApiFlowTest`) ; `UserOwnedDataCoverageTest` ; `make sql-budget-update` relu.

## Definition of Done

Celle des skills `repas-dev` et `repas-drive-back` : `make test`, `make quality`, `make openapi` (diff relu), `docs/drive/architecture.md` §§ 5.2, 5.3, 7 (nouvelle table, nouvelles routes, `selection`), `docs/drive/samples/settings.sample.json` régénéré, migration vérifiée compatible avec la version précédente, roadmap cochée.

## Pièges connus

- Une `Query` ne modifie pas l'état : « dernière utilisée » s'écrit par une commande explicite, jamais en lisant un plan.
- La préférence ne référence l'enseigne que par son identifiant (ADR 0013) ; aucune clé étrangère.
- Une enseigne retirée de `DRIVE_RETAILERS` n'efface pas la préférence : la sélection l'ignore tant qu'elle est désactivée.
- Ne pas déduire « configurée » d'un choix : c'est la présence d'un `DriveSettings`.
