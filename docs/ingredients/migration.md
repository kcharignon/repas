# Migration des ingrédients communs : une copie par utilisateur concerné

Plan de la migration de données (ticket [ING-003](tickets/ING-003-migration-de-donnees.md)), qui s'appuie sur le socle d'[ING-001](tickets/ING-001-socle-adoption.md) et d'[ING-002](tickets/ING-002-visibilite-et-resolution.md). Décisions : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décisions a à e. Inventaire : [`analyse.md`](analyse.md). **Aucune donnée de production n'est supprimée, jamais.**

Mots employés : **adopter** un commun pour un utilisateur = lui en faire une **copie à lui** (modifiable) et noter la correspondance ; **alias** = la ligne de la table `ingredient_alias` qui dit « pour cet utilisateur, le commun `C` est son ingrédient `X` » ; **adoptant** = un utilisateur qui a au moins un alias d'adoption.

## 1. Ce que fait la migration, en une phrase

Pour chaque utilisateur **concerné**, elle crée une copie de **chacun des communs** (avec ses conversions propres, ses unités, son rayon, son image), note la correspondance, puis **repointe tout ce qui référence un commun** (lignes de ses recettes, lignes et ingrédients de ses listes de courses, choix du drive) vers sa copie, dans une seule transaction par utilisateur. Les communs, eux, ne bougent pas.

## 2. Le périmètre : qui est concerné

Un utilisateur est **concerné** si, au moment du lancement :

- il possède **au moins un ingrédient personnel** (`ingredient.creator = son identifiant`) ; **ou**
- il possède **au moins une recette dans son livre de recettes** (`recipe.author = son identifiant`) : les recettes qu'il a écrites **et** celles qu'il a copiées comptent (une copie a pour auteur le copieur) ;

**et** il n'est **pas administrateur** (`user.roles` contient `ROLE_ADMIN`) : un administrateur gère les communs, ses recettes continuent de pointer vers eux et ils sont déjà « à lui » ([ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision a). Son compte n'a donc ni copie ni alias.

Ne sont **pas** concernés, et ne reçoivent **rien** (ni copie, ni alias, ni repointage, ni modification de version) : un utilisateur **sans recette et sans ingrédient personnel**, même s'il a des listes de courses ou des choix du drive (il continue de voir et d'utiliser les communs partagés, qui existent toujours) ; un administrateur.

Le **statut** du compte (actif, en attente, désactivé) ne change pas le périmètre : un compte désactivé qui a des recettes est concerné, ce qui lui évite un état mixte s'il revient. Le rapport à blanc affiche le statut de chaque utilisateur ; on peut en exclure un par `--except`.

Ce qu'on voit ensuite par rapport à aujourd'hui, pour un utilisateur concerné : il ne voit plus que **ses** ingrédients (les communs adoptés sont masqués pour lui, [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision b) ; chaque ingrédient est modifiable (nom, rayon, unités, coefficient, suppression si inutilisé) ; ses recettes, listes et choix du drive sont identiques à l'écran (mêmes noms, rayons, quantités, unités, mêmes produits mémorisés). Pour un utilisateur non concerné : strictement rien ne change.

## 3. Ce que la migration copie, par commun

Pour un commun `C` et un utilisateur `U` :

| Colonne de `ingredient` | Valeur de la copie |
|---|---|
| `slug` | `C.slugged_name` suivi de l'identifiant de `U` (le motif des ingrédients personnels existants) ; en cas de collision, voir § 5 |
| `name`, `slugged_name`, `image` | ceux de `C` (le chemin d'image est partagé : aucun fichier n'est copié) |
| `department`, `default_cooking_unit`, `default_purchase_unit`, `compatible_units` | ceux de `C` |
| `creator` | `U` |
| `adopted_from` (nouvelle colonne, nullable) | `C.slug` : « cet ingrédient est né de l'adoption de ce commun » |

Pour chaque **conversion propre** de `C` (`conversion.ingredient = C.slug`) : une conversion identique (`start_unit`, `end_unit`, `coefficient`) avec un **nouvel identifiant** et `ingredient = copie`. Les conversions **communes** (`ingredient` vide : g/kg, litre/ml…) **ne sont pas copiées** : elles restent partagées. Ainsi le graphe de conversion de la copie est **exactement** celui du commun, et `compatible_units` reste exact.

La correspondance est écrite dans `ingredient_alias` (`user_id = U`, `alias_slug = C.slug`, `ingredient_slug = copie`, `kind = 'adoption'`, `source = <identifiant du lancement>`). C'est la **table de correspondance** de la décision d.

## 4. Ce que la migration repointe

Pour `U`, **dans la même transaction que la copie** :

| Table | Lignes concernées | Mise à jour |
|---|---|---|
| `recipe_row` | lignes des recettes dont `recipe.author = U` et dont `ingredient` est un alias d'adoption de `U` | `ingredient = copie` ; `recipe.version = version + 1` pour chaque recette modifiée (un `If-Match` périmé répondra `412`, [ADR 0016](../adr/0016-controle-de-version-etag-if-match.md)) |
| `shopping_list_row` | lignes des listes dont `shopping_list.owner = U` | `ingredient = copie` ; `shopping_list.version + 1` |
| `shopping_list_ingredient` | idem | `ingredient = copie` ; idem |
| `drive_choice` | choix de `U` (toutes enseignes, tous magasins), `ingredient_slug` alias d'adoption | `ingredient_slug = copie` ; `updated_at` inchangé. `drive_choice_product` ne bouge pas (rattaché à l'identifiant du choix). `search_query` et `kind` ne bougent pas |
| `drive_department_rayon` | rien | clé par rayon de Repas, pas par ingrédient |

Le repointage est **1 pour 1** (chaque commun a une seule copie par utilisateur) : aucune ligne ne fusionne avec une autre, aucune quantité n'est recalculée, aucune clé unique ne peut être violée. Dans une liste, « une ligne par ingrédient » est conservée.

Les **listes terminées** qui n'auraient pas encore été supprimées (`RemoveShoppingListCompletedEventListener`) et les **listes en pause** sont repointées comme les listes actives : le repointage se fait par propriétaire, pas par statut.

### Les listes envoyées au drive (`SENT`) : une exception, et pourquoi elle est justifiée

Une liste `SENT` est **immuable côté API** ([ADR 0023](../adr/0023-liste-envoyee-au-drive.md)) : `assertEditable()` y refuse toute écriture. La migration est une opération de données, hors API, **et elle doit les repointer** :

- le plan d'envoi cherche le choix par `(utilisateur, enseigne, magasin, slug de la ligne)` ([`analyse.md`](analyse.md) § 11). On repointe `drive_choice` ; **si la liste `SENT` gardait l'ancien slug, ses lignes perdraient leurs produits mémorisés** : le plan, l'estimation du coût et la reprise d'un envoi se comporteraient comme si les ingrédients étaient nouveaux ;
- le repointage ne change **ni le contenu commandé (nom, quantité, unité), ni le statut, ni `sent_at`** : le relevé de commande reste le même. Seule la clé d'ingrédient change, vers un ingrédient de même nom, même rayon, mêmes unités ;
- `shopping_list.version` monte comme pour une liste modifiée (un client qui avait lu la liste le saura).

Garde-fous : le lancement **s'arrête** s'il existe une liste `SENT` d'un utilisateur concerné, sauf `--accept-sent` (un envoi peut être en cours sur le téléphone : on le laisse finir, ou on rouvre la liste, d'abord) ; et l'écriture des choix du drive tolère un ancien slug (alias, ING-002) pour qu'un envoi déjà parti sur le téléphone ne recrée pas de choix sous un commun masqué.

## 5. Slugs et collisions

Le slug de la copie est `C.slugged_name` + `U` : c'est exactement ce que `Ingredient::create()` produirait (`slugify(nom . créateur)` = `slugged_name` + identifiant, l'identifiant étant déjà un slug valide). **Longueur** : 24 caractères au plus pour les communs de production + 36 = 60, loin de 255 ; le rapport à blanc vérifie `longueur <= 255` pour tout le catalogue.

**Collision** : l'utilisateur possède déjà un ingrédient de ce slug, donc de **même nom** (en production : 5 cas pour tous les comptes, dont 1 dans le même rayon que le commun). On **ne fusionne jamais automatiquement** (l'unité ou le rayon peuvent différer, un repointage vers lui casserait des conversions) : la copie prend le slug `C.slugged_name` + `-2` + `U` (puis `-3`…), tel que le premier libre. Le résultat est un **doublon de nom** pour l'utilisateur (« Tomate » personnel et « Tomate » adopté), qui est exactement ce que l'écran « Doublons possibles » ([`fusion.md`](fusion.md) § 8) propose de fusionner, avec la règle « même nom, même rayon ».

Le slug `tomate-2<U>` est aussi celui qu'aurait un ingrédient que l'utilisateur nommerait « Tomate 2 » : le contrôle d'existence (`IngredientRepository::exists`, `CreateIngredientHandler`) tranche dans les deux sens (`409` à la création, jamais d'écrasement).

**Renommage** : le slug ne dépend plus du nom après la création (c'est déjà le cas). Renommer une copie ne change ni son slug ni son alias.

**Unicité globale** : la clé primaire garantit qu'aucun slug n'est partagé entre deux utilisateurs (l'identifiant est dans le slug).

## 6. La commande, ses modes, son rapport

`php bin/console repas:ingredients:adopt-commons` (contexte `Repas`, `src/Command/`, qui ne contient que de la boucle et de l'affichage : le travail est une commande `AdoptCommonIngredientsForUser` dispatchée par le bus, donc transactionnelle, testée en unitaire ; la lecture à blanc passe par un Loader).

| Option | Effet |
|---|---|
| (aucune) | **À blanc**, mode par défaut : lecture seule (aucun `INSERT`, `UPDATE`, `DELETE`, aucune transaction qui écrit), imprime le rapport |
| `--apply` | écrit, **une transaction par utilisateur**, imprime le rapport |
| `--user=<id>` (répétable) | se limite à ces utilisateurs (essai sur un seul compte d'abord) |
| `--except=<id>` (répétable) | exclut ces utilisateurs |
| `--accept-sent` | autorise le lancement malgré des listes `SENT` d'utilisateurs concernés |
| `--check` | lecture seule, **vérifications** du § 8 seulement (à lancer après `--apply`) |
| `--undo=<identifiant du lancement>` | retour arrière des **données** (§ 10), `--apply` requis |
| `--run=<identifiant>` | nom du lancement écrit dans `ingredient_alias.source` (défaut : `migration-AAAA-MM-JJ`) |

**Rapport**, par utilisateur (jamais d'adresse e-mail : l'identifiant suffit ; l'ops script affiche l'adresse en la lisant à part) : statut ; raison d'être concerné (ingrédients personnels : n ; recettes : n) ; **ou** raison de l'exclusion ; communs à adopter / déjà adoptés ; collisions de slug (nombre, puis détail) ; conversions à copier ; lignes à repointer par table (recettes distinctes, listes distinctes dont `SENT`, choix du drive) ; anomalies (§ 8). **Totaux** en fin de rapport, et la phrase « rien n'a été modifié » en mode à blanc.

## 7. L'algorithme par utilisateur (transaction `U`)

0. Verrou : `pg_advisory_xact_lock(hashtext('adopt:' || U))` (deux lancements ne se croisent pas) ; relire les critères de périmètre **dans** la transaction.
1. `A` = communs (`ingredient.creator IS NULL`) sans alias d'adoption pour `U`.
2. Slugs cibles calculés en PHP (§ 5), en une lecture des slugs déjà pris (`WHERE slug LIKE '%' || U`).
3. `INSERT INTO ingredient` en lots de 100 (`adopted_from` renseigné), `INSERT INTO conversion` (identifiants générés par `UuidGenerator`), `INSERT INTO ingredient_alias`.
4. Repointage (§ 4) par `UPDATE … FROM ingredient_alias a WHERE a.user_id = U AND a.kind = 'adoption' AND a.alias_slug = <colonne>` ; les versions des recettes et des listes touchées montent par `WITH changed AS (UPDATE … RETURNING recipe) UPDATE recipe SET version = version + 1 …`.
5. Vérifications du § 8 **dans la transaction** ; à la moindre non-conformité : `ROLLBACK` de cet utilisateur, message, on passe au suivant (les autres utilisateurs ne sont pas touchés).
6. `COMMIT`. **Aucun événement** n'est émis : une adoption n'est pas une création, les statistiques de l'utilisateur ne bougent pas.

Le nombre de requêtes est **constant** par utilisateur (quelques `INSERT … SELECT`/`VALUES` par lots et quatre `UPDATE`), pas proportionnel au nombre de lignes.

### Idempotence

Rejouable sans doublon : l'étape 1 exclut les communs déjà aliasés ; l'étape 4 ne touche que les lignes qui référencent encore un commun aliasé (après le premier passage il n'y en a plus) ; une transaction interrompue n'a rien écrit. Rejouer sert aussi à **rattraper** une écriture concurrente (§ 9). Le deuxième passage d'un lancement complet imprime « 0 à adopter, 0 à repointer » pour tous.

### Utilisateur concerné plus tard

Un utilisateur qui n'était pas concerné au premier lancement (premier recette créée depuis) le devient au lancement suivant : même commande. Il n'y a pas de limite de temps.

## 8. Vérifications avant et après

**Avant (à blanc, aussi rejouées dans la transaction)** :

| Contrôle | Attendu |
|---|---|
| Aucune référence orpheline : `recipe_row`, `shopping_list_row`, `shopping_list_ingredient`, `drive_choice` d'un utilisateur concerné dont l'ingrédient n'existe pas | 0 (sinon anomalie signalée ; l'orphelin n'est **pas** touché) |
| Lignes d'un utilisateur concerné qui référencent l'ingrédient personnel **d'un autre** | 0, sinon anomalie (donnée héritée d'avant l'ADR 0021) |
| Longueur de chaque slug cible | `<= 255` |
| Collisions de slug | listées |
| Conversions dont l'ingrédient n'existe pas | 0, sinon anomalie |

**Après** (`--check`, et en fin de chaque transaction) :

| Contrôle | Attendu |
|---|---|
| Nombre de lignes par table pour `U` (`recipe_row` de ses recettes, `shopping_list_row`, `shopping_list_ingredient`, `drive_choice`, `drive_choice_product`) | **identique** à avant |
| Somme des quantités par table | **identique** |
| Empreinte par liste et par recette : hachage de l'ensemble `(nom de l'ingrédient, rayon, quantité, unité)` trié | **identique** (on compare des noms, pas des slugs) |
| Références de `U` vers un commun aliasé | **0** |
| Références orphelines | **0** |
| Chaque alias : la copie existe, `creator = U`, `adopted_from = alias_slug`, mêmes `department` et unités que le commun, même nombre de conversions propres | oui |
| `drive_choice` de `U` sans copie correspondante | 0 ; nombre de choix et de produits mémorisés identique ; aucun choix sous un slug de commun aliasé |
| Versions | chaque recette et chaque liste modifiée a monté de 1, aucune autre |
| Statistiques `user.statistics.ingredients` | inchangées |
| Utilisateurs non concernés | aucune ligne modifiée (comptage des lignes dont l'ingrédient a changé : 0) |

**Test d'équivalence du drive** (avant la mise en production, sur la base de test) : pour un utilisateur avec des choix, des listes envoyées et non envoyées, la lecture du plan (`GetDrivePlan`) et de l'estimation (`GetDriveEstimate`) donne, avant et après la migration, **les mêmes lignes à l'ingrédient près (nom, quantité, unité, produits, nombre de produits à commander, prix)** ; seul le slug de chaque ligne change.

## 9. Concurrence

Le site reste en ligne pendant le lancement (quelques secondes). Une écriture concurrente de l'utilisateur concerné : ses `UPDATE` de lignes sont des verrous de ligne, qui attendent ou font attendre ; une écriture de recette versionnée échoue en `409` si la recette a bougé (ADR 0016) et le client recommence. Le seul cas qui peut laisser une trace est une écriture qui lit les alias **avant** le `COMMIT` et pose une ligne sur un commun juste **après** : il reste alors une référence vers un commun aliasé, que `--check` signale et qu'un deuxième `--apply` repointe (idempotence). Lancer pendant un créneau calme (le site est utilisé par quelques proches, la nuit suffit).

## 10. Retour arrière

- **Du code** : sans danger, voir § 11.
- **Des données** : la sauvegarde `pg_dump` d'avant lancement (§ 13) est le filet ; sa restauration est une **décision humaine** ([ADR 0017](../adr/0017-deploiement-et-releases.md) règle 6).
- **Annulation ciblée** : `--undo=<lancement> --apply` repointe vers le commun d'origine les lignes de l'utilisateur qui référencent encore une copie du lancement (`ingredient_alias.source`), supprime les alias de ce lancement, **mais laisse les copies d'ingrédients et de conversions en place** (jamais de suppression de donnée de production par ce chemin ; elles sont inertes, et l'ancien code les voit comme des ingrédients personnels ordinaires). Les copies qui ont été **modifiées, renommées ou utilisées depuis** par l'utilisateur ne sont pas défaites : le rapport les liste. C'est un outil de dernier recours, testé sur la base de test.

## 11. Compatibilité avec la version précédente du code (ADR 0017 règle 6)

Vérifiée dans le code de `main` (87e31ea) : **l'ancien code lit sans erreur les données que la migration produit.**

| Ce que la migration écrit | Comment l'ancien code le traite |
|---|---|
| Des ingrédients avec `creator = U` | C'est exactement le cas d'un ingrédient personnel : visible et modifiable par `U` (`IngredientPostgreSQLLoader:53`, `:223`), utilisable (`findPurchaseUnitSlugUsableBy` `IngredientPostgreSQLRepository:77`), contrôlé par `Recipe::assertIngredientIsAvailable` (`Recipe.php:238`) |
| Une nouvelle colonne `ingredient.adopted_from` nullable | `IngredientPostgreSQLRepository::updateEntity` (`:138`) ne copie que les colonnes qu'il connaît ; Doctrine n'écrit que les colonnes mappées : l'ancien code ne la lit ni ne l'efface |
| Une nouvelle table `ingredient_alias` (et `ingredient_distinct`) | inconnue de l'ancien code, qui ne la lit ni ne l'écrit |
| Des lignes de recettes, de listes et de choix qui pointent vers ces copies | l'ancien code les lit par jointure sur `slug` sans regarder le créateur ([`analyse.md`](analyse.md) § 5) ; le plan du drive les lit par slug ([`analyse.md`](analyse.md) § 11) |
| Des versions de recettes et de listes incrémentées | prévu par l'ADR 0016 |

**Dégradations acceptées avec l'ancien code** (visibles, jamais bloquantes) : un utilisateur concerné verrait **chaque ingrédient en double** dans les rayons, la recherche et le sélecteur de recette (le commun partagé et sa copie : le masquage est dans le nouveau code) ; copier une recette d'un utilisateur concerné crée des copies d'ingrédients superflues (l'ancien `CopyRecipeHandler` copie tout ingrédient personnel) ; supprimer une copie décrémente le compteur « ingrédients créés » (l'ancien code ne distingue pas les adoptions) ; **supprimer un compte avec l'ancien code laisse des lignes `ingredient_alias` orphelines** (le contrôle `UserOwnedDataCoverageTest` et la purge arrivent avec ING-001) : requête de nettoyage dans le ticket ING-010 (`DELETE FROM ingredient_alias WHERE user_id NOT IN (SELECT id FROM "user")`). Aucune de ces dégradations ne fait échouer une requête.

Donc : la migration de schéma est **additive** (colonne nullable, deux tables), l'ancien code l'ignore ; la migration de données est **compatible** avec lui.

## 12. Ordre de déploiement (ADR 0017 : le back d'abord)

1. **Back A** (ING-001 + ING-002) : schéma additif, services d'adoption et d'alias, masquage des communs adoptés, résolution des anciens slugs. **Sans donnée, aucun comportement ne change** (aucun alias : rien n'est masqué). Déployé par une release ordinaire (sauvegarde `deploy.sh`, contrôle de santé, retour arrière du code possible).
2. **Observation** : le site tourne sur la version A quelques heures.
3. **Migration de données** (ING-003), lancée par l'humain avec le script d'exploitation : à blanc, relecture du rapport avec l'assistant, sauvegarde, `--apply`, `--check`. **Jamais le même jour** qu'une autre release du back.
4. **Back B** (ING-004 rapprochement, ING-005 et ING-006 fusion et doublons) : peut partir avant ou après l'étape 3. Recommandé après : la copie de recette trouve des adoptants.
5. **Front** (ING-007) en dernier, quand l'API de fusion est en production (le front ne doit pas appeler une route absente) ; il ne change rien d'autre : les écrans existants fonctionnent avant comme après la migration.

## 13. Exploitation : script, sauvegarde, durée

- **Script** `.claude/skills/repas-ops/references/adopter-ingredients.sh`, sur le modèle de `rouvrir-listes-envoyees.sh` : sans argument, **lecture seule** (rapport à blanc) ; avec `--appliquer`, **sauvegarde `./backup.sh avant-adoption-ingredients`** puis `docker compose exec -T php php bin/console repas:ingredients:adopt-commons --apply --run=…`, puis `--check`. Lancé par l'utilisateur (`!`), jamais par un agent de code ; aucun secret dans le chat ([skill `repas-ops`](../../.claude/skills/repas-ops/SKILL.md)).
- **Sauvegarde** : le déploiement du back A en fait déjà une (`deploy/deploy.sh`) ; la sauvegarde propre au lancement la complète juste avant l'écriture. Elles contiennent des données personnelles et restent sur le serveur ([`../production.md`](../production.md) « Sauvegardes »).
- **Durée estimée** : par utilisateur concerné, une copie de ~260 ingrédients, ~105 conversions et ~260 alias (environ 630 lignes insérées) puis quatre `UPDATE` sur quelques centaines de lignes : de l'ordre de **la seconde**. Avec 5 à 8 utilisateurs concernés, **moins d'une minute** au total. Le verrou le plus long est celui des `UPDATE` d'un utilisateur (millisecondes).
- **Volume ajouté** en base : de l'ordre de **5 000 lignes** pour la production actuelle, négligeable.

## 14. À relever en production avant le lancement (lecture seule)

Requêtes sans donnée personnelle dans le résultat (les identifiants d'utilisateurs ne sont pas affichés ici) ; à exécuter par l'utilisateur, ou à intégrer au mode à blanc :

```sql
-- 1. Les utilisateurs et leur périmètre (statut, administrateur ou non, recettes, ingrédients personnels, listes par statut, choix du drive)
SELECT u.status, (u.roles::text LIKE '%ROLE_ADMIN%') AS admin,
       (SELECT count(*) FROM recipe r WHERE r.author = u.id)                       AS recettes,
       (SELECT count(*) FROM recipe r WHERE r.author = u.id AND r.orignal IS NOT NULL) AS dont_copies,
       (SELECT count(*) FROM ingredient i WHERE i.creator = u.id)                  AS ingredients_perso,
       (SELECT count(*) FROM shopping_list l WHERE l.owner = u.id AND l.status = 'SENT')   AS listes_envoyees,
       (SELECT count(*) FROM shopping_list l WHERE l.owner = u.id)                 AS listes,
       (SELECT count(*) FROM drive_choice c WHERE c.user_id = u.id)                AS choix_drive
  FROM "user" u ORDER BY admin DESC, recettes DESC;

-- 2. Références orphelines (ingrédient inexistant)
SELECT 'recipe_row' AS t, count(*) FROM recipe_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'shopping_list_row', count(*) FROM shopping_list_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'shopping_list_ingredient', count(*) FROM shopping_list_ingredient x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'drive_choice', count(*) FROM drive_choice x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient_slug)
UNION ALL SELECT 'conversion', count(*) FROM conversion x WHERE x.ingredient IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient);

-- 3. Lignes qui référencent l'ingrédient personnel d'un autre utilisateur (donnée héritée)
SELECT 'recipe_row' AS t, count(*) FROM recipe_row rr JOIN recipe r ON r.id = rr.recipe JOIN ingredient i ON i.slug = rr.ingredient
  WHERE i.creator IS NOT NULL AND i.creator <> r.author
UNION ALL SELECT 'shopping_list_row', count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
  WHERE i.creator IS NOT NULL AND i.creator <> l.owner;

-- 4. Références à des communs, tous utilisateurs réunis (taille du repointage)
SELECT 'recettes' AS t, count(*) FROM recipe_row rr JOIN ingredient i ON i.slug = rr.ingredient WHERE i.creator IS NULL
UNION ALL SELECT 'liste (lignes)', count(*) FROM shopping_list_row x JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NULL
UNION ALL SELECT 'liste (ingredients)', count(*) FROM shopping_list_ingredient x JOIN ingredient i ON i.slug = x.ingredient WHERE i.creator IS NULL
UNION ALL SELECT 'drive_choice', count(*) FROM drive_choice x JOIN ingredient i ON i.slug = x.ingredient_slug WHERE i.creator IS NULL;

-- 5. Longueur maximale d'un nom normalisé de commun, collisions de slug avec les ingrédients personnels existants
SELECT max(length(slugged_name)) AS plus_long_nom FROM ingredient WHERE creator IS NULL;
SELECT count(*) AS collisions FROM ingredient p JOIN ingredient c ON c.creator IS NULL AND p.slug = c.slugged_name || p.creator WHERE p.creator IS NOT NULL;

-- 6. Version de PostgreSQL et version du schéma
SHOW server_version;
SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1;
```

La liste de ce qu'il reste à relever, au 2026-10-20, est dans la réponse qui accompagne la PR : nombre de comptes administrateurs et leur rôle exact, listes `SENT` actuelles, références orphelines ou croisées héritées, choix du drive dont l'ingrédient n'existe plus.

## 15. Impact sur le contexte `Drive` et tests qui protègent les choix

Le contexte `Drive` ne référence un ingrédient que par `drive_choice.ingredient_slug` ([`analyse.md`](analyse.md) § 11). Tout le reste (produits, prix, contenance, rayons appris, réglages, limiteurs) est indépendant. Ce que la migration, la résolution d'alias et la fusion changent, et la preuve que les choix survivent :

| Élément du drive | Impact | Protection (test ou règle) |
|---|---|---|
| `drive_choice.ingredient_slug` | repointé du commun vers la copie, **toutes enseignes et tous magasins** de l'utilisateur ; la clé unique `(user_id, retailer, store_id, ingredient_slug)` ne peut pas être violée (le slug de la copie est neuf) | `ChoiceRepositoryTest` + test de la migration : nombre de choix identique, aucun choix sous un slug de commun aliasé, aucune violation de clé |
| `drive_choice_product` (produits acceptés, refusés, rang) | **inchangé** : rattaché à l'identifiant du choix, pas au slug | comptage avant/après dans `--check` |
| `drive_choice.search_query` (DRV-650) et `kind` | inchangés ; le texte cherché par défaut quand il n'y a pas de recherche mémorisée est le **nom** de l'ingrédient, identique dans la copie | test du plan : `searchQuery` identique avant/après |
| `drive_product` (contenance, prix, étiquette) | inchangés : faits publics sans propriétaire | rien à tester de plus |
| Plan d'envoi, nombre de produits (`PackCalculator`) | identiques : la copie garde les **mêmes unités d'achat** (dimension et facteur de base) | **test d'équivalence** : `GetDrivePlan` et `GetDriveEstimate` d'un utilisateur de test (choix, listes envoyées et non envoyées) donnent les mêmes lignes à l'ingrédient près, avant et après |
| Listes `SENT` | repointées avec leurs choix (sinon leurs produits mémorisés disparaissent du plan) ; contenu, statut, `sent_at` inchangés | critères d'ING-003 |
| `drive_department_rayon` (rayon de l'enseigne appris par rayon de Repas) | inchangé ; si l'utilisateur change le rayon de sa copie, l'apprentissage suit le **nouveau** rayon (voulu) | `IngredientDepartmentPostgreSQLResolverTest` |
| Écritures qui reçoivent un slug (`PUT/DELETE /drive/choices/{ingredientSlug}`, lot de choix de l'agent) | un ancien slug (brouillon d'envoi sur le téléphone, onglet ouvert) est **résolu** vers la copie ; jamais de choix créé sous un commun adopté | port `Shared` `IngredientAliasResolver` (ING-002 C) et ses tests ; `Drive` ne dépend que de `Shared` (Deptrac) |
| Fusion | choix de `A` gagnant, celui de `B` supprimé avec ses produits, sinon passe sur `A` ; refus si une liste `SENT` est concernée | critères d'ING-005 |
| Plafonds de débit, interrupteurs | sans objet (aucune route du drive n'est ajoutée) | `DriveRoutesAreRateLimitedTest` inchangé |
| Suppression de compte | `PurgeUserDriveData` inchangé ; les alias sont purgés par `Repas` | `UserOwnedDataCoverageTest` |
| Plusieurs enseignes (ADR 0027) | aucun nom d'enseigne dans le code ajouté ; le repointage et la fusion sont par `(utilisateur, ingrédient)`, quelles que soient les enseignes | revue : aucun nom d'enseigne dans le diff (les registres d'enseignes de l'ADR 0027 restent les seuls endroits permis) |