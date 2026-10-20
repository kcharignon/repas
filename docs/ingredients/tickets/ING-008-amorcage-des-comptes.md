# ING-008 — Amorçage des comptes à l'activation, rattrapage, interrupteur

- **Dépôt** : back (+ script d'exploitation)
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (change ce que reçoit **chaque** compte, écrit dans la transaction d'activation, touche les données de production par le rattrapage)
- **Dépend de** : ING-011 (marqueur `is_base`, port du propriétaire du catalogue)
- **Statut** : à faire — **remplace** l'ancien ING-008 (« adoption à la première utilisation »), abandonné par l'amendement du 2026-10-20
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

`Repas\Infrastructure\EventListener\SeedIngredientsOnUserActivatedListener` (`#[AsEventListener]`, modèle : `PurgeUserDataOnUserDeletedListener`) : si `IngredientSeedingFeature::isEnabled()`, dispatche `SeedUserIngredientsCommand(userId, 'activation')`. Synchrone, **dans la transaction de l'activation** : si l'amorçage échoue, l'activation échoue (D6) ; l'erreur est claire (`IngredientAdoptionException`, `500` journalisé, message sans donnée personnelle) et l'utilisateur réessaie. Interrupteur coupé : aucune requête de plus.

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
