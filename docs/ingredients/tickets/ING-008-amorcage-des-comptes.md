# ING-008 — Amorçage des comptes à l'activation, rattrapage, interrupteur

- **Dépôt** : back (+ script d'exploitation)
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (change ce que reçoit **chaque** compte, écrit dans la transaction d'activation, touche les données de production par le rattrapage)
- **Dépend de** : ING-011 (marqueur `is_base`, port du propriétaire du catalogue)
- **Statut** : fait (PR #244) — **remplace** l'ancien ING-008 (« adoption à la première utilisation »), abandonné par l'amendement du 2026-10-20
- **Sortie** : **R1**
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décisions 4 à 8, D5, D6) ; **[`sans-commun.md`](../sans-commun.md) §§ 1 à 4, 6 à 8 en entier** ; [`migration.md`](../migration.md) §§ 6 à 9 (le moteur qu'on réutilise) ; ING-003 et sa section « Réalisation » ; ADR 0021 (statuts, purge), 0017 règle 6, 0013 règle 7 ; skills `/repas-dev`, `/repas-drive-back`, `/repas-ops`.

## Contexte

Il n'y aura plus d'ingrédient commun (amendement) : chaque compte a **ses** ingrédients. Les comptes déjà migrés (ING-003) les ont. Il reste (1) les **nouveaux comptes**, qui doivent recevoir une copie des ingrédients **de base** du propriétaire du catalogue **au moment où ils deviennent actifs**, (2) le **compte vide** actuel et tout compte actif qui n'a pas encore les siens (rattrapage), (3) un interrupteur pour couper l'amorçage automatique. L'adoption à la première utilisation (filet) disparaît : la garantie vient de l'atomicité de l'activation et d'une requête de santé.

## Portée

### A. L'événement d'activation

- `Shared\Domain\Event\UserActivatedEvent(string $userId)`.
- Dispatché **après `save()`**, dans la transaction du bus, par chaque chemin qui rend un compte `ACTIF` : `VerifyEmailHandler` (compte en attente), `ResetPasswordHandler` (`$wasPending`), `ChangeUserStatusHandler` (nouveau statut `ACTIVE` alors que l'ancien ne l'était pas), et par les gestionnaires de la connexion Google (AUTH-006 : création `ACTIVE`, liaison d'un compte en attente) quand ils existent. Un compte déjà actif qui reste actif n'émet rien.
- **Garde-fou** `UserActivationDispatchesEventTest` (architecture) : parcourt `src/User/Application/**/*Handler.php` ; tout gestionnaire qui appelle `verifyEmail(`, `changeStatus(`, `User::create(` ou `registerFromIdentity(` doit citer `UserActivatedEvent`, sauf exemptions motivées listées dans le test (`RegisterNewUserHandler` : le compte naît `PENDING`). Ajouter un chemin d'activation impose de le déclarer ici.

### B. L'interrupteur

- Port `Shared\Application\Interface\IngredientSeedingFeature::isEnabled(): bool`, implémentation `EnvIngredientSeedingFeature` (variable `INGREDIENT_SEEDING_ENABLED`, **coupée par défaut**, y compris quand la ligne est absente). Listée dans `deploy/compose.yaml` (`${INGREDIENT_SEEDING_ENABLED:-false}`, sinon le `.env` embarqué dans l'image s'appliquerait en silence), `deploy/.env.example`, `.env` (`false`), `.env.test` (`false` : les tests qui amorcent allument le double). `deploy/smoke-test.sh` : une vérification « l'amorçage est coupé par défaut ». `docs/production.md` : ligne du tableau des variables. Script humain `.claude/skills/repas-ops/references/ecrire-ingredient-seeding.sh` (modèle : `ecrire-drive-enabled.sh`).
- L'interrupteur ne commande que l'amorçage **automatique** (§ D). La commande de rattrapage (§ E) marche interrupteur coupé.

### C. Le cas d'usage `SeedUserIngredients`

`Application/Command/SeedUserIngredients/` : `SeedUserIngredientsCommand(userId, source, acceptSent = true, complete = false)` + `SeedUserIngredientsHandler` (transactionnel par le bus) → `SeedResult`. C'est l'algorithme d'ING-003 (`migration.md` § 7) avec trois différences : **la source**, **le périmètre**, **aucun commun** dans le vocabulaire. Il réutilise `IngredientAdoptionBulkRepository` (méthodes **ajoutées** ; ne pas modifier le comportement des méthodes d'ING-003 : sa commande et son retour arrière restent valables jusqu'à ING-014), `AdoptionVerifier`, `AdoptionSnapshotReader`, `IngredientSlugPolicy`, `UuidGenerator`.

1. Verrou `pg_advisory_xact_lock(hashtext('seed:' || U))`.
2. **Périmètre** : le compte existe ; ce n'est pas le propriétaire du catalogue (`IngredientCatalogOwner`) ; il n'est pas déjà amorçé (`isAdopter`), sauf `complete`. Sinon `SeedResult::notConcerned()` / `alreadySeeded()`, **aucune écriture** (versions comprises).
3. **Source** (`sans-commun.md` § 2.2, écrite **à un seul endroit**, un fragment de requête de catalogue) : les ingrédients `is_base` dont le créateur est le propriétaire **ou vide** ; plus, quand le compte a des lignes (rattrapage d'un compte qui a des données, activation d'un compte réactivé), les ingrédients du catalogue (créateur propriétaire ou vide) que ses recettes, listes et choix du drive référencent (`DriveIngredientReferences::referencedSlugs(userId)`, **méthode ajoutée** au port `Shared`, implémentée par `Drive`). Source vide : rien n'est écrit, le compte **reste non amorcé**, un `warning` est journalisé, le résultat le dit.
4. Les copies, dans l'ordre d'ING-003 : alias `adoption` d'abord (`INSERT … ON CONFLICT`), puis les ingrédients (`adopted_from` = le slug source, **`is_base = FALSE`**, `creator` = U, slug par `IngredientSlugPolicy::forAdoption`, `-2`… en cas de collision), puis les conversions **propres** (nouveaux identifiants) ; les conversions générales ne sont pas copiées. Un `INSERT … SELECT` par lot : le nombre de requêtes est **constant** quel que soit le nombre d'ingrédients.
5. Repointage des lignes du compte qui référencent une origine copiée (recettes de l'auteur, listes du propriétaire **y compris envoyées au drive** — exception justifiée d'ING-003, contenu, statut et date d'envoi inchangés —, choix du drive par `DriveIngredientReferences::repoint`), versions +1 pour ce qui change. Pour un compte neuf il n'y a rien : les lectures coûtent, les écritures pas.
6. Empreintes avant/après et conformité des copies (`AdoptionVerifier`, `nonConformingCopies`) **dans** la transaction ; à la moindre divergence, `IngredientAdoptionException` → rien n'est écrit pour ce compte.
7. **Aucun événement, aucune statistique** (une adoption n'est pas une création, ADR 0028 décision 7). Aucune donnée supprimée.
8. `source` écrite dans les alias : `activation` pour l'écouteur, sinon le nom passé (`rattrapage-AAAA-MM-JJ`).

### D. L'écouteur d'activation

`Repas\Infrastructure\EventListener\SeedIngredientsOnUserActivatedListener` (`#[AsEventListener]`, modèle : `PurgeUserDataOnUserDeletedListener`) : si `IngredientSeedingFeature::isEnabled()`, dispatche `SeedUserIngredientsCommand(userId, 'activation')`. Synchrone, **dans la transaction de l'activation** : si l'amorçage échoue, l'activation échoue (D6) ; l'erreur est claire (`IngredientAdoptionException`, `500` journalisé, message sans donnée personnelle) et l'utilisateur réessaie. Interrupteur coupé : aucune requête de plus. Hors production : `.env` laisse la variable à `false` (comme `DRIVE_ENABLED`, et la ligne de `deploy/compose.yaml` reste la seule qui compte en production) ; pour essayer l'inscription en développement, `INGREDIENT_SEEDING_ENABLED=true` dans `.env.local`.

### E. La commande de rattrapage `repas:ingredients:seed`

Sur le modèle d'ING-003 (`migration.md` § 6) : sans option, **à blanc** (lecture seule, rapport) ; `--apply` ; `--check` (verdict, code de sortie 0 si et seulement si tout est conforme) ; `--user=<référence>` et `--except=<référence>` (répétables, la référence courte du rapport) ; `--accept-sent` ; `--source=<nom>` (défaut `rattrapage-AAAA-MM-JJ`) ; `--keep-going` (par défaut elle s'arrête au premier échec) ; `--complete` (complète aussi un compte déjà amorcé ; jamais sur tous). Périmètre par défaut : les comptes `ACTIF`, hors propriétaire, non amorcés ; `--user` peut en viser un de n'importe quel statut. Le **rapport** (jamais d'adresse, d'identifiant ni de nom d'ingrédient personnel : comptes numérotés) donne par compte : statut, amorcé ou non, nombre d'ingrédients, ce qui serait copié (ingrédients, conversions), collisions de slug, lignes à repointer par table dont les listes envoyées, empreinte du contenu. **`--check` = la requête de santé** : (1) aucun compte actif hors propriétaire n'est non amorcé, (2) aucun compte actif n'est sans ingrédient, (3) aucune ligne d'un compte autre que le propriétaire ne référence l'ingrédient d'un autre compte, (4) pour le propriétaire seulement : la même chose dès qu'aucun ingrédient sans créateur n'existe. Les communs d'avant ne sont jamais modifiés.

### F. Le script d'exploitation `amorcer-ingredients.sh`

`.claude/skills/repas-ops/references/amorcer-ingredients.sh`, sur le modèle d'`adopter-ingredients.sh` : sans argument, lecture seule (rapport à blanc) ; `--appliquer` : rapport, **sauvegarde `./backup.sh avant-amorcage-ingredients`**, écriture, `--check` ; `--verifier` ; `--accepter-listes-envoyees`, `--compte=`, `--sauf=`, `--complet`, `--lancement=`. Lancé par l'humain (`!`), jamais par un agent. Essayé sur une copie (`REPAS_DIR`) par `AmorcerIngredientsScriptTest`. Le script d'ING-003 reste tel quel.

### G. Budgets

Nouveaux scénarios `WriteBudget` : `confirmer-adresse-amorcage` (VerifyEmail, interrupteur allumé, catalogue de N ingrédients de base) et `activer-compte-amorcage` (ChangeUserStatus → ACTIVE), **constants en N** (mesurés avec 5 puis 50 ingrédients). Les budgets existants de ces use cases **ne montent pas** interrupteur coupé.

## Hors portée

La passation et le classement des 261 (ING-009) ; le nettoyage du résolveur et de l'adoption à la volée (ING-013, ING-014) ; le front ; les fixtures de développement (ING-014) ; l'amorçage à l'inscription (écarté) ; compléter un compte déjà amorcé automatiquement (écarté : rien n'est propagé).

## Critères d'acceptation

(Les nombres sont ceux du jeu de test.)

- **Activation.** Étant donné l'interrupteur allumé, un propriétaire avec 5 ingrédients dont 3 de base (dont un avec 2 conversions propres) et un compte `PENDING` : quand il confirme son adresse, alors il a **3** ingrédients à lui (`creator` = lui, `adopted_from` = le slug source, `is_base` faux, mêmes nom, image, rayon, unités), **les conversions propres copiées** (nouveaux identifiants), 3 alias `adoption` (`source = activation`), et ses statistiques n'ont pas bougé ; les 2 ingrédients non marqués ne lui sont pas copiés.
- **Chaque chemin.** Idem par le lien de mot de passe oublié (compte en attente), par `PUT /users/{id}/status` vers `ACTIVE` (depuis `PENDING` et depuis `DISABLED`).
- **Atomicité.** Une erreur injectée dans l'amorçage annule l'activation : le compte reste `PENDING`, aucune copie, le jeton de confirmation n'est pas consommé.
- **Idempotence.** Réactiver (`DISABLED` → `ACTIVE`) un compte déjà amorcé n'écrit rien ; un compte qui a supprimé toutes ses copies est de nouveau servi.
- **Pas à l'inscription.** `POST /api/v1/users` n'écrit aucun ingrédient.
- **Interrupteur coupé.** L'activation réussit, aucun ingrédient n'est créé, aucune requête de plus.
- **Le propriétaire n'est jamais amorcé** (même activé, même avec `--user`).
- **Règle transitoire** (`sans-commun.md` § 2.2). Étant donné des ingrédients sans créateur dont certains `is_base`, et aucun ingrédient du propriétaire : un compte reçoit les seuls marqués ; après qu'ils sont passés au propriétaire (mêmes slugs), il reçoit exactement les mêmes. Marqueur partout faux : rien n'est écrit, le compte reste non amorcé (`warning`).
- **Un marqueur ailleurs est inerte** : l'ingrédient de base d'un utilisateur ordinaire n'est jamais copié.
- **Rattrapage.** Un compte vide actif (ni ingrédient ni recette) avec une liste dont une ligne porte un ingrédient **non de base** du catalogue reçoit les ingrédients de base **et** celui-là ; sa ligne est repointée sur sa copie ; contenu, quantités, statut, date d'envoi d'une liste envoyée inchangés (empreintes) ; les versions touchées +1, aucune autre.
- **Aucun compte déjà amorcé n'est modifié** par `repas:ingredients:seed` sans `--complete` (somme de contrôle de toutes les tables identique, versions comprises).
- **À blanc / `--check`** : aucune écriture (aucun `INSERT`, `UPDATE`, `DELETE`) ; `--check` sort en erreur tant qu'un compte actif est non amorcé et en succès sinon.
- **Budgets** : les deux nouveaux scénarios sont figés et constants en N ; les budgets existants ne montent pas interrupteur coupé.
- **Garde-fou** : `UserActivationDispatchesEventTest` échoue si un gestionnaire d'activation oublie l'événement (essayer avec un faux gestionnaire dans un répertoire d'essai).
- **Compatibilité** (ADR 0017 règle 6) : aucune migration dans ce ticket ; l'ancien code ignore l'événement, la variable et les alias ; ce que l'amorçage écrit (ingrédients personnels, alias) est la forme d'ING-003 que l'ancien code lit.

## Tests exigés

Handler `SeedUserIngredientsHandlerTest` (doubles en mémoire : périmètre, source, règle transitoire, marqueur inerte, idempotence, aucun événement) ; **base réelle** (`OutputAdapter`) pour le dépôt de masse (méthodes ajoutées) et la lecture du rapport, avec recettes, listes de tous les statuts dont `SENT`, choix du drive de plusieurs enseignes, collisions de slug, conversions propres et générales ; écouteur (interrupteur) ; **parcours avec les vrais bus** : inscription → confirmation → ingrédients (`UserActivationSeedingFlowTest`), mot de passe oublié, statut par l'administrateur, suppression du compte amorcé (purge complète : ingrédients, conversions, alias) ; test de console (`CommandTester`) ; test du script avec `REPAS_DIR` ; test d'équivalence du drive (plan et estimation d'un compte rattrapé, identiques à l'ingrédient près) ; budgets ; `make test quality`, `make deploy-smoke` (variable ajoutée à `deploy/`).

## Definition of Done

`repas-dev` § 4 ; `CLAUDE.md` (ligne du chantier, phrase sur l'amorçage) et skill `repas-dev` (« tout chemin qui active un compte dispatche `UserActivatedEvent` ») ; `docs/production.md` (variable) ; roadmap cochée ; PR « À relire » : requêtes SQL émises par amorçage (nombre, constant), budgets annoncés.

## Pièges

- **L'amorçage vit dans la transaction d'activation** : ne pas l'appeler après coup, ne pas l'attraper pour continuer (D6). ADR 0013 règle 7 : il ne charge ni n'enregistre le `User` (aucune statistique) ; `User` n'est pas modifié après l'événement.
- **Le propriétaire n'est pas un administrateur quelconque** : `IngredientCatalogOwner`, pas `ROLE_ADMIN` (un second administrateur est un compte ordinaire, amorcé).
- La source est « de base **et** créateur propriétaire ou vide » : sans le « ou vide » le rattrapage d'avant la passation ne trouve rien ; sans « de base » on recopie les 261. Un seul fragment, testé dans les deux formes de la base.
- Les copies reçoivent `is_base = FALSE` **explicitement** (la colonne par défaut ne suffit pas à une copie colonne par colonne).
- Ne pas créer de **second** moteur : réutiliser le dépôt de masse d'ING-003, sans changer ses méthodes existantes (son retour arrière doit continuer de passer ses tests).
- `Drive` ne dépend que de `Shared` : `referencedSlugs` est une méthode de port, pas une lecture SQL de `drive_choice` depuis `Repas`. Aucun nom d'enseigne.
- `ChangeUserStatusHandler` ne connaît pas l'ancien statut : le lire avant `changeStatus()` ; un `ACTIVE` → `ACTIVE` n'émet rien.
- Un compte `PENDING` ou `DISABLED` n'est jamais amorcé tant qu'il n'est pas actif.
- Ne rien afficher d'une adresse, d'un identifiant ou d'un nom d'ingrédient personnel dans le rapport ni les journaux.

## Réalisation (PR back #244)

Livré comme décrit ci-dessus, avec les écarts et ajouts suivants, tous décidés pour tenir l'intention du ticket et de l'amendement du 2026-10-20. **Rien n'a été exécuté en production**, aucun schéma ne change (aucune migration), l'interrupteur est coupé par défaut ; le détail d'usage est dans [`sans-commun.md`](../sans-commun.md) §§ 2.5 et 13.

**Contrats** :

- `Repas\Shared\Domain\Event\UserActivatedEvent(string $userId)`, dispatché **après `save()`** par `VerifyEmailHandler` (si le compte était `PENDING`), `ResetPasswordHandler` (`$wasPending`) et `ChangeUserStatusHandler` (nouveau statut `ACTIVE` et ancien statut différent). Chacun gagne `EventDispatcherInterface` en dernier argument de son constructeur.
- `Repas\Shared\Application\Interface\IngredientSeedingFeature::isEnabled(): bool`, implémentation `Shared\Infrastructure\Feature\EnvIngredientSeedingFeature` (`%env(bool:default::INGREDIENT_SEEDING_ENABLED)%` : absente, vide ou invalide = coupé, prouvé par `IngredientSeedingFeatureWiringTest`). Écouteur `Repas\Repas\Infrastructure\EventListener\SeedIngredientsOnUserActivatedListener`. `.env` et `.env.test` : `false` ; `phpunit.dist.xml` force `false` ; `deploy/compose.yaml` (`${INGREDIENT_SEEDING_ENABLED:-false}`), `deploy/.env.example`, `deploy/smoke-test.sh` (une vérification « coupé par défaut », rejouée verte), `docs/production.md`, scripts `ecrire-ingredient-seeding.sh` (modèle `ecrire-drive-enabled.sh` et `ecrire-app-version.sh`, mêmes garde-fous, essayé par `EcrireIngredientSeedingScriptTest`) et `amorcer-ingredients.sh` (modèle `adopter-ingredients.sh`, essayé par `AmorcerIngredientsScriptTest`).
- `Repas\Repas\Application\Command\SeedUserIngredients\SeedUserIngredientsCommand(userId, source, acceptSent = true, complete = false)` → `SeedUserIngredientsHandler` → `SeedResult(outcome: SeedOutcome, seeded, seededBecauseReferenced, reused, slugCollisions, conversionsCopied, repointed, driveChoicesRepointed, sentShoppingListsRepointed, fingerprint)`. `SeedOutcome` : `SEEDED`, `NOT_CONCERNED` (compte inconnu ou propriétaire), `ALREADY_SEEDED`, `NO_CATALOG` (pas de propriétaire ou aucun « de base »), `NOTHING_TO_ADD` (`complete` sans rien à ajouter).
- Ports ajoutés (les méthodes d'ING-003 ne changent pas) : `IngredientAdoptionBulkRepository::lockSeeding`, `accountExists`, `isSeeded`, `ingredientsToSeed`, `copyCatalogIngredients` (valeur `IngredientToSeed`) ; `DriveIngredientReferences::referencedSlugs` et `referencedSlugsOfEveryUser` (implémentés par `Drive`). `IngredientVisibility::isCatalogSql` / `isBaseCatalogSql` : la règle « de base ET créateur propriétaire ou vide », écrite une fois ; `SeedingSourceReader` la lit pour l'écriture et pour le rapport.
- Rapport : requête `GetSeedingReportQuery(only, except, complete)` → `SeedingReportDTO` (`SeedingUserReportDTO`, `SeedingHealthDTO`), port `IngredientSeedingReportLoader`, implémentation `IngredientSeedingReportPostgreSQLLoader`. Commande `repas:ingredients:seed` : `--apply`, `--check`, `--accept-sent`, `--user`, `--except`, `--source`, `--keep-going`, `--complete` ; codes de sortie de `--check` : 0, 1, 3 (santé rouge seule).

**Écarts avec le ticket** :

1. **`isSeeded` est un port de `IngredientAdoptionBulkRepository`**, pas un appel à `IngredientAliasRepository::isAdopter` : même définition (au moins un alias d'adoption, même orphelin), un seul port pour le handler et son double en mémoire ; `IngredientSeedingRepositoryTest` compare les deux sur une base réelle.
2. **Source vide stricte** : quand aucun ingrédient « de base » n'existe dans le catalogue (classement pas posé) ou quand le propriétaire n'existe pas, **rien n'est écrit même si les lignes du compte référencent des ingrédients** (le ticket ne parlait que de la source vide) : sinon le compte serait marqué amorcé avec une partie du catalogue et ne serait jamais servi pour le reste.
3. **`--complete` répare aussi** : un compte déjà amorcé dont une ligne ou un choix du drive est resté sur l'origine d'une adoption qu'il a déjà (un vieil onglet) est repointé sur sa copie, sans rien copier. C'est le remède de la requête de santé (3), sans lequel rien ne l'aurait réparé après la passation (le `--apply` d'ING-003 le faisait).
4. **`--check` rend 3** quand seule la santé est rouge (par exemple un compte en attente ou désactivé qui garde des lignes sur le catalogue : la requête de santé les compte quel que soit le statut, comme P2) : le script n'a alors rien à sauvegarder ni à écrire. `--apply` n'échoue pas pour une santé rouge qu'il n'a pas causée : il l'affiche, le script rejoue `--check`.
5. **Le garde-fou compte plus de chemins** que ceux du ticket : `setStatus(` et `ExternalIdentityResolver` (AUTH-006) en plus de `verifyEmail(`, `changeStatus(`, `User::create(`, `registerFromIdentity(` ; il exige `new UserActivatedEvent(` (une simple citation ne suffit pas) ; un faux gestionnaire dans un répertoire d'essai prouve qu'il échoue. AUTH-006 est mis à jour (la connexion avec Google doit dispatcher l'événement).
6. `IngredientAdoptionBulkPostgreSQLRepository::COPY_DIFFERS_FROM_COMMON` passe de `private` à `public` (le lecteur de source le réutilise) : aucun changement de comportement.
7. `IngredientAdoptionException::seedSlugTooLong` : le message d'un slug trop long ne cite pas le slug (le nom d'un ingrédient du catalogue peut être personnel).
8. **Le propriétaire n'entre dans les contrôles 2 et 4 de la requête de santé que lorsqu'il ne reste aucun ingrédient sans créateur** (avant la passation il utilise les anciens communs).

**Budgets SQL** (`tests/Repas/WriteBudget`) : nouveaux scénarios `amorcer-compte` **29** (le coût fixe de l'amorçage d'un compte, constant en nombre d'ingrédients : lire le propriétaire, périmètre, source, empreintes avant et après, trois `INSERT … SELECT`, deux `UPDATE` de repointage), `confirmer-adresse-amorcage` **33** (4 d'une confirmation + 29), `activer-compte-amorcage` **32** (3 + 29) ; `SeedingActivationBudgetTest` les mesure avec 5 puis 50 ingrédients de base (même nombre) ; `confirmation-email` (4) et `changer-statut-compte` (4) **ne bougent pas** interrupteur coupé. `SeedProductionLikeCatalogTest` : 261 anciens communs du fichier `docs/ingredients/base-ingredients.tsv` (151 « de base », sommes de contrôle du document vérifiées), une centaine de conversions : un compte reçoit exactement les 151 en 29 requêtes et moins de 0,2 s, avant comme après la passation ; la confirmation d'adresse par l'API (inscription, lien, JWT, fiche et rayon) en moins d'une seconde.

**Ce qui est prouvé** : activation par chacun des chemins (confirmation, lien de mot de passe oublié, statut vers `ACTIVE` depuis `PENDING` et `DISABLED`) ; l'inscription seule n'écrit aucune ligne ; interrupteur coupé l'activation réussit sans rien copier ; un amorçage qui échoue annule l'activation (compte `PENDING`, aucune copie, lien non consommé, réessayable) ; un compte déjà amorcé n'est jamais modifié (somme de contrôle de toutes les tables, versions comprises) ; le propriétaire n'est jamais amorcé (même activé, même avec `--user --complete`) ; un second administrateur est un compte ordinaire ; le marqueur d'un autre compte est inerte ; la règle transitoire donne les mêmes ingrédients avant et après la passation ; rattrapage d'un compte qui a des listes dont une envoyée et des choix du drive (plan et estimation identiques à l'ingrédient près, `SeedingDriveEquivalenceTest`) ; à blanc et `--check` n'écrivent rien (aucun `INSERT`, `UPDATE`, `DELETE` ni verrou) ; une faute injectée annule un compte seul (`--keep-going` sert les autres) ; la sortie ne contient ni adresse, ni identifiant, ni nom d'ingrédient personnel ; la suppression d'un compte amorcé efface ingrédients, conversions et alias ; le retour arrière d'ING-003 (`--undo=<source>`) défait un lancement d'amorçage.

**Compatibilité avec le code précédent (ADR 0017 règle 6)** : aucune migration ; l'ancien code ignore l'événement, la variable et l'écouteur. Ce que l'amorçage écrit (ingrédients personnels avec `adopted_from`, conversions, alias `adoption` de `source` `activation` ou `rattrapage-…`) est la forme d'ING-003, que l'ancien code lit. Vérifié en rejouant le code de `origin/main` d'avant ce ticket (un second conteneur sur la même base de développement) sur trois comptes amorcés : connexion `200`, rayon `200` (les cinq copies modifiables et le commun partagé non copié), fiche d'une copie `200` (`canEdit` vrai, `isBase` faux), `PUT` d'une copie `204` (le nom se retrouve dans la recette), lecture de la recette `200`, suppression d'une copie `204` (le commun redevient visible). `make deploy-smoke` rejoué en local : toutes les vérifications passent, y compris la nouvelle.

**Points d'attention pour ING-009 (passation)** :

- **L'ordre** : le classement (phase 1) doit être posé **avant** la phase 2, sinon `SeedOutcome::NO_CATALOG` (rien n'est écrit, aucun compte n'est marqué) : la commande de passation appelle `SeedUserIngredientsCommand(userId, 'rattrapage-AAAA-MM-JJ', acceptSent: true)` pour chaque compte retenu par `GetSeedingReportQuery` (même sélection : comptes actifs, hors propriétaire, non amorcés) et s'arrête au premier échec.
- **Les préconditions** se lisent dans `SeedingHealthDTO` : P3 = `activeNotSeeded === 0` et `activeWithoutIngredient === 0` (le propriétaire y entre dès qu'aucun ingrédient sans créateur n'existe) ; P2 = `foreignLines` (quel que soit le statut du compte ; le propriétaire est exclu) ; P4 = `IngredientSeedingFeature::isEnabled()`. La référence courte d'un compte est `IngredientSeedingReportPostgreSQLLoader::referenceOf` (la même que celle d'ING-003).
- **`IngredientVisibility::isCatalogSql`** contient le « ou vide » : après la passation il devient inutile (ING-014 le retire) ; `ClassifyBaseIngredients` écrit `is_base` par SQL et le dépôt d'ingrédient recopie le marqueur du modèle à chaque `save()` : ne pas enregistrer de modèle d'ingrédient avant la phase 1.
- **Les statistiques** : l'amorçage n'émet aucun événement. Le propriétaire gagne ses 261 « ingrédients créés » par l'annonce unique de la passation.
- **Le moteur** : un nouvel `INSERT` d'ingrédient nomme `is_base` (`FALSE` pour une copie) ; la passation n'en a aucun.
- **`--check` rend 3** quand seule la santé est rouge : la commande de passation peut s'en servir pour dire « rien à amorcer mais P2 ou P3 manquent ».

**Non vérifié** : le comportement avec plusieurs conteneurs `php` (les verrous consultatifs sont par transaction, donc sûrs, mais l'essai n'a eu qu'un conteneur) ; la durée réelle sur la production (de l'ordre de 0,2 s pour 151 ingrédients et ~100 conversions sur la base de développement) ; l'essai de bout en bout par le front (ING-015) et par la connexion avec Google (AUTH-006).
