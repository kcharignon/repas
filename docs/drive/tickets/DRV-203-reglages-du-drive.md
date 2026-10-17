# DRV-203 — Réglages du drive

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : M
- **Dépend de** : DRV-201, DRV-103
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 7 ; skill `repas-dev` §§ 3.1 à 3.3 ; ADR 0003, 0007 ; ADR 0020 (préférence d'un agrégat séparé plutôt que de grossir `User`).

## Contexte

L'utilisateur a un drive par enseigne : le magasin où il retire ses courses. Le magasin est **lu par l'agent** sur le site (jamais saisi à la main) puis enregistré ici. Les produits mémorisés sont propres à ce magasin.

## Portée

1. Domaine (`src/Drive/Domain/`) : enum `Retailer` (`LECLERC` = `'leclerc'`) avec `isValidStoreUrl(string $baseUrl): bool` et `storeIdOf(string $baseUrl): ?string` (motif `^https://[a-z0-9-]+\.leclercdrive\.fr/magasin-(\d+)-(\d+)$`, l'identifiant est le premier groupe de chiffres) ; valeur `Store` (`id`, `label` nullable ≤ 255, `baseUrl`) ; modèle `DriveSettings` (`userId`, `retailer`, `store`, `updatedAt`) avec `create()`/`load()`/`changeStore()` ; `DriveException` avec factories : `storeInvalid()` (422, `DRIVE_STORE_INVALID`), `notConfigured()` (409, `DRIVE_NOT_CONFIGURED`), `disabled()` (404, `DRIVE_DISABLED`).
2. Port `DriveSettingsRepository` (`findOneByUser(string $userId, Retailer $retailer): ?DriveSettings`, `save`, `deleteByUser`), implémentation `DriveSettingsPostgreSQLRepository` (hérite de `Shared\Infrastructure\Repository\PostgreSQLRepository`), entité Doctrine `DriveSettings` (table `drive_settings`, PK `(user_id, retailer)`), migration compatible avec la version précédente, `DriveSettingsInMemoryRepository` de test.
3. Écriture : `Application/Command/SaveDriveSettings/` (`SaveDriveSettingsCommand(userId, retailer, storeId, storeLabel, baseUrl)`, handler : valide l'URL et l'identifiant, upsert, horloge injectée).
4. Lecture : `Application/Query/GetDriveSettings/` (`GetDriveSettingsQuery(userId)` → `DriveSettingsDTO{enabled: bool, retailer: 'leclerc', store: {id,label,baseUrl}|null}`), port `DriveSettingsLoader`, `DriveSettingsPostgreSQLLoader`. `enabled` vient de `DriveFeature`.
5. API : `GET /api/v1/drive/settings` (toujours `200`, même interrupteur coupé : `enabled:false`) et `PUT /api/v1/drive/settings` (corps `{retailer, store:{id,label?,baseUrl}}` via `#[MapRequestPayload]`, `204`, `404 DRIVE_DISABLED` si coupé, `422` corps invalide ou `DRIVE_STORE_INVALID`). L'utilisateur vient du JWT (`#[CurrentUser] AuthenticatedUser`). Documentation `#[OA\…]` ; `make openapi`.
6. Scénario `WriteBudget` pour `SaveDriveSettings`.
7. La purge à la suppression du compte est dans DRV-207.

## Hors portée

Choisir le magasin via l'annuaire de Leclerc (inutile : lu par l'agent, DRV-307). Produits et choix (DRV-204).

## Critères d'acceptation

- Un utilisateur sans réglage reçoit `{enabled, retailer:'leclerc', store:null}` ; après `PUT`, il reçoit son magasin.
- `PUT` avec une URL d'un autre hôte, un identifiant différent de celui de l'URL, un `label` de plus de 255 caractères ou un corps incomplet : `422`.
- Changer de magasin remplace le précédent (un seul réglage par enseigne et utilisateur).
- Interrupteur coupé : `GET` renvoie `enabled:false`, `PUT` répond `404 DRIVE_DISABLED`.
- Un utilisateur ne lit ni ne modifie les réglages d'un autre (l'identifiant vient du jeton).

## Tests exigés

Domaine (`Retailer`, `DriveSettings`) ; handler en unitaire avec `DriveSettingsInMemoryRepository` ; dépôt et loader sur base réelle (`tests/Drive/OutputAdapter/`) ; contrôleurs avec les doubles de bus (clés JSON, 401, 404, 422) ; `QueryConventionTest` (handler de lecture) ; budgets.

## Definition of Done

Celle du skill `repas-dev` § 4 ; `UserOwnedDataCoverageTest` sera étendu en DRV-207 (ce ticket peut le faire échouer : le coordonner avec DRV-207 dans la même PR ou ajouter provisoirement l'entrée).

## Pièges connus

- `UserOwnedDataCoverageTest` échoue dès qu'une entité gagne un champ `userId` non listé : prévoir l'entrée dans la liste `ERASED_WITH_THE_USER` et la purge (DRV-207).
- Ne pas importer `User\Domain\Model\User` : `#[CurrentUser] AuthenticatedUser` (`Shared`).
