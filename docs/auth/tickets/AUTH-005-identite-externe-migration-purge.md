# AUTH-005 — Identité externe : modèle `UserIdentity`, table `user_identity`, purge du compte

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 3.4, 3.6, 3.7 ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décisions 8 et 12 ; ADR 0017 règle 6 (migration compatible), ADR 0021 décisions 6 et 10 ; skills `repas-dev` et `repas-drive-back` § « Migrations additives » et « Purge du compte » ; `docs/drive/tickets/DRV-207-purge-compte-drive.md` (même garde-fou).

## Contexte

La clé d'une identité Google est le `sub`, jamais l'e-mail : il faut une table qui lie `(provider, subject)` à un utilisateur. Aucune clé étrangère n'existe dans le projet : la purge du compte et `UserOwnedDataCoverageTest` sont les seuls garde-fous. La version précédente du code doit ignorer sans dommage la nouvelle table.

## Portée

1. **Domaine** (`src/User/Domain/`) : `Model/UserIdentity` (`id`, `userId`, `provider` (`IdentityProvider`), `subject`, `createdAt`) avec `create()` / `load()` ; port `Interface/UserIdentityRepository` : `findOneByProviderAndSubject(IdentityProvider, string): ?UserIdentity`, `findOneByUserAndProvider(...)`, `save(UserIdentity): void`, `deleteAllOfUser(string $userId): void` ; `IdentityException::alreadyLinked()` (`409`, rattrape la violation d'unicité de deux connexions simultanées).
2. **Infrastructure** : entité Doctrine `Entity/UserIdentity` (table `user_identity`), `Repository/UserIdentityPostgreSQLRepository` (hérite de `PostgreSQLRepository`), alias dans `config/repositories.yaml`.
3. **Migration** `migrations/Version2026…php`, **additive** :
   `user_identity(id varchar(36) pk, user_id varchar(36) not null, provider varchar(32) not null, subject varchar(255) not null, created_at timestamp(0) not null, unique(provider, subject), unique(user_id, provider))`. Aucune clé étrangère, aucune colonne ajoutée à `user`. Documenter dans la migration que `down` supprime la table et que le code précédent l'ignore.
4. **Purge** : `DeleteUserHandler` appelle `UserIdentityRepository::deleteAllOfUser()` avant de supprimer le compte (même endroit que les refresh tokens et les liens de confirmation) ; `tests/Shared/Infrastructure/UserOwnedDataCoverageTest.php` : ajouter `UserIdentity::class => ['userId']` ; scénario `supprimer-compte` : mesurer et figer le nouveau budget (**18 aujourd'hui, 19 attendu** ; `make sql-budget-update`).
5. **Doubles de test** : `UserIdentityInMemoryRepository` (`tests/Helper/InMemoryRepository/`), `UserIdentityBuilder` (`tests/Helper/Builder/`).

## Hors portée

La politique qui crée ou retrouve une identité (AUTH-006). Plusieurs fournisseurs ou plusieurs liens Google par compte. Une colonne `last_login_at` (aucun besoin).

## Critères d'acceptation

- Étant donné deux liens `(GOOGLE, "s1")`, quand le second est enregistré, alors il est refusé (`409`) ; un même compte ne peut pas porter deux liens Google.
- Étant donné un compte avec un lien, quand le compte est supprimé (`DeleteUser`), alors le lien n'existe plus ; le lien d'un autre compte est intact.
- Étant donné la base du code précédent plus cette migration, alors `make schema-validate` est vert et le code précédent démarre (la table est ignorée).
- `UserOwnedDataCoverageTest` passe ; il échoue si l'entrée `UserIdentity` est retirée.
- Le budget `supprimer-compte` passe de 18 à 19 (ou à la valeur mesurée) ; aucun autre budget ne bouge.

## Tests exigés

Domaine (`create`, `load`) ; handler `DeleteUser` en unitaire avec le double en mémoire (le lien est supprimé) ; dépôt sur base réelle (`tests/User/OutputAdapter/`) : recherche par `(provider, subject)`, unicité des deux contraintes, `deleteAllOfUser` ; `AccountAdministrationFlowTest` : un compte avec lien Google supprimé ne laisse aucune ligne ; `UserOwnedDataCoverageTest` ; budgets.

## Definition of Done

Celle de `repas-dev` § 4 : `make test`, `make quality`, `make schema-validate`, scénario et budget mis à jour, `CLAUDE.md` (paragraphe « Comptes » : la purge efface aussi `user_identity`) et ADR 0021 (une phrase) mis à jour dans la même PR.

## Pièges connus

- Un champ nommé `userId` déclenche `UserOwnedDataCoverageTest` : c'est voulu ; ne pas contourner la regex en renommant la colonne.
- Pas de clé étrangère (convention du projet, ADR 0021) : l'ordre de la suppression compte seulement pour la lisibilité, mais ne jamais supprimer le compte et oublier le lien.
- `subject` fait 255 caractères au plus ; un `sub` de Google est une suite de chiffres (21 en pratique) : valider la longueur, ne pas supposer le format.
- La migration doit rester compatible avec le code précédent (retour arrière automatique de `deploy.sh`) : nouvelle table seulement.
- Ne pas stocker l'adresse e-mail de Google dans cette table : elle changerait en silence et n'a aucun rôle après la première liaison.
- La violation d'unicité de deux connexions simultanées survient **dans** la transaction du bus : la rattraper dans le dépôt en `IdentityException::alreadyLinked()` (comme `emailAlreadyUsed` à l'inscription, ADR 0008 §14), sans poursuivre dans la même transaction.
