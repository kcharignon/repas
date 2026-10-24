# AUTH-005 — Identité externe : modèle `UserIdentity`, table `user_identity`, purge du compte

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : M
- **Dépend de** : —
- **Faisable sans téléphone** : oui
- **Statut** : fait (PR [#222](https://github.com/kcharignon/repas/pull/222), 2026-10-20)
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

## Réalisation (PR #222, 2026-10-20)

**Fait comme demandé** :

- Domaine : `Model/UserIdentity` (`create()` / `load()`), port `Interface/UserIdentityRepository` (les quatre méthodes demandées ; `findOneByUserAndProvider(string $userId, IdentityProvider $provider)`), `IdentityException::alreadyLinked()` (`409`, message `ALREADY_LINKED`, constante `IdentityException::ALREADY_LINKED`, comme l'attend AUTH-007).
- Infrastructure : entité `Entity/UserIdentity` (table `user_identity`, colonne `provider` en `enumType: IdentityProvider`), `Repository/UserIdentityPostgreSQLRepository`, alias dans `config/repositories.yaml`.
- Migration `Version20261020090000`, additive : la table et ses clés uniques `uniq_user_identity_provider_subject (provider, subject)` et `uniq_user_identity_user_provider (user_id, provider)` ; elle sert aussi aux recherches et à la suppression par compte, donc pas d'autre index. Aucune clé étrangère, `user` intact. Le docblock dit que le code précédent ignore la table et ce que fait `down`.
- Purge : `DeleteUserHandler` appelle `deleteAllOfUser()` après les liens de mot de passe oublié et avant le compte ; `UserIdentity::class => ['userId']` dans `UserOwnedDataCoverageTest` ; scénario `supprimer-compte` avec un lien Google.
- Doubles : `tests/Helper/InMemoryRepository/UserIdentityInMemoryRepository` (tient les deux clés uniques et lève `alreadyLinked()`, comme la table), `tests/Helper/Builder/UserIdentityBuilder` (sujets factices `sujet-test-…`, `withUser()` / `withUserId()` / `withSubject()`).
- Tests : `tests/User/Domain/UserIdentityTest`, `DeleteUserHandlerTest` (lien supprimé, celui de l'autre compte intact, intact aussi quand la suppression est refusée), `tests/User/OutputAdapter/UserIdentityRepositoryTest`. Ce dernier couvre la recherche exacte, les deux contraintes (un seul lien reste après le refus), l'insertion en une requête, la réécriture d'un lien lu, `deleteAllOfUser` (une requête, identity map comprise) et la reliaison d'un sujet après suppression. `AccountAdministrationFlowTest` vérifie aussi deux choses : aucune ligne ne reste après la suppression d'un compte lié ; si la suppression échoue en cours de route, le lien reste, car tout est dans la même transaction.
- Documentation : `CLAUDE.md` (« Comptes ») et ADR 0021 (décisions 6 et 9, liste des tests de dépôt).

**Écarts et choix** :

- **Budget** : 20, pas 19. Le ticket partait de 18, mais `supprimer-compte` valait déjà 19 depuis le mot de passe oublié (PR #206, `DELETE password_reset_token`). Ce ticket n'ajoute qu'une requête (`DELETE user_identity`) ; aucun autre budget ne bouge. Les nombres « 18 » de l'ADR 0024 (décision 12) et de `connexion-google.md` § 3.7 décrivent l'état au cadrage : ils restent tels quels.
- **`save()`** ne cherche pas le lien en base avant d'écrire. Il met à jour un lien déjà présent dans l'identity map (lu dans la même requête) ; sinon, il l'insère directement (une seule requête, aucun `SELECT`). Un lien ne change jamais. Toute violation d'unicité (sujet déjà lié, compte déjà lié chez ce fournisseur, identifiant en double) devient `alreadyLinked()`. Doctrine ferme alors l'EntityManager : l'exception doit remonter jusqu'au bus, qui annule la transaction.
- **Sujet** : le modèle refuse lui-même un sujet vide, fait d'espaces ou de plus de 255 **octets** (`strlen`, comme le vérificateur d'AUTH-003 ; OpenID Connect borne `sub` à 255 caractères ASCII). Il répond `invalidCredential()` (`401` unique) : un tel sujet ne vient pas d'un jeton conforme. Le sujet est gardé tel quel, sans retouche (ni `trim`, ni casse).

**Vérifié en plus** :

- Base vide migrée de bout en bout, puis `doctrine:schema:validate` vert ; `down` supprime la table, `up` la remet.
- Code précédent (`origin/main`) sur la base migrée : le `doctrine:migrations:migrate --no-interaction --all-or-nothing` du démarrage (`docker-entrypoint.sh`) répond `0` (« Already at the latest version », avec un avertissement pour la migration qu'il ne connaît pas). Sa suite entière passe avec la table présente (2574 tests).
- `UserOwnedDataCoverageTest` échoue si l'on retire l'entrée `UserIdentity`. Sans l'appel `deleteAllOfUser` dans le handler, trois tests échouent : le handler, le parcours et le budget.

**Pour la suite (AUTH-006)** : le cas d'usage crée le lien par `UserIdentity::create(uuid, userId, IdentityProvider::GOOGLE, $identity->subject, $now)` puis `save()`. Il ne rattrape pas `alreadyLinked()` et laisse le `409` remonter. Le scénario `creation-compte-google` comptera une seule requête pour le lien (`INSERT user_identity`).
