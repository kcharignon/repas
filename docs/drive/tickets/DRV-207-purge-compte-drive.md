# DRV-207 — Purge des données `Drive` à la suppression d'un compte

- **Dépôt** : back
- **Jalon** : M2 Back `Drive`
- **Taille** : S
- **Dépend de** : DRV-203, DRV-204
- **Statut** : à faire
- **Lire d'abord** : [ADR 0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) (décisions 6, 8, 9, 10) ; `src/Repas/Infrastructure/EventListener/PurgeUserDataOnUserDeletedListener.php` ; `tests/Shared/Infrastructure/UserOwnedDataCoverageTest.php`.

## Contexte

Aucune clé étrangère ne lie les tables à `user` : supprimer un compte « comme s'il n'avait jamais existé » repose sur une liste écrite à la main, gardée par `UserOwnedDataCoverageTest`. Les tables `drive_settings` et `drive_choice` portent `user_id`. `drive_product` n'a pas de propriétaire : on la garde.

## Portée

1. `Application/Command/PurgeUserDriveData/` : `PurgeUserDriveDataCommand(string $userId)` et son handler (`DriveSettingsRepository::deleteByUser`, `ChoiceRepository::deleteByUser` : suppressions en masse, sans événement).
2. `Infrastructure/EventListener/PurgeUserDataOnUserDeletedListener` (dans `Drive`, `#[AsEventListener]`, répond à `Shared\Domain\Event\UserDeletedEvent` par la commande, comme le listener de `Repas`) : synchrone, donc dans la transaction de `DeleteUser`.
3. `UserOwnedDataCoverageTest::ERASED_WITH_THE_USER` : ajouter `DriveSettings::class => ['userId']` et `Choice::class => ['userId']` (classes d'entités `Repas\Drive\Infrastructure\Entity\…`).
4. Budget du scénario `supprimer-compte` (15 requêtes aujourd'hui) : mettre à jour `tests/Repas/WriteBudget/budgets.php` (`make sql-budget-update`) après avoir vérifié l'ajout (2 suppressions).
5. Mettre à jour la documentation de l'ADR 0021 (une phrase : la purge couvre aussi le contexte `Drive`).

## Hors portée

Suppression des `Product` (faits publics).

## Critères d'acceptation

- Étant donné un utilisateur avec des réglages et des choix, quand son compte est supprimé, alors ses réglages et ses choix n'existent plus et les produits restent.
- Si la purge échoue, le compte n'est pas supprimé (atomicité du `command.bus`).
- `UserOwnedDataCoverageTest` passe ; il échoue si on retire l'une des deux entrées.

## Tests exigés

Handler en unitaire (doubles en mémoire) ; test de bout en bout de `DeleteUser` avec données `Drive` ; `UserOwnedDataCoverageTest` ; budget.

## Definition of Done

Celle du skill `repas-dev` § 4.
