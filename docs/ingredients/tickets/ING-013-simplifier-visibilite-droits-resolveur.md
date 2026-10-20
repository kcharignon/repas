# ING-013 — Simplifier : visibilité, droits, résolveur, copie de recette, fusion, doublons, conversions

- **Dépôt** : back
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : L
- **Effort recommandé pour l'agent** : **xhigh** (règles d'accès à tous les ingrédients, `OwnerVoter`, toutes les écritures qui reçoivent un slug)
- **Dépend de** : **la passation exécutée et contrôlée** (`sans-commun.md` § 4, étape 12) ; R1 en production
- **Statut** : à faire — **la PR se prépare dès R1 mais ne se fusionne qu'après l'étape 12** (sinon une sortie accidentelle rendrait le propriétaire aveugle)
- **Sortie** : **R2**, avec ING-014, **un autre jour que la passation**
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décisions 1, 2, 8 ; tableau « Ce qui devient obsolète » ; D3, D4, D7) ; **[`sans-commun.md`](../sans-commun.md) §§ 7, 9, 11** ; [`analyse.md`](../analyse.md) §§ 3, 4 (l'inventaire d'origine) ; ING-002, ING-004, ING-005, ING-006 et leurs sections « Réalisation » (ce qu'on simplifie) ; ADR 0007 §§ 11 à 13 ; skills `/repas-dev`, `/repas-drive-back`.

## Contexte

Une fois les 261 anciens communs passés au propriétaire et tout compte actif amorcé, **aucun ingrédient n'est plus partagé**. Le code qui traite « sans créateur » à part est mort ou nuisible (le propriétaire ne verrait plus rien si la visibilité restait celle d'aujourd'hui, avec une branche administrateur en plus). Ce ticket livre le **comportement** de « tous les utilisateurs ont leurs ingrédients et basta » ; ING-014 retire le reste du code mort et pose les garde-fous.

## Portée

1. **Visibilité (une seule règle)** : `IngredientVisibility::visibleBy` devient « `creator` = l'utilisateur ». Appliquée telle quelle aux mêmes endroits qu'ING-002 : `IngredientPostgreSQLLoader::listByDepartment`, `::listAvailable`, `::search`, `IngredientPostgreSQLRepository::findByDepartmentAndOwner`, `::findByOwner`, `::findPurchaseUnitSlugUsableBy`, `IngredientMatchCandidatesPostgreSQLRepository::visibleTo`. `::get` (la fiche) : `404` quand le créateur n'est pas le lecteur, **sans exception administrateur** (D3).
2. **`canEdit`** : `creator` = le lecteur, donc toujours vrai pour ce qui est visible. Le champ **reste** dans le contrat (compatibilité des clients en cache, le front ne s'en sert plus pour décider, ING-015) ; son retrait est hors chantier. Les paramètres `$viewerIsAdmin` des requêtes, handlers, du port `IngredientLoader` et des controllers disparaissent.
3. **Droits** : `OwnerVoter` ne laisse plus passer l'administrateur pour `INGREDIENT_OWNER` (l'administrateur modifie, supprime, fusionne les **siens**, comme tout le monde) ; son passe-droit reste pour les autres attributs. `Recipe::assertIngredientIsAvailable` : l'ingrédient est à l'auteur, sinon `404` (plus de branche « sans créateur »).
4. **Résolveur d'écriture** : `IngredientSlugResolver` ne fait plus que résoudre les alias valides (`resolve`) : plus d'adoption à la volée, plus de notion d'« adoptant » (`AliasLookup::$adopter`, `IngredientRepository::findCommonSlugs`, `SOURCE_FIRST_USE`). Mêmes appelants qu'ING-002 ; `IngredientSlugWritesAreResolvedTest` inchangé dans son principe. La lecture d'alias reste une requête ; la lecture des communs et l'adoption disparaissent : les budgets `WriteBudget` **baissent** (`make sql-budget-update`, à annoncer).
5. **Copie de recette** : l'étape 0 (provenance) prend la clé d'alias `adopted_from` de l'ingrédient source, **sinon son slug**, et la résout par les alias du copieur ; plus de branche « commun », plus d'adoption à la volée ; si le copieur n'a rien pour cette clé, le rapprochement par le nom, puis la copie. **Toute copie retient son origine** (`adopted_from` = l'origine de l'original, sinon son slug : D7) : elle n'est donc plus comptée comme une création ; `RecipesOrIngredientsCreatedEvent` ne porte plus d'ingrédient créé par une copie de recette.
6. **Fusion et doublons** : retirer `MergeBlock::COMMON_INGREDIENT`, `IngredientException::cannotMergeCommon` (`CANNOT_MERGE_COMMON_INGREDIENT`), la branche de `IngredientMergePlanner`, de `IngredientMergePostgreSQLLoader`, de `MergeIngredientsHandler` (toujours `assertOwnedBy`), de `DuplicateIngredientFinder` ; l'administrateur fusionne et voit des doublons parmi **ses** ingrédients (le catalogue de 261 ne donne aucune paire : test existant). `make openapi` : les codes retirés disparaissent de la documentation ; le front tolère déjà un `blockedBy` inconnu.
7. **Conversions (D4)** : `ConversionLoader::list` ne rend que les conversions **générales** et celles des ingrédients **du lecteur** (le champ `creatorEmail` reste, toujours le lecteur ou vide) ; `GET /api/v1/conversions/{id}` d'une conversion d'un autre : `404` ; `CreateConversionCommand` et `UpdateConversionCommand` gagnent l'identifiant de l'utilisateur et leurs handlers refusent (`404`) un ingrédient qui n'est pas à lui ; les routes restent `#[IsGranted('ROLE_ADMIN')]`.
8. **Tests** : `IngredientBuilder` et ses usages (77 fichiers) : un ingrédient de test naît **chez un utilisateur** (par défaut un utilisateur de test partagé par le scénario), plus jamais « sans créateur » ; les tests qui supposaient « un commun visible de tous » créent un ingrédient par compte ou passent par l'amorçage.

## Hors portée

Supprimer le code mort restant, les commandes et le script d'ING-003, les fixtures, le garde-fou et la migration-garde (ING-014) ; le front (ING-015) ; retirer `canEdit` ou `creatorId` du contrat ; un accès de support de l'administrateur aux ingrédients des autres.

## Critères d'acceptation

- Étant donné la base après la passation (jeu de test reproduisant la production : propriétaire avec ses 261, un compte migré, un compte neuf amorcé), quand chacun liste le rayon, cherche « tomate », choisit un ingrédient pour une recette : **chacun ne voit que les siens**, le propriétaire exactement les mêmes qu'avant ; aucun ingrédient n'apparaît deux fois.
- Le propriétaire modifie, supprime (si inutilisé), fusionne un de **ses** ingrédients ; `PUT`/`DELETE` sur l'ingrédient d'un autre : `403` (voter) ; `GET` de la fiche d'un autre : `404`.
- Un administrateur qui n'est pas le propriétaire se comporte comme un utilisateur ordinaire pour les ingrédients.
- Étant donné un ancien slug (`tomate`) reçu par un compte migré : l'écriture atterrit sur sa copie (alias) ; reçu par un compte qui n'a pas d'alias pour ce slug : `404` ou « ingrédient inconnu », **aucune** adoption, aucune ligne sur l'ingrédient d'un autre.
- Étant donné la copie de la recette du propriétaire par un compte amorcé : ses lignes se branchent sur les copies du copieur par l'alias (clé = le slug de l'ingrédient du propriétaire) ; un ingrédient que le copieur n'a pas est copié, avec son origine, sans être compté comme créé.
- Étant donné la liste des conversions de l'administrateur : générales + les siennes ; aucune de celles des autres comptes ; la création d'une conversion sur l'ingrédient d'un autre : `404`.
- Les budgets d'écriture des use cases qui lisaient les alias et les communs baissent ; aucun ne monte ; `make sql-budget-update` est dans le commit.
- `IngredientVisibilityTest` : une seule définition de la visibilité ; tous les chargeurs et dépôts la partagent (comparés).

## Tests exigés

Tout ce qui précède sur base réelle (`OutputAdapter`) ; `OwnerVoterTest` (administrateur et propriétaire, ingrédient d'un autre et le sien) ; handlers en mémoire (résolveur, copie de recette : provenance par alias, ingrédient absent, retenue de l'origine, événement de statistiques) ; controllers (clés JSON inchangées, codes) ; parcours avec les vrais bus (recette → liste → choix du drive pour un compte amorcé ; copie d'une recette du propriétaire) ; `MergePreviewMatchesTheMergeTest` et la suite de la fusion ; `IngredientReferencesAreAllRepointedTest` ; `make test quality openapi-check`.

## Definition of Done

`repas-dev` § 4 ; `CLAUDE.md` et skill `repas-dev` mis à jour dans le même commit (plus de « un utilisateur sans alias garde les communs partagés », plus d'adoptant) ; roadmap cochée ; PR « À relire » : D3, D4, D7 et leurs effets visibles (l'administrateur ne modifie plus les ingrédients des autres ; la statistique « ingrédients créés » baisse pour les copies de recette).

## Pièges

- **Ne pas fusionner avant l'étape 12** : sur la base d'avant la passation, ce code rend le propriétaire aveugle (ING-014 pose une migration-garde qui l'empêche de démarrer ; ne pas la contourner).
- Ne pas casser le **retour arrière du code** : aucune migration dans ce ticket.
- Le résolveur garde son rôle **d'anciens slugs** (onglets, brouillons du drive, fusion) : ne pas l'enlever avec l'adoption.
- Le drive ne change pas : `IngredientAliasResolver` (port `Shared`) continue de résoudre, rien d'autre.
- `OwnerVoter` : ne pas retirer le passe-droit des autres attributs (recettes, listes) ; seul `INGREDIENT_OWNER` change.
- La statistique : `UpdateUserStatisticsCommand` compte déjà « créés = sans origine » : cohérent avec D7.
- **Fiche lue par un ancien slug** (onglet ouvert avant la migration d'ING-003, adopté depuis) : `GET /ingredients/{ancien slug}` répond `404` après ce ticket, alors qu'ING-002 la laissait lisible en lecture seule. C'est acceptable (écran périmé, le rayon se recharge) ; si l'on veut le tolérer, résoudre le slug par les alias en lecture, dans un ticket à part.
