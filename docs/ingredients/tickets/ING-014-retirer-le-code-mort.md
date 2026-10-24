# ING-014 — Retirer le code mort, poser les garde-fous « plus d'ingrédient sans créateur »

- **Dépôt** : back
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : M
- **Effort recommandé pour l'agent** : **high** (suppression d'un grand volume de code testé ; peu de logique neuve, mais un garde de séquence de production)
- **Dépend de** : ING-013 (même sortie R2, même condition : après l'étape 12)
- **Statut** : fait (PR #250) — sauf le retrait de la commande de passation et du « ou vide » du catalogue, qui reste à faire dans une PR à part (voir « Réalisation »)
- **Sortie** : **R2**
- **Lire d'abord** : [`sans-commun.md`](../sans-commun.md) § 11 (**la liste exacte de ce qui meurt**) et §§ 5, 7 ; ADR 0017 règles 5 et 6 ; ADR 0028 amendement ; ING-003 (ce qu'on retire) ; skill `/repas-dev`.

## Contexte

« Ce qui devient mort doit être listé et supprimé dans le dernier ticket » (décision du propriétaire). Ce ticket supprime ce que les tickets précédents ont rendu inutile, empêche que le traitement particulier d'un ingrédient sans créateur revienne, et empêche R2 de démarrer sur une base qui n'est pas passée.

## Portée

1. **Supprimer** (tout ce que le § 11 de `sans-commun.md` liste dans « Mort après la passation », moins ce qu'ING-013 a déjà fait) : le cas d'usage unitaire `AdoptCommonIngredient*` ; la migration d'ING-003 (`AdoptCommonIngredientsForUser*`, `UndoCommonIngredientsAdoptionForUser*`, `GetAdoptionReport*`, `IngredientAdoptionReportLoader` et son implémentation, `src/Command/AdoptCommonIngredientsCommand.php`, `adopter-ingredients.sh`, leurs tests, `AdoptionDocumentedSqlTest` et les doubles inutilisés) ; `IngredientVisibility::isCommon` / `isCommonSql` et ce qui reste de masquage ; `Ingredient::isCommon`, `IngredientCandidate::isCommon`, `MergeParty::$isCommon` ; les méthodes de port et d'exception inutilisées (`IngredientException::notCommon`, `findCommonSlugs`…) ; les paramètres et commentaires qui disent « commun ». Le moteur de masse (dépôt, vérificateur, empreintes) **reste** : c'est celui de l'amorçage.
2. **Domaine** : `Ingredient::create(string $creatorId, …)` et `load` exigent un créateur ; une ligne de base sans créateur (retour arrière du robinet) lève une `LogicException` claire à la lecture au lieu d'un comportement partiel. L'entité Doctrine garde sa colonne **nullable** (compatibilité avec le code précédent).
3. **Garde-fou d'architecture** `NoSharedIngredientTest` : échoue si `src/` (hors `migrations/`, `DataFixture/` et ce test) contient `creator IS NULL`, `creatorId IS NULL`, `creator IS DISTINCT`, `getCreatorId() === null`, `isCommon`, ou un `creatorId: null` pour un ingrédient. Les conversions **générales** (`conversion.ingredient` vide) sont un autre sujet et restent permises ; le test le dit.
4. **Garde de séquence** : une migration Doctrine **sans changement de schéma** dont `up()` fait `abortIf` quand `APP_ENV` est `prod` et qu'il existe un ingrédient sans créateur (message : « lancer la passation, `docs/ingredients/sans-commun.md` »). Hors production elle ne fait qu'écrire un avertissement (en développement : recharger les fixtures). Effet voulu : R2 sorti trop tôt abandonne le démarrage et `deploy.sh` revient seul à R1 (ADR 0017 règle 5). Testée sur une base avec un ingrédient sans créateur (refus) et sans (passe).
5. **Fixtures de développement** : `IngredientFixture` pose le catalogue chez le propriétaire du catalogue de développement (le compte protégé de `UserFixture`, ou `INGREDIENT_CATALOG_OWNER_EMAIL`) et marque de base les mêmes 151 (`base-ingredients.json`) ; `Data/ingredient.json` perd le champ `creator` ; `DemoUserFixture` / `DemoRepasFixture` amorcent les comptes de démonstration par le vrai cas d'usage `SeedUserIngredients` ; `make fixtures-load-dev` puis `make fixtures-demo` donnent à `admin@repas.fr` et `user@repas.fr` leurs ingrédients. `CLAUDE.md` (section « Données de démonstration ») le dit ; pour essayer l'interrupteur « de base » avec le compte de démonstration, `INGREDIENT_CATALOG_OWNER_EMAIL=admin@repas.fr` dans `.env.local`.
6. **Documents** : bandeaux « historique » (la migration est faite, le modèle a changé) en tête de `analyse.md`, `migration.md` (ses blocs SQL ne sont plus testés : le dire), `fusion.md` (règle 2 et § 8), `rapprochement.md` (§ 3 étape 0) ; `docs/production.md` : la section « Adoption des ingrédients » devient historique, celles de l'amorçage et de la passation restent ; `CLAUDE.md` : réécrire les paragraphes « Ingrédients propres à chaque utilisateur », « Fusion », « Doublons » (plus de commun, plus d'adoptant, plus de passe-droit), la ligne du chantier ; skill `repas-dev` (Definition of Done), skill `repas-drive-back` ; `docs/adr/README.md`.
7. **Budgets** : `make sql-budget-update` si des scénarios ont baissé.

## Hors portée

Le front ; retirer `canEdit` / `creatorId` du contrat ; renommer le vocabulaire « adoption » du moteur de masse (garder les noms, corriger les commentaires) ; supprimer la colonne `creator` nullable ou la table d'alias.

## Critères d'acceptation

- `make test quality` vert ; `make deptrac phpstan` verts ; la baseline PHPStan ne grossit pas.
- `NoSharedIngredientTest` passe et **échoue** quand on réintroduit `creator IS NULL` dans un fichier d'essai.
- `grep -rniE "creator(Id)? is null|getCreatorId\(\) === null|isCommon|adoptant" src/` ne trouve plus rien (hors migrations et fixtures).
- La migration-garde abandonne sur une base avec un ingrédient sans créateur en `APP_ENV=prod`, passe sinon.
- `make fixtures-load-dev` puis `make fixtures-demo` : les comptes de démonstration voient leurs ingrédients, l'administrateur de démonstration ne voit que les siens.
- Aucune migration de schéma ; `doctrine:schema:validate` vert ; `make deploy-smoke` vert (la migration-garde passe sur une base vide).
- Compatibilité (ADR 0017 règle 6) : R1 relit la base de R2 (rien d'écrit de neuf).

## Tests exigés

Ceux qui disparaissent avec le code ; `NoSharedIngredientTest` ; test de la migration-garde ; test des fixtures (données de développement) ; `make test quality openapi-check`.

## Definition of Done

`repas-dev` § 4 ; roadmap cochée ; PR « À relire » : la liste de ce qui est supprimé (fichiers, nombre de lignes), l'effet sur les budgets.

## Pièges

- **Ne pas supprimer** : la table et le dépôt d'alias, `adopted_from`, `IngredientAliasResolver` (port `Shared`), `IngredientSlugWritesAreResolvedTest`, le moteur de masse, le rapprochement, la fusion, les doublons, `ingredient_distinct`.
- La migration-garde ne doit **pas** rendre le développement impossible : seul `prod` abandonne.
- Un test d'architecture qui grep `src/` doit exclure son propre fichier et les migrations (qui contiennent l'historique).
- Ne pas oublier `tests/Helper` (InMemory…) : les doubles qui modélisent « commun » ou « adoptant » (`IngredientAliasInMemoryRepository`, `IngredientSlugResolverFactory`).

## Réalisation (PR #250, 2026-10-24)

- **Fait** : tout ce que la portée liste, sauf le retrait de la passation. Le détail de ce qui est retiré et de ce qui reste est dans [`sans-commun.md`](../sans-commun.md) § 2.7. 35 fichiers supprimés (17 dans `src/`, 17 dans `tests/`, le script `adopter-ingredients.sh`), 5 347 lignes retirées pour 565 ajoutées ; `make test` 3 911 tests, `make quality` et `make deploy-smoke` verts.
- **Écart au ticket, volontaire** : `hand-over-catalog` (ING-009), le classement et l'amorçage de son compte vide dépendent encore du « ou vide » (`IngredientVisibility::isCommonSql`, `isCatalogSql`) et de l'état d'avant la passation ; ses ~100 tests aussi. Les retirer demande de supprimer la commande, son script `passer-communs-a-l-administrateur.sh`, `HandoverDataset`, `BaseIngredientClassification` côté commande et de simplifier `SeedingSourceReader` (plus d'état « supposé »). `NoSharedIngredientTest` liste donc cinq fichiers d'exception avec leur raison, et **échoue quand l'un d'eux n'en a plus besoin** : la liste ne peut que rétrécir. À faire dans une PR à part, dès que le propriétaire n'a plus besoin de rejouer la passation.
- **Plus de retour arrière des données par commande** : `adopt-commons --undo` est retiré avec le reste ; la sauvegarde `avant-amorcage-ingredients` est la seule voie (décision humaine).
- **Production** : relevé en lecture seule le 2026-10-24, 0 ingrédient sans créateur sur 1 823 ; la migration-garde passera.
- **Fixtures** : les trois ingrédients « spécifiques » des recettes de démonstration (`aiguilette-de-poulet`, `haricot-rouge`, `pomme-golden`) sont remplacés par leurs équivalents « de base » (`poulet`, `haricot`, `pomme`), car un compte de démonstration ne reçoit que les ingrédients « de base ».
- **Hors portée de l'ancienne rédaction, ajouté** : `make test` lit `phpunit.dist.xml` (plus de copie locale qui se périme), le journal du conteneur est un `NullLogger` en test et les mesures de performance ne s'affichent qu'avec `TEST_PERF_REPORT=1` (PR #249).
