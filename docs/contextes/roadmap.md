# Roadmap : contextes étanches (Open Host Service et couche anticorruption)

Suivi vivant. Cocher une case quand la PR est fusionnée (et noter son numéro). Décision : [ADR 0035](../adr/0035-contextes-etanches-open-host-service.md) (acceptée le 2026-10-31, demande du propriétaire). **Point d'entrée pour tout agent** : lire l'ADR, ce fichier, le pilote (§ « Le modèle à suivre »), puis les skills `repas-dev` (§ 3.4) et, pour le drive, `repas-drive-back`.

Règle d'exécution : **une PR = un lien entre deux contextes**, dans le répertoire principal du back, **un agent à la fois** (la base de test est commune). Chaque PR laisse `make test`, `make quality` verts et **retire** de `SharedHoldsNoContextBusinessTest::LEGACY` (et de `ContextsDoNotShareTablesTest::LEGACY`) ce qu'elle a migré. Un agent ne touche ni `CLAUDE.md`, ni l'ADR : il rend compte, le fil principal met à jour la roadmap. Aucun comportement visible ne change (API, réponses d'Alexa, budgets SQL : un budget qui monte est un défaut, `make sql-budget-update` n'est jamais une réponse).

## L'état des lieux (audit du 2026-10-31)

Deptrac ne voit aucune classe d'un contexte importée par un autre, mais l'étanchéité tient par `Shared` : une vingtaine de ports métier et 19 DTO y vivent, implémentés par le producteur. Dépendances réelles entre contextes (consommateur → producteur) :

| Lien | Ports de `Shared` | Consommateurs (écriture / lecture) |
|---|---|---|
| `Voice` → `User` | `ActiveUserChecker` | 2 handlers d'écriture, 1 de lecture · **fait (pilote)** |
| `Repas` → `User` | `IngredientCatalogOwner`, `UserDefaultServing` | `CreateIngredient`, `UpdateIngredient`, `SeedUserIngredients`, `PlanSpokenMeal` (écriture) ; `IngredientPostgreSQLLoader`, `IngredientSeedingReportPostgreSQLLoader` (lecture) |
| `Repas` → `User` (SQL) | — | 5 fichiers lisent la table `"user"` : `ConversionHealthPostgreSQLLoader`, `ConversionPostgreSQLLoader`, `IngredientSeedingReportPostgreSQLLoader`, `AdoptionSnapshotReader`, `IngredientAdoptionBulkPostgreSQLRepository` (`ContextsDoNotShareTablesTest::LEGACY`) |
| `Repas` → `Drive` | `DriveIngredientReferences` (+ `DriveChoiceFingerprint`, `DriveMergeImpact`) | `MergeIngredients`, `SeedUserIngredients`, dépôts de fusion et d'amorçage (écriture) ; aperçu de fusion, rapport d'amorçage (lecture) |
| `Drive` → `Repas` | `IngredientAliasResolver`, `IngredientDepartmentResolver`, `IngredientNameLoader`, `ShoppingListForDriveLoader` (+ `ShoppingListForDrive`, `ShoppingListLineForDrive`), `UnitSlugResolver` | `SaveProductChoices`, `ForgetChoice`, `ReplaceIngredientPreference` (écriture) ; `ChoicePostgreSQLLoader`, `DrivePlanPostgreSQLLoader`, `DriveEstimatePostgreSQLLoader` (lecture) |
| `User` → `Repas`, `Voice` | `OwnershipLoader`, `VoiceLinkOwnershipLoader` | `OwnerVoter` (contexte `User`) décide des droits sur des objets de `Repas` et de `Voice` |
| `Voice` → `Repas` | `SpokenIngredientResolver`, `SpokenIngredientCreator`, `SpokenListLookup`, `SpokenRecipeResolver`, `VoiceMealPlanner`, `VoiceShoppingList` (+ 14 DTO) | les handlers de `Voice/Application/Command/*Spoken*` et la query `GetSpokenItemInList` ; implémentés dans `Repas/Infrastructure/Voice/` (6 adapters, ~530 lignes) |
| (transverse) | `DriveFeature`, `VoiceFeature`, `IngredientSeedingFeature`, `Dimension`, 3 exceptions, 4 fichiers HTTP | interrupteurs d'un seul contexte logés dans `Shared` |
| (composition) | — | `src/Command/UpdateUserStatisticsCommand` compose `Repas` et `User` ; les autres commandes console sont à un seul contexte mais hors de lui |

## Le modèle à suivre : le pilote `Voice` → `User` (C1)

Un lien complet, des deux sortes, et ses tests :

| Pièce | Fichier |
|---|---|
| OHS d'écriture (rend le Model `User`) | `src/User/Application/OpenHostService/UserAccountCommandOHS.php` |
| OHS de lecture (rend un DTO) | `src/User/Application/OpenHostService/UserAccountQueryOHS.php`, `UserAccountDTO.php` (appuyé sur `UserLoader::isActive`) |
| Ports du consommateur (sa langue) | `src/Voice/Application/Port/ActiveUserCommandPort.php`, `ActiveUserQueryPort.php` |
| Adapters (seuls à importer `User`) | `src/Voice/Infrastructure/Acl/ActiveUserCommandAdapter.php`, `ActiveUserQueryAdapter.php` |
| Handlers | `ExchangeAuthorizationCodeHandler`, `RefreshAccessGrantHandler` (→ `CommandPort`), `ResolveAccessGrantQueryHandler` (→ `QueryPort`) |
| Test par transitivité | `tests/Voice/Application/{ExchangeAuthorizationCode,RefreshAccessGrant}HandlerTest` (vrai adapter + vrai OHS + `UserInMemoryRepository`), `ResolveAccessGrantQueryHandlerTest` (… + `UserInMemoryLoader`) |
| Test de la transposition | `tests/Voice/Acl/ActiveUserAdaptersTest.php` |
| Test de l'accès réel | `tests/User/OutputAdapter/UserLoaderIsActiveTest.php` |
| Retraits | `Shared\…\ActiveUserChecker`, `ActiveUserCheckerPostgreSQL`, `tests/Helper/ActiveUserCheckerInMemory`, entrée de `LEGACY` |

### Migrer un port, pas à pas

1. **Lister les appelants** du port de `Shared` et classer chacun : handler d'écriture, ou lecture (QueryHandler, Loader, voter, controller de lecture). Un port lu des deux côtés donne **deux** familles (OHS, port, adapter).
2. **Producteur** : un `…CommandOHS` (repositories et services de domaine, ou ses propres commandes par le `CommandBusInterface` : on réutilise le cas d'usage existant, jamais de logique nouvelle ; rend un Model du producteur, `void` ou une valeur simple) et/ou un `…QueryOHS` (loaders ou `QueryBusInterface` ; rend un DTO publié `…DTO` sous `Application/OpenHostService/`, ou une valeur simple). Un OHS de lecture appuyé sur une donnée que seul un Repository donnait : ajouter la méthode au Loader (une requête, pas de modèle).
3. **Consommateur** : pour chaque OHS, un port `…CommandPort` / `…QueryPort` dans `Application/Port/`, **dans sa langue** (ses noms, ses types ; les anciens DTO de `Shared` qu'il utilise deviennent les siens, déplacés sous son `Application/` ou son `Domain/`), et son adapter dans `Infrastructure/Acl/`. L'adapter transpose, il ne décide rien.
4. **Rebrancher** les handlers sur les ports (jamais un `QueryPort` dans un handler d'écriture : `OpenHostServiceConventionTest`), supprimer le port de `Shared`, son implémentation chez le producteur, son double `tests/Helper/*InMemory`, et la ligne de `LEGACY`.
5. **Tests** : chaque test de handler construit le vrai adapter et le vrai OHS sur les doubles en mémoire du producteur (par transitivité, jamais la base) ; un adapter appelé hors handler et un OHS que nul handler n'exerce ont leur test unitaire (`tests/{Consommateur}/Acl/`, `tests/{Producteur}/…`) ; l'accès réel d'une méthode de Loader ou de Repository ajoutée se teste dans `OutputAdapter` sur la base.
6. **Vérifier** : `make test`, `make quality` (Deptrac des contextes, `OpenHostServiceConventionTest`, `SharedHoldsNoContextBusinessTest`, `ContextsDoNotShareTablesTest`), budgets SQL inchangés (`tests/Repas/WriteBudget`), et chercher dans `docs/` et `CLAUDE.md` les noms supprimés (à signaler, le fil principal les met à jour).

## Jalons

Taille : S (quelques heures), M (une journée), L (plusieurs jours). Effort recommandé pour l'agent (grille validée : `xhigh` pour les droits et l'authentification, `high` pour le reste).

- [x] **C1** · règle (ADR 0035, Deptrac, tests de convention, skill, `CLAUDE.md`) et pilote `Voice` → `User` (`ActiveUserChecker`) · M · fait par le fil principal
- [ ] **C2** · `Repas` → `User` : `IngredientCatalogOwner` et `UserDefaultServing` (une famille d'écriture et une de lecture pour le propriétaire du catalogue) **et** les cinq lectures SQL de la table `"user"` (un `QueryOHS` de `User` qui publie ce que les rapports d'exploitation lisent : identifiant, statut, adresse, statistiques ; une adresse n'est publiée qu'aux deux rapports administrateur qui la montrent aujourd'hui) · L · high
- [ ] **C3** · `Repas` → `Drive` : `DriveIngredientReferences` (empreinte, slugs référencés, repointage, aperçu et fusion des choix) · L · high · `IngredientReferencesAreAllRepointedTest` et les budgets de fusion et d'amorçage restent verts
- [ ] **C4** · `Drive` → `Repas` : alias, rayon, nom, unité, liste envoyée (5 ports, 2 DTO) · L · high · les écritures du `Drive` qui reçoivent un slug passent par `IngredientAliasResolver::resolveOwned` aujourd'hui (`IngredientSlugWritesAreResolvedTest`) : l'exigence reste, dans le port du `Drive`
- [ ] **C5** · `User` → `Repas`, `Voice` : scinder `OwnerVoter` (chaque contexte juge ses objets ; `User` garde `HIMSELF`), retirer `OwnershipLoader` et `VoiceLinkOwnershipLoader` de `Shared` · M · **xhigh** (droits) · aucun passe-droit ne change (`INGREDIENT_OWNER`, `VOICE_LINK_OWNER`, recette secrète : ADR 0028, 0029, 0033) ; `AuthenticatedUser` peut gagner `isActive()` si le voter doit refuser un compte désactivé sans importer `User`
- [ ] **C6** · `Voice` → `Repas`, ingrédients : `SpokenIngredientResolver`, `SpokenIngredientCreator` · L · high
- [ ] **C7** · `Voice` → `Repas`, liste : `VoiceShoppingList`, `SpokenListLookup`, `AddedItem`, `RemovedItem` · L · high
- [ ] **C8** · `Voice` → `Repas`, repas : `SpokenRecipeResolver`, `VoiceMealPlanner` · L · high · et, si le moment est bon, renommer les commandes `…Spoken…` de `Repas` dans sa langue (`AddIngredientToActiveShoppingList`…) : les noms de budgets (`retirer-article-dit-a-alexa*`…) suivent
- [ ] **C9** · nettoyage : interrupteurs `DriveFeature` (→ `Drive`), `VoiceFeature` (→ `Voice`), `IngredientSeedingFeature` (→ `Repas`) et leurs écouteurs HTTP ; `Dimension` (décider : type partagé ou deux types) ; commandes console rangées dans leur contexte (`UpdateUserStatisticsCommand` compose deux contextes : la rendre à `User`, qui demande à `Repas` ses chiffres par un `QueryOHS`) ; `LEGACY` vide, tests `SharedHoldsNoContextBusinessTest` réduit à sa règle ; `CLAUDE.md`, `docs/voice/alexa.md`, `docs/drive/architecture.md`, skills `repas-dev` et `repas-drive-back` relus pour les noms disparus · M · high
