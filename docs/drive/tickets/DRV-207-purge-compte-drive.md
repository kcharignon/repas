# DRV-207 — Purge des données `Drive` à la suppression d'un compte

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : S
- **Dépend de** : DRV-203, DRV-204
- **Statut** : fait (PR [#142](https://github.com/kcharignon/repas/pull/142), 2026-10-18)
- **Lire d'abord** : [ADR 0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) (décisions 6, 8, 9, 10) ; `src/Repas/Infrastructure/EventListener/PurgeUserDataOnUserDeletedListener.php` ; `tests/Shared/Infrastructure/UserOwnedDataCoverageTest.php`.

> **Mise à jour du 2026-10-18 (DRV-204, PR #138)** : la purge des choix est déjà faite : `ChoiceRepository::deleteProductsByUser` puis `deleteByUser` appelés par `PurgeUserDriveDataHandler`, entrée `Choice::class => ['userId']` de `UserOwnedDataCoverageTest`, test de bout en bout dans `AccountAdministrationFlowTest` (aucune ligne orpheline dans `drive_choice_product`), budget `supprimer-compte` 18. **Il ne reste ici que le point 5 (la phrase de l'ADR 0021)** et la relecture des critères d'acceptation.

## Contexte

Aucune clé étrangère ne lie les tables à `user` : supprimer un compte « comme s'il n'avait jamais existé » repose sur une liste écrite à la main, gardée par `UserOwnedDataCoverageTest`. Les tables `drive_settings` et `drive_choice` portent `user_id`. `drive_product` n'a pas de propriétaire : on la garde.

> **Mise à jour du 2026-10-18** : DRV-203 (PR #134) a déjà créé `PurgeUserDriveDataCommand` / `PurgeUserDriveDataHandler`, l’écouteur `Drive\Infrastructure\EventListener\PurgeUserDataOnUserDeletedListener`, l’entrée `DriveSettings::class` de `UserOwnedDataCoverageTest` et le budget `supprimer-compte` à 16 pour les réglages. Reste ici : étendre le handler aux choix (points 1, 3 et 4 pour `Choice` et `drive_choice_product`) et le point 5.

## Portée

1. `Application/Command/PurgeUserDriveData/` : `PurgeUserDriveDataCommand(string $userId)` et son handler : supprime d'abord les lignes `drive_choice_product` des préférences de l'utilisateur (`ChoiceRepository::deleteProductsByUser`, par les identifiants de ses `drive_choice`, sinon des lignes orphelines restent : aucune clé étrangère), puis `drive_choice` (`ChoiceRepository::deleteByUser`) et `drive_settings` (`DriveSettingsRepository::deleteByUser`) : suppressions en masse, sans événement.
2. `Infrastructure/EventListener/PurgeUserDataOnUserDeletedListener` (dans `Drive`, `#[AsEventListener]`, répond à `Shared\Domain\Event\UserDeletedEvent` par la commande, comme le listener de `Repas`) : synchrone, donc dans la transaction de `DeleteUser`.
3. `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` : ajouter `DriveSettings::class => ['userId']` et `Choice::class => ['userId']` (classes d'entités `Repas\Drive\Infrastructure\Entity\…`).
4. Budget du scénario `supprimer-compte` (15 requêtes aujourd'hui) : mettre à jour `tests/Repas/WriteBudget/budgets.php` (`make sql-budget-update`) après avoir vérifié l'ajout (3 suppressions).
5. Mettre à jour la documentation de l'ADR 0021 (une phrase : la purge couvre aussi le contexte `Drive`).

## Hors portée

Suppression des `Product` (faits publics).

## Critères d'acceptation

- Étant donné un utilisateur avec des réglages et des préférences (produits acceptés et refusés), quand son compte est supprimé, alors ses réglages, ses préférences et **toutes leurs lignes de produits** n'existent plus (aucune ligne orpheline dans `drive_choice_product`) et les produits restent.
- Si la purge échoue, le compte n'est pas supprimé (atomicité du `command.bus`).
- `UserOwnedDataCoverageTest` passe ; il échoue si on retire l'une des deux entrées.

## Tests exigés

Handler en unitaire (doubles en mémoire) ; test de bout en bout de `DeleteUser` avec données `Drive` ; test de base qui vérifie qu'aucune ligne de `drive_choice_product` ne reste après la purge ; `UserOwnedDataCoverageTest` ; budget.

## Definition of Done

Celle du skill `repas-dev` § 4.

## Réalisation (2026-10-18, PR repas#142)

Tout le code était déjà livré par DRV-203 (réglages) et DRV-204 (préférences et leurs lignes de produits) ; cette PR ne contient que de la documentation. Relecture des critères d'acceptation contre le code de `main` :

- Commande `PurgeUserDriveDataCommand` et handler (`drive_settings`, puis les lignes `drive_choice_product` par les préférences de l'utilisateur, puis `drive_choice`), écouteur `Drive\Infrastructure\EventListener\PurgeUserDataOnUserDeletedListener` (synchrone, dans la transaction de `DeleteUser`) : en place. Tests : `PurgeUserDriveDataHandlerTest` (doubles en mémoire), `PurgeUserDataOnUserDeletedListenerTest`, `ChoiceRepositoryTest` et `DriveSettingsRepositoryTest` (suppressions sur base réelle, lignes de produits comprises).
- `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` : `DriveSettings::class => ['userId']` et `Choice::class => ['userId']`. Le test échoue si l'une des deux entrées est retirée (il compare les colonnes `user` des entités à cette liste).
- Parcours de bout en bout : `AccountAdministrationFlowTest::testADeletedAccountTakesItsDriveSettingsAndChoicesWithItAndLeavesTheOthers` (réglages et préférences effacés, produits conservés, aucune ligne orpheline dans `drive_choice_product`, données d'un autre compte intactes). L'atomicité est celle de `command.bus` (ADR 0021 décision 8), déjà prouvée par le test « suppression annulée si la purge échoue ».
- Budget `supprimer-compte` : 18 requêtes (15 + 1 pour les réglages + 2 pour les préférences).
- **Fait ici** : la phrase de l'ADR 0021 (décision 6 : la purge couvre aussi le contexte `Drive`, produits conservés) ; décision 9 : `supprimer-compte` passe de 15 à 18 requêtes ; `CLAUDE.md` (paragraphe « Comptes ») et la Definition of Done du skill `repas-dev` citent `PurgeUserDriveData`.
