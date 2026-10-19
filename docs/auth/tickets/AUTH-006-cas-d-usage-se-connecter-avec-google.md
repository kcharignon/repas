# AUTH-006 — Cas d'usage « Se connecter avec Google » : retrouver, lier, créer ou refuser un compte

- **Dépôt** : back
- **Jalon** : M1 Back `User`
- **Taille** : L
- **Dépend de** : AUTH-003, AUTH-004, AUTH-005 ; AUTH-001 (D1, D2)
- **Faisable sans téléphone** : oui (faux vérificateur et faux nonce)
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 3.4, 4 (toute la politique) ; [ADR 0024](../../adr/0024-connexion-avec-google.md) décisions 9 à 11 ; ADR 0015 §§ 1, 12 ; ADR 0020 §§ 1, 10 ; ADR 0021 §§ 3, 4 ; ADR 0013 règle 7 (charger un agrégat une seule fois) ; skill `repas-dev` § 3.3 ; `RotateRefreshTokenHandler` (modèle d'une commande qui renvoie l'identifiant de connexion).

## Contexte

C'est le cœur : à partir d'un jeton vérifié, décider quel compte Repas se connecte. Toute la politique de compte du cadrage vit dans le **domaine** (testable sans base) et le handler l'orchestre dans la transaction du `command.bus`. La route HTTP, le JWT et le cookie viennent au ticket suivant.

## Portée

1. **`User`** (`src/User/Domain/Model/User.php`) : constante `NO_PASSWORD = '!'` (jamais un hachage valide : `password_verify` renvoie faux) ; `hasPassword(): bool` ; `lockPassword(): void` (`changePassword(NO_PASSWORD)` : la version des identifiants monte, tous les jetons déjà émis sont révoqués) ; fabrique `registerFromIdentity(id, email, createdAt)` : `ACTIVE`, `ROLE_USER`, nombre de personnes par défaut (4, comme l'écran d'inscription), mot de passe verrouillé, statistiques initialisées.
2. **`Domain/Service/ExternalIdentityResolver`** : `resolve(VerifiedIdentity, DateTimeImmutable): User`. Règles (tableau § 4.2 de l'analyse, à jour des décisions D1 et D2) :
   - lien existant pour `(provider, sub)` : renvoie son compte s'il est `ACTIVE`, sinon `IdentityException::invalidCredential()` (désactivé ou en attente) ;
   - pas de lien, `emailVerified` faux : `invalidCredential()` (déjà refusé en amont par le vérificateur ; garde de défense) ;
   - pas de lien, aucun compte à cette adresse : crée le compte (`registerFromIdentity`) et le lien ;
   - pas de lien, compte existant, `emailIsAuthoritative()` faux : `IdentityException::accountExistsWithPassword()` (`409`, `ACCOUNT_EXISTS_WITH_PASSWORD`) ;
   - pas de lien, compte existant `DISABLED` : `invalidCredential()`, **aucun lien posé** ;
   - pas de lien, compte existant `ACTIVE`, adresse autoritaire : pose le lien, renvoie le compte ;
   - pas de lien, compte existant `PENDING`, adresse autoritaire : pose le lien, `verifyEmail()`, **`lockPassword()`**, supprime le lien de confirmation en attente (`EmailVerificationTokenRepository::deleteAllOfUser`).
3. **Domaine** : `Service/RefreshTokenIssuer` extrait de `IssueRefreshTokenHandler` (ouvre une famille de refresh tokens et élague les plus anciennes) ; `IssueRefreshTokenHandler` l'appelle (tests existants inchangés).
4. **Application** : `SignInWithGoogle/SignInWithGoogleCommand(idToken, refreshTokenHash)` et `SignInWithGoogleHandler` (`#[AsMessageHandler]`) :
   1. `IdentityTokenVerifier::verify($idToken)` ;
   2. `LoginNonceStore::consume($identity->nonce)` — faux ou absent : `invalidCredential()` ;
   3. `ExternalIdentityResolver::resolve(...)` avec l'heure de l'horloge injectée et un identifiant de `UuidGenerator` ;
   4. `RefreshTokenIssuer` avec le hachage fourni ;
   5. **renvoie l'adresse de connexion** du compte (exception de l'ADR 0015 §12 : la création et la liaison doivent être atomiques avec l'ouverture de la session).
5. **Scénarios `WriteBudget`** : `connexion-google` (lien existant), `creation-compte-google`, `liaison-compte-google` — mesurer et figer (`make sql-budget-update`), ne rien deviner.

## Hors portée

La route, le JWT et le cookie (AUTH-007) ; l'écran ; la liaison par saisie du mot de passe (variante à D2) ; la fusion de deux comptes ; un lien Google ajouté depuis « Moi ».

## Critères d'acceptation (un par cas)

- Étant donné un jeton valide dont le `sub` est lié à un compte `ACTIVE`, quand la commande est jouée, alors elle renvoie l'adresse de ce compte (même si l'adresse du jeton diffère) et une famille de refresh tokens existe ; rien d'autre n'est écrit.
- Étant donné un `sub` lié à un compte `DISABLED` ou `PENDING`, alors `invalidCredential()`, aucun jeton de rafraîchissement.
- Étant donné un `sub` inconnu et aucune adresse connue, alors un compte `ACTIVE` `ROLE_USER` avec mot de passe verrouillé, un lien et une famille de jetons sont créés ; `hasPassword()` est faux ; `passwordMatch('!')` et `passwordMatch('')` sont faux.
- Étant donné un `sub` inconnu et une adresse `@gmail.com` d'un compte `ACTIVE`, alors le lien est posé, le mot de passe du compte **reste utilisable**.
- Étant donné la même situation avec `@orange.fr` ou `@societe.fr` (même `hd`), alors `409 ACCOUNT_EXISTS_WITH_PASSWORD`, rien d'écrit (ni lien, ni compte, ni jeton).
- Étant donné un compte `PENDING` à une adresse Gmail, alors il devient `ACTIVE`, son lien de confirmation disparaît, son ancien mot de passe ne fonctionne plus, et sa version des identifiants a augmenté.
- Étant donné un compte `DISABLED` à une adresse Gmail, alors `invalidCredential()` et **aucun lien** n'est posé.
- Étant donné un nonce inconnu, expiré ou déjà consommé, alors `invalidCredential()` ; le même jeton rejoué après une connexion réussie est refusé.
- Étant donné le compte protégé (`User::PROTECTED_EMAIL`), alors il est traité comme un compte `ACTIVE` ordinaire et reste intouchable par l'API d'administration.
- Étant donné un échec en cours de route (par exemple la famille de jetons), alors rien n'est écrit (atomicité du bus).

## Tests exigés

Domaine : `User` (verrouillage, `hasPassword`, fabrique), `ExternalIdentityResolver` (tous les cas ci-dessus, en unitaire, sans base) ; `RefreshTokenIssuer` ; handler avec `UserInMemoryRepository`, `UserIdentityInMemoryRepository`, `RefreshTokenInMemoryRepository`, `EmailVerificationTokenInMemoryRepository`, faux `IdentityTokenVerifier`, faux `LoginNonceStore`, `SpyEventDispatcher` si utile ; scénarios `WriteBudget`. Les anciens tests de `IssueRefreshTokenHandler` doivent passer sans modification.

## Definition of Done

Celle de `repas-dev` § 4 ; budgets figés ; PHPStan et Deptrac verts ; ADR 0024 cité dans « À relire » ; `CLAUDE.md` (section CQRS / « Comptes » : une phrase sur la connexion Google et le compte sans mot de passe).

## Pièges connus

- **Charger chaque agrégat une seule fois** (ADR 0013 règle 7) : le résolveur reçoit et renvoie le même `User`, le handler n'enregistre qu'une fois. Un modèle est une copie : ne pas recharger entre `verifyEmail()`, `lockPassword()` et `save()`.
- Un compte `PENDING` créé par un tiers avec l'adresse de la victime : sans `lockPassword()`, l'attaquant garde l'accès après que la victime a « confirmé » en se connectant avec Google (ADR 0020 §10).
- `lockPassword()` passe par `changePassword()` pour faire monter `credentialsVersion` ; `setPassword()` ne révoque rien.
- L'adresse de Google est mise en minuscules par `User::normalizeEmail` ; comparer des adresses **normalisées**. Le domaine de l'adresse se lit après la **dernière** `@` (`gmail.com@evil.com` n'est pas Gmail).
- Le pare-feu `auth` n'applique pas `UserChecker` : seul ce handler empêche un compte désactivé ou en attente de se connecter.
- Un refus ne doit pas être distinguable : mêmes `401` et même message pour jeton invalide, nonce rejoué, compte désactivé. Seul le `409` du compte existant non Gmail est explicite.
- Deux connexions simultanées pour le même `sub` : la seconde échoue sur l'unicité (`alreadyLinked()`, `409`) ; ne pas tenter de continuer dans la transaction.
- Le nonce est consommé **après** la vérification de signature : un faux jeton qui cite un vrai nonce ne le brûle pas. En revanche un nonce consommé par une connexion ensuite refusée reste consommé (le jeton est « dépensé »).
- Ne jamais donner le rôle d'administrateur depuis Google, ni lire `hd` pour autre chose qu'un éventuel élargissement de D2.
