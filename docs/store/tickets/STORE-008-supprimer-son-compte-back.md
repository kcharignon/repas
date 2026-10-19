# STORE-008 — Supprimer son propre compte : `POST /api/v1/users/me/deletion`

- **Dépôt** : back (`src/User/`, `docs/adr/0021-…`, `CLAUDE.md`, `docs/api/openapi.json`, `tests/`, budgets SQL)
- **Jalon** : M2 Conformité aux règles de Google Play
- **Taille** : M
- **Dépend de** : STORE-001 (D1)
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non (mais **STORE-017 et STORE-018 en dépendent** : Google refuse une application avec compte sans suppression)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 6.d ; [ADR 0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) (tout, surtout §1, §3, §6, §8, §10) ; [ADR 0015](../../adr/0015-refresh-token-du-spa.md) §7 ; skills `repas-dev` et `repas-drive-back` (purge) ; `src/User/Application/DeleteUser/DeleteUserHandler.php`.

## Contexte

Google exige, pour une application qui permet de créer un compte, un chemin de suppression du compte et des données **dans l'application** et sur le web ; geler ne suffit pas. Aujourd'hui seul un administrateur supprime (`DELETE /api/v1/users/{id}`) et ne peut pas supprimer son propre compte. Un utilisateur doit pouvoir supprimer **son** compte, avec la même purge atomique ([0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) §6, §8, §10).

## Portée

1. **Règle métier** (ADR 0021 §1 amendé) : tout utilisateur peut supprimer son propre compte, **sauf** le compte `User::PROTECTED_EMAIL` (`422 PROTECTED_ACCOUNT`) et le **dernier administrateur actif** (`422 LAST_ADMINISTRATOR`). La suppression d'un autre compte reste réservée à l'administrateur et `DELETE /api/v1/users/{id}` refuse toujours le sien (`CANNOT_DELETE_OWN_ACCOUNT`).
2. **Cas d'usage** `User/Application/DeleteOwnAccount/` : `DeleteOwnAccountCommand` (`userId` du jeton, `?string $password`, `?string $confirmationEmail`, `final readonly`) et son handler. Le handler : charge l'utilisateur ; contrôle la confirmation (mot de passe valide si le compte a un mot de passe ; sinon l'adresse saisie est égale à celle du compte, sans tenir compte de la casse) → sinon `422 INVALID_CONFIRMATION`, rien d'écrit ; applique les deux refus de la règle ; supprime comme `DeleteUserHandler`. **Extraire** la suppression commune (jetons de rafraîchissement, liens de confirmation, compte, `UserDeletedEvent`) dans un service de domaine partagé par les deux handlers ; les liens d'identité externe (AUTH-005) sont supprimés par le même service.
3. **Port** : `UserRepository::isLastActiveAdministrator(string $userId): bool` (prédicat booléen, ADR 0013).
4. **Route** `POST /api/v1/users/me/deletion` (corps `{"password": "…"}` ou `{"confirmationEmail": "…"}`), `#[CurrentUser] AuthenticatedUser`, `204` ; codes 422 ci-dessus ; **plafond par utilisateur** (la route teste un mot de passe : 5 essais par 15 minutes, compteur dans le cache, `429` + `Retry-After`) via un équivalent de `RateLimitedPerUser` **dans le contexte `User`** (celui de `Drive` ne se réutilise pas : Deptrac).
5. OpenAPI (`#[OA\…]`), `make openapi`, relire le diff.
6. **Documents** (même commit) : ADR 0021 §1 (note datée « amendé par l'ADR 0025 »), paragraphe « Comptes » de `CLAUDE.md`, `docs/drive/note-vie-privee.md` § « Comment tout effacer » (la suppression n'est plus « demande à la personne qui administre » seulement ; le texte du front est mis à jour par STORE-009), roadmap.

## Hors portée

Le front (STORE-009) ; l'effacement des cookies (le front appelle `POST /api/auth/logout`, qui est idempotent, et la purge a déjà supprimé les familles de jetons) ; un délai de grâce ou une suppression différée.

## Critères d'acceptation

- Étant donné un utilisateur actif avec mot de passe, quand il envoie son mot de passe, alors `204`, son compte, ses recettes, ses listes, ses ingrédients personnalisés, ses réglages et choix du drive, ses jetons et ses liens d'identité sont supprimés (mêmes données que la suppression par l'administrateur) ; ses copies faites par d'autres restent, devenues originales.
- Étant donné un mauvais mot de passe, alors `422 INVALID_CONFIRMATION`, rien n'est supprimé, et le compteur de débit augmente.
- Étant donné un compte sans mot de passe (créé par Google), quand il envoie son adresse exacte, alors `204` ; une autre adresse : `422`.
- Étant donné le compte protégé ou le dernier administrateur actif, alors `422` avec le code propre.
- Étant donné la sixième tentative en 15 minutes, alors `429` avec `Retry-After`.
- Étant donné la purge qui échoue, alors le compte n'est pas supprimé (transaction du bus).
- Étant donné le jeton d'accès du compte supprimé, alors la requête suivante est refusée.

## Tests exigés

Handler en mémoire (`InMemory` + `SpyEventDispatcher`) : confirmation par mot de passe, par adresse, refus protégé / dernier administrateur / mauvaise confirmation ; dépôt sur base réelle (`isLastActiveAdministrator`) ; controller avec `MockCommandBus` (clés JSON, statuts, `429`) ; parcours complet avec les vrais bus (`AccountSelfDeletionFlowTest`, calqué sur `AccountAdministrationFlowTest`) ; `UserOwnedDataCoverageTest` ; **budget SQL** `supprimer-mon-compte` mesuré et figé (`make sql-budget-update`) ; `make test`, `make quality`, `make openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4 ; ADR 0021 et `CLAUDE.md` dans le même commit ; PR : budget SQL avant / après, la liste des codes d'erreur ; aucune donnée réelle.

## Pièges connus

- **La route teste un mot de passe** : sans plafond, un jeton volé permettrait de le deviner ; le plafond est par utilisateur, pas par IP.
- `User::hasPassword()` n'existe qu'après AUTH-006 : si ce ticket passe avant, tout compte est traité comme ayant un mot de passe, et le cas « sans mot de passe » est ajouté (et testé) par le ticket qui introduit `NO_PASSWORD`.
- Le compte protégé est celui du propriétaire : il se supprime comme il le décide, mais pas par cette route ; l'écrire dans la page publique de suppression (STORE-009) serait une fuite : le message est générique.
- Dernier administrateur : compter les administrateurs **actifs** (un administrateur désactivé ne compte pas).
- Une suppression ne se défait pas ; la seule reprise est la dernière sauvegarde (ADR 0021) ; les données restent dans les sauvegardes environ deux semaines : le dire dans la page publique (STORE-009).
- `403` reste réservé à l'autorisation (ADR 0007 §13) : une mauvaise confirmation est un `422`.
