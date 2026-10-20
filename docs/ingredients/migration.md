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
| `slug` | `IngredientSlugPolicy::forAdoption(C.name, U, …)` : `slugify(nom . U)`, le slug que `Ingredient::create()` produirait (`C.slugged_name` suivi de l'identifiant de `U` pour un nom qui finit par une lettre ou un chiffre) ; en cas de collision, voir § 5 |
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

Le slug de la copie est celui que `Ingredient::create()` produirait : `slugify(nom . U)`, calculé par `IngredientSlugPolicy::forAdoption()` (ING-001, **ING-003 l'appelle, il n'écrit pas sa propre formule en SQL**). Pour un nom qui finit par une lettre ou un chiffre, c'est `C.slugged_name` + `U`, l'identifiant étant déjà un slug valide. Pour un nom qui finit par un symbole (quatre communs du jeu de référence, « couche (taille N) »), `slugify` met un tiret avant l'identifiant (`couche-taille-4-<U>`) alors que `slugged_name` collé n'en met pas (`couche-taille-4<U>`) : seule la formule sur le nom colle au slug qu'aurait l'ingrédient créé à la main, donc seule elle détecte la collision avec un ingrédient personnel de même nom. **Longueur** : 24 caractères au plus pour les communs de production + 36 = 60, loin de 255 ; le rapport à blanc vérifie `longueur <= 255` pour tout le catalogue.

**Collision** : l'utilisateur possède déjà un ingrédient de ce slug, donc de **même nom** (en production : 5 cas pour tous les comptes, dont 1 dans le même rayon que le commun). On **ne fusionne jamais automatiquement** (l'unité ou le rayon peuvent différer, un repointage vers lui casserait des conversions) : la copie prend le slug `-2` (`tomate-2<U>`, c'est-à-dire `slugify(nom . '-2' . U)`), puis `-3`…, le premier libre. Le résultat est un **doublon de nom** pour l'utilisateur (« Tomate » personnel et « Tomate » adopté), qui est exactement ce que l'écran « Doublons possibles » ([`fusion.md`](fusion.md) § 8) propose de fusionner, avec la règle « même nom, même rayon ».

Le slug `tomate-2<U>` est aussi celui qu'aurait un ingrédient que l'utilisateur nommerait « Tomate 2 » : le contrôle d'existence (`IngredientRepository::exists`, `CreateIngredientHandler`) tranche dans les deux sens (`409` à la création, jamais d'écrasement).

**Renommage** : le slug ne dépend plus du nom après la création (c'est déjà le cas). Renommer une copie ne change ni son slug ni son alias.

**Unicité globale** : la clé primaire garantit qu'aucun slug n'est partagé entre deux utilisateurs (l'identifiant est dans le slug).

## 6. La commande, ses modes, son rapport

`php bin/console repas:ingredients:adopt-commons` (contexte `Repas`, `src/Command/AdoptCommonIngredientsCommand.php`, qui ne contient que de la boucle et de l'affichage : le travail d'un compte est la commande applicative `AdoptCommonIngredientsForUser`, dispatchée par le bus, donc transactionnelle, testée en unitaire avec des doubles en mémoire ; le rapport est la requête `GetAdoptionReport`, lue par `IngredientAdoptionReportLoader`, en lecture seule).

| Option | Effet |
|---|---|
| (aucune) | **À blanc**, mode par défaut : lecture seule (aucun `INSERT`, `UPDATE`, `DELETE`, aucun verrou), imprime le rapport complet et « À blanc : rien n'a été modifié ». Code de sortie `0`, ou `1` si une anomalie bloquante interdirait d'écrire |
| `--check` | lecture seule aussi : le même rapport et le **verdict** « Rien à faire : 0 à adopter, 0 à repointer, aucun alias invalide ». Code de sortie `0` si et seulement s'il n'y a plus rien à faire (à lancer après `--apply`), `1` sinon |
| `--apply` | écrit, **une transaction par compte**, imprime une ligne par compte puis le rapport d'après lancement. Refuse d'écrire sans rien modifier : anomalie bloquante, liste envoyée sans `--accept-sent`, environnement autre que `prod` et `dev` (`repas.adoption.environments`), compte inconnu |
| `--accept-sent` | autorise le repointage des listes envoyées au drive des comptes retenus. **Obligatoire** dès qu'une liste `SENT` a une ligne sur un commun ; sans rien à repointer sur une liste envoyée (second lancement), il n'est pas nécessaire |
| `--user=<référence>` (répétable) | se limite à ces comptes (essai sur un seul compte d'abord). La **référence** est celle du rapport (`[a1b2c3d4]` : les 8 premiers caractères du SHA-256 de l'identifiant), ou l'identifiant complet |
| `--except=<référence>` (répétable) | exclut ces comptes, mêmes formes |
| `--source=<nom>` (synonyme `--run`) | nom du lancement écrit dans `ingredient_alias.source` : lettres, chiffres et `. _ : -`, 64 caractères au plus (défaut : `migration-AAAA-MM-JJ`) |
| `--keep-going` | si un compte échoue (transaction annulée pour lui), continue avec les suivants. **Par défaut la commande s'arrête au premier échec** : une empreinte qui diverge peut être un défaut systématique, mieux vaut le lire avant de continuer |
| `--undo=<lancement>` | retour arrière des **données** de ce lancement (§ 10), `--apply` requis |

Un administrateur nommé par `--user` reste **ignoré**. Les options `--apply` et `--check` s'excluent.

**Rapport**, un bloc par compte, **sans adresse e-mail, sans identifiant, sans nom d'ingrédient personnel** : les comptes sont numérotés (`n°1`…, par ordre d'identifiant) et désignés par leur référence courte. Pour chaque compte : le statut ; ce qu'il possède (ingrédients personnels, copies d'adoption, recettes dont copiées) ; **retenu** ou **ignoré** et pourquoi (administrateur ; ni ingrédient personnel ni recette ; écarté par `--except` ; hors de `--user`). Pour un compte retenu : communs à adopter / déjà adoptés ; collisions de slug (le nombre, puis le **nom du commun** concerné, public) ; conversions propres à copier ; lignes à repointer par table (recettes, lignes et ingrédients de listes dont celles **envoyées au drive**, choix du drive et leurs produits) ; l'**empreinte du contenu** (12 caractères, voir § 8) ; les anomalies et les alias invalides. En tête : le catalogue (communs, conversions propres, slug le plus long) ; en pied : les **totaux** et le verdict.

## 7. L'algorithme par utilisateur (transaction `U`)

0. Verrou : `pg_advisory_xact_lock(hashtext('adopt:' || U))` (deux lancements ne se croisent pas) ; relire les critères de périmètre **dans** la transaction (compte inconnu, administrateur, ni ingrédient ni recette : rien n'est fait).
1. **Empreinte d'avant** (§ 8), côté `Repas` et côté drive. Les listes `SENT` qui auraient une ligne sur un commun exigent `--accept-sent` : sinon la commande s'arrête **avant** d'écrire.
2. `A` = communs (`ingredient.creator IS NULL`) **sans adoption valide** pour `U` (alias d'adoption dont la copie existe et est à `U`). Un alias **orphelin** (copie disparue) est remplacé sur place, jamais supprimé. Un ingrédient de `U` déjà né du commun (`adopted_from`), strictement conforme à lui (nom, image, rayon, unités, unités compatibles, conversions propres) et sans alias, est **désigné** par l'alias au lieu d'être doublé : c'est ce qui rend « retour arrière puis nouveau lancement » sans doublon.
3. Slugs cibles calculés en PHP par `IngredientSlugPolicy` (§ 5), en **une** lecture des slugs déjà pris (ceux qui se terminent par l'identifiant de `U`).
4. Les **alias d'abord** (`INSERT … ON CONFLICT … RETURNING` : ce qui est rendu est ce qui a été réellement écrit, une adoption simultanée l'emporte sur la clé de l'alias), puis les copies d'ingrédients (`INSERT … SELECT` depuis le commun, `adopted_from` renseigné), puis les conversions **propres** (identifiants neufs par `UuidGenerator`, unités et coefficient recopiés par la base, aucun aller-retour de flottant). Un `INSERT … SELECT` par lot : la taille du catalogue n'a pas d'effet sur le nombre de requêtes.
5. Repointage (§ 4) par `UPDATE … FROM <adoptions valides de U>` ; les versions des recettes et des listes touchées montent dans la **même** requête (`WITH changed AS (UPDATE … RETURNING recipe) UPDATE recipe SET version = version + 1 …`). Recettes et listes ensemble, puis les choix du drive par le port `Shared` `DriveIngredientReferences`, **implémenté par `Drive`** (un contexte n'écrit pas les tables d'un autre, ADR 0013 règle 8 ; `Drive` garde la main sur sa clé unique et ses produits).
6. **Empreinte d'après** comparée à celle d'avant (§ 8), dans la transaction ; à la moindre différence : `IngredientAdoptionException`, donc **ROLLBACK de ce compte**, message clair, et **arrêt** de la commande (`--keep-going` pour continuer avec les suivants : les autres comptes ne sont pas touchés).
7. `COMMIT`. **Aucun événement** n'est émis : une adoption n'est pas une création, les statistiques de l'utilisateur ne bougent pas.

Le nombre de requêtes est **constant** par utilisateur : **26** mesurées, pour 6 lignes comme pour 8 000 lignes à repointer, pour 1 comme pour 5 comptes (`tests/Repas/WriteBudget`, scénario `adopter-catalogue`, et `AdoptionPerformanceTest`). Environ 10 000 lignes (recettes, listes dont deux envoyées, 100 choix du drive avec leurs produits) se migrent en **moins d'une seconde** par compte sur la base de développement.

### Idempotence

Rejouable sans doublon : l'étape 2 exclut les communs déjà adoptés ; l'étape 5 ne touche que les lignes qui référencent encore un commun adopté (après le premier passage il n'y en a plus) ; une transaction interrompue n'a rien écrit. Rejouer sert aussi à **rattraper** une écriture concurrente (§ 9). Le deuxième passage d'un lancement complet imprime « 0 à adopter, 0 à repointer, rien à faire » pour tous les comptes **sans ouvrir de transaction**, et n'écrit aucune ligne, versions comprises (prouvé par une somme de contrôle de toutes les tables avant et après, `AdoptCommonIngredientsCommandTest`). Un commun que l'utilisateur aurait **supprimé** depuis (sa copie et son alias disparaissent, ING-001) serait de nouveau adopté par un rejeu : le rapport à blanc le montre (« à adopter »).

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

**Ce qui est réellement vérifié, et où** (réalisation d'ING-003) :

- **À blanc et `--check`** (`IngredientAdoptionReportPostgreSQLLoader`, lecture seule) : références orphelines et références à l'ingrédient personnel d'un autre compte, **par table et pour chaque compte retenu** (lignes de recettes, de listes, ingrédients de listes, choix du drive) ; slugs de copie de plus de 255 caractères ; alias d'adoption invalides (copie absente, à un autre compte, d'un autre commun ; sauf un alias que la fusion de deux ingrédients, ING-005, a déplacé sur l'ingrédient gardé : il porte `source = 'merge'` et seule sa copie compte, `AdoptionAfterMergeTest`) ; conversions rattachées à un ingrédient qui n'existe pas (anomalie du catalogue, sans effet sur la migration). Les premières interdisent `--apply` (aucune écriture) ; la dernière est seulement affichée.
- **Dans la transaction de chaque compte** (`AdoptionVerifier`, comparaison de deux `AdoptionSnapshot` et de deux `DriveChoiceFingerprint`) : nombre de lignes et somme **exacte** des quantités (`numeric`, indépendante de l'ordre des lignes) pour `recipe_row`, `shopping_list_row` et `shopping_list_ingredient` ; somme de contrôle du **contenu de chaque recette et de chaque liste** (ensemble trié des `(nom de l'ingrédient, rayon, quantité, unité)`, jamais le slug) ; **situation de chaque liste** (statut, date d'envoi) ; **versions** : +1 pour chaque recette et liste dont une ligne a été repointée, inchangée pour toutes les autres ; `user.statistics` identique ; plus **aucune** ligne sur un commun ; côté drive : mêmes nombres de choix et de lignes de produits, même somme des contenances (`pack_size_value`), même somme de contrôle de tout ce qui n'est pas le slug (enseigne, magasin, nature, recherche mémorisée `search_query`, date de mise à jour, et pour chaque produit son identifiant, son verdict, son rang, sa contenance et son unité), et **chaque choix sur l'ingrédient attendu** (sa copie, ou le même slug s'il n'était pas un commun) ; aucun choix ne garde le slug d'un commun adopté ; chaque copie écrite par ce passage est **conforme** à son commun (créateur, `adopted_from`, nom, image, rayon, unités par défaut, unités compatibles, conversions propres identiques) ; plus aucun commun sans adoption valide.
- **Après le lancement** : la commande relit le rapport (`--check`), compare l'**empreinte du contenu** de chaque compte à celle du rapport d'avant (elle ne dépend d'aucun slug : la même valeur avant et après) et dit « Rien à faire ».
- **Comptes non concernés** : les `UPDATE` ne touchent que les recettes dont le compte est l'**auteur** et les listes dont il est le **propriétaire** ; l'administrateur et le compte sans recette ni ingrédient reçoivent zéro ligne (prouvé par une somme de contrôle de tout ce qui leur appartient, versions comprises). Les communs ne sont jamais écrits (somme de contrôle des communs et de leurs conversions identique après).
- **Exhaustivité des références** : `IngredientReferencesAreAllRepointedTest` lit le schéma réel (`information_schema`) et échoue pour toute colonne dont le nom évoque un ingrédient ou un slug, ou toute colonne JSON, qui ne serait pas déclarée (repointée, ou écartée avec sa raison) ; il lit aussi les **valeurs** d'un jeu de données complet : seules les colonnes déclarées contiennent le slug d'un commun.

**Test d'équivalence du drive** (`AdoptionDriveEquivalenceTest`, base de test, ingrédients et unités construits par le domaine) : pour un utilisateur avec des choix (rangs, refus, contenance, recherche mémorisée, prix), une liste envoyée et une liste active, la lecture du plan (`GetDrivePlan`) et de l'estimation (`GetDriveEstimate`) donne, avant et après la migration, **les mêmes lignes à l'ingrédient près (nom, quantité, unité, produits, nombre de produits à commander, prix)** ; seul le slug de chaque ligne change, et c'est celui de la copie.

## 9. Concurrence

Le site reste en ligne pendant le lancement (quelques secondes). Une écriture concurrente de l'utilisateur concerné : ses `UPDATE` de lignes sont des verrous de ligne, qui attendent ou font attendre ; une écriture de recette versionnée échoue en `409` si la recette a bougé (ADR 0016) et le client recommence. Le seul cas qui peut laisser une trace est une écriture qui lit les alias **avant** le `COMMIT` et pose une ligne sur un commun juste **après** : il reste alors une référence vers un commun aliasé, que `--check` signale et qu'un deuxième `--apply` repointe (idempotence). Lancer pendant un créneau calme (le site est utilisé par quelques proches, la nuit suffit).

## 10. Retour arrière

- **Du code** : sans danger, voir § 11.
- **Des données** : la sauvegarde `pg_dump` d'avant lancement (§ 13) est le filet ; sa restauration est une **décision humaine** ([ADR 0017](../adr/0017-deploiement-et-releases.md) règle 6).
- **Annulation ciblée** : `--undo=<lancement> --apply` repointe vers le commun d'origine les lignes de l'utilisateur qui référencent encore une copie du lancement (`ingredient_alias.source`) — recettes, listes (y compris envoyées, avec `--accept-sent`), choix du drive —, supprime les alias de ce lancement, **mais laisse les copies d'ingrédients et de conversions en place** (jamais de suppression de donnée de production par ce chemin ; elles sont inertes, et l'ancien code les voit comme des ingrédients personnels ordinaires). Même transaction par compte, mêmes empreintes que l'adoption (contenu inchangé, versions +1, choix du drive complets). Une copie **modifiée depuis** par l'utilisateur (nom, image, rayon, unités, unités compatibles, conversions propres) n'est pas défaite : elle porte son travail, son alias reste, et la commande dit « N copie(s) modifiée(s) depuis laissée(s) telle(s) quelle(s) ». Un nouveau `--apply` après un `--undo` **désigne** les copies restées en place au lieu de les doubler (§ 7, étape 2). Outil de dernier recours, testé (`AdoptCommonIngredientsFailureAndUndoTest`).
- **Les mêmes données, en SQL, sans la commande** (le cas où la commande elle-même ne démarre plus). Lancée par l'humain, **après une sauvegarde**, en **une seule transaction** (`--single-transaction`), pour **tous** les comptes d'un lancement. Elle ne supprime que les alias du lancement, ne regarde pas si une copie a été modifiée (préférer `--undo` si c'est possible) et fait ce que fait `--undo` : repointe vers les communs les recettes, les listes (versions +1) et les choix du drive qui portent encore une copie du lancement. Le texte ci-dessous est celui que `AdoptionDocumentedSqlTest` exécute sur la base de test et compare au résultat de `--undo` :

<!-- retour-arriere-sql -->
```sql
-- Les adoptions du lancement :  psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 --single-transaction -v run=migration-AAAA-MM-JJ -f -
CREATE TEMP TABLE adoption_undo AS
SELECT a.user_id, a.alias_slug AS common_slug, a.ingredient_slug AS copy_slug
  FROM ingredient_alias a
 WHERE a.kind = 'adoption' AND a.source = :'run';

-- 1. Les recettes : lignes des recettes de leur auteur, puis +1 de version pour celles qui ont changé
WITH changed AS (
  UPDATE recipe_row rr SET ingredient = u.common_slug
    FROM adoption_undo u, recipe r
   WHERE rr.ingredient = u.copy_slug AND r.id = rr.recipe AND r.author = u.user_id
  RETURNING rr.recipe)
UPDATE recipe SET version = version + 1 WHERE id IN (SELECT recipe FROM changed);

-- 2. Les listes de courses (toutes, les envoyées au drive aussi) : lignes et ingrédients, puis +1 de version
WITH list_rows AS (
  UPDATE shopping_list_row x SET ingredient = u.common_slug
    FROM adoption_undo u, shopping_list l
   WHERE x.ingredient = u.copy_slug AND l.id = x.shopping_list AND l.owner = u.user_id
  RETURNING x.shopping_list),
 list_ingredients AS (
  UPDATE shopping_list_ingredient x SET ingredient = u.common_slug
    FROM adoption_undo u, shopping_list l
   WHERE x.ingredient = u.copy_slug AND l.id = x.shopping_list AND l.owner = u.user_id
  RETURNING x.shopping_list)
UPDATE shopping_list SET version = version + 1
 WHERE id IN (SELECT shopping_list FROM list_rows UNION SELECT shopping_list FROM list_ingredients);

-- 3. Les choix du drive (toutes enseignes, tous magasins) ; un choix dont le commun est déjà pris dans le même magasin n'est pas touché
UPDATE drive_choice c SET ingredient_slug = u.common_slug
  FROM adoption_undo u
 WHERE c.user_id = u.user_id AND c.ingredient_slug = u.copy_slug
   AND NOT EXISTS (SELECT 1 FROM drive_choice o WHERE o.user_id = c.user_id AND o.retailer = c.retailer AND o.store_id = c.store_id AND o.ingredient_slug = u.common_slug);

-- 4. La correspondance du lancement disparaît : les communs redeviennent visibles. Les copies et leurs conversions RESTENT.
DELETE FROM ingredient_alias a USING adoption_undo u
 WHERE a.user_id = u.user_id AND a.alias_slug = u.common_slug AND a.kind = 'adoption' AND a.source = :'run';

-- Contrôle (attendu : 0 partout) : des lignes sont-elles restées sur une copie du lancement ?
SELECT (SELECT count(*) FROM recipe_row x JOIN adoption_undo u ON x.ingredient = u.copy_slug JOIN recipe r ON r.id = x.recipe AND r.author = u.user_id) AS recettes,
       (SELECT count(*) FROM shopping_list_row x JOIN adoption_undo u ON x.ingredient = u.copy_slug JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = u.user_id) AS lignes_de_liste,
       (SELECT count(*) FROM shopping_list_ingredient x JOIN adoption_undo u ON x.ingredient = u.copy_slug JOIN shopping_list l ON l.id = x.shopping_list AND l.owner = u.user_id) AS ingredients_de_liste,
       (SELECT count(*) FROM drive_choice c JOIN adoption_undo u ON c.user_id = u.user_id AND c.ingredient_slug = u.copy_slug) AS choix_du_drive;
DROP TABLE adoption_undo;
```

Après l'une ou l'autre voie : relire les contrôles de fin du § 13 (les lignes sont revenues sur les communs, plus aucun alias du lancement) et ouvrir une recette, une liste et l'écran des produits du drive d'un compte concerné.

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

- **Script** `.claude/skills/repas-ops/references/adopter-ingredients.sh`, sur le modèle de `rouvrir-listes-envoyees.sh` : sans argument, **lecture seule** (rapport à blanc) ; avec `--appliquer`, le rapport à blanc, puis **la sauvegarde `./backup.sh avant-adoption-ingredients`**, puis `docker compose exec -T php php bin/console repas:ingredients:adopt-commons --apply …`, puis `--check`. S'arrête avant la sauvegarde si le rapport à blanc signale une anomalie bloquante. Lancé par l'utilisateur (`!`), **jamais par un agent de code** ; aucun secret dans le chat ([skill `repas-ops`](../../.claude/skills/repas-ops/SKILL.md), `references/ingredients.md`). Essayé sur une copie (`REPAS_DIR`) par `AdoptIngredientsScriptTest`.
- **Sauvegarde** : le déploiement du back en fait déjà une (`deploy/deploy.sh`) ; la sauvegarde propre au lancement (`./backup.sh avant-adoption-ingredients`, un `pg_dump` lisible vérifié, 14 conservées dans `/opt/repas/backups/`) la complète juste avant l'écriture. Elles contiennent des données personnelles et restent sur le serveur ([`../production.md`](../production.md) « Sauvegardes »).
- **Durée mesurée** (base de développement, PostgreSQL 16) : 26 requêtes et moins d'une seconde par compte, même avec 10 000 lignes à repointer et 262 communs ; la commande entière (rapport, écriture, vérification) 1,3 s pour un compte de 10 000 lignes ; le second lancement 0,6 s. Avec 6 comptes concernés : **quelques secondes** au total. Le verrou le plus long est celui des `UPDATE` d'un compte (millisecondes).
- **Volume ajouté** en base : environ 630 lignes par compte concerné (260 copies, ~105 conversions propres, 260 alias), de l'ordre de **4 000 lignes** pour la production actuelle, négligeable.

### Procédure pas à pas (avec l'assistant, une étape à la fois)

Avant tout : ING-001, ING-002 et ING-004 sont **déployés** et ont tourné quelques heures ; **aucune autre release du back ce jour-là** ; la nuit ou un moment calme ; aucun envoi de liste en cours sur un téléphone (sinon attendre ou rouvrir la liste). `ssh …` veut dire `ssh -i ~/.ssh/id_ed25519 root@64.227.124.100`.

1. **Mesurer l'état d'avant** (lecture seule, les relevés du § 14, plus la photographie globale ci-dessous) et la noter. Elle sert à comparer à la fin :
   ```sql
   SELECT (SELECT count(*) FROM recipe_row)               AS lignes_de_recettes,
          (SELECT count(*) FROM shopping_list_row)        AS lignes_de_listes,
          (SELECT count(*) FROM shopping_list_ingredient) AS ingredients_de_listes,
          (SELECT count(*) FROM drive_choice)             AS choix_du_drive,
          (SELECT count(*) FROM drive_choice_product)     AS produits_des_choix,
          (SELECT count(*) FROM ingredient WHERE creator IS NULL) AS communs,
          (SELECT md5(string_agg(t::text, ',' ORDER BY t.slug)) FROM ingredient t WHERE t.creator IS NULL) AS somme_des_communs,
          (SELECT count(*) FROM ingredient_alias)         AS alias;
   ```
2. **Le rapport à blanc** : `! ssh … 'bash -s' < .claude/skills/repas-ops/references/adopter-ingredients.sh`. Rien n'est modifié. Le lire avec l'assistant : les comptes retenus et ignorés (l'administrateur et le compte sans recette ni ingrédient sont ignorés), les collisions de slug, **les listes envoyées** (le rapport dit « exigera --accept-sent »), les choix du drive à repointer, aucune « ANOMALIE BLOQUANTE ». Noter les **empreintes du contenu** de chaque compte.
3. **Essayer sur un seul compte** si l'on veut (la référence est dans le rapport) : `… 'bash -s -- --appliquer --accepter-listes-envoyees --compte=<référence>' < …/adopter-ingredients.sh`. Ouvrir une recette, la liste et l'écran des produits du drive de ce compte.
4. **L'écriture** : `! ssh … 'bash -s -- --appliquer --accepter-listes-envoyees' < .claude/skills/repas-ops/references/adopter-ingredients.sh`. Le script refait le rapport à blanc (il s'arrête s'il y a une anomalie bloquante, et s'il n'y a rien à faire), fait la **sauvegarde** `avant-adoption-ingredients` (la sortie donne le nom du fichier), écrit compte par compte (une ligne par compte : copies créées, conversions, lignes repointées, empreinte « identique »), puis relit tout et doit finir sur « Rien à faire : 0 à adopter, 0 à repointer, empreintes identiques à celles d'avant ». **Au premier échec il s'arrête** : le compte en cause n'a rien reçu (transaction annulée), les comptes déjà faits le restent, relancer est sans danger après avoir lu le message.
5. **Le deuxième lancement** (doit ne rien écrire) : même commande ; le script lit le rapport, voit qu'il n'y a plus rien à faire et dit « Rien à faire : aucune sauvegarde, aucune écriture » (appelée directement, la commande dit pour chaque compte « 0 à adopter, 0 à repointer, rien à faire »). Puis **le verdict seul** : `! ssh … 'bash -s -- --verifier' < …/adopter-ingredients.sh` (code de sortie 0, « Rien à faire »).
6. **Les contrôles SQL de fin** (lecture seule, ci-dessous), à comparer à la photographie de l'étape 1.
7. **Voir à l'écran** (par un compte concerné, avec lui ou en lecture) : une recette (mêmes noms et quantités), la liste de courses, l'écran des produits du drive (mêmes produits mémorisés), le rayon d'un ingrédient (une seule fois chaque ingrédient, modifiable) ; un compte non concerné et l'administrateur : rien de changé.
8. En cas de doute à n'importe quelle étape : ne rien relancer, lire § 10 (retour arrière).

<!-- controles-sql -->
```sql
-- 1. Lignes qui référencent encore un commun, chez les comptes migrés (attendu : 0 partout)
SELECT 'recettes' AS t, count(*) FROM recipe_row x JOIN recipe r ON r.id = x.recipe JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS NULL AND r.author IN (SELECT user_id FROM ingredient_alias WHERE kind = 'adoption')
UNION ALL SELECT 'lignes de liste', count(*) FROM shopping_list_row x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS NULL AND l.owner IN (SELECT user_id FROM ingredient_alias WHERE kind = 'adoption')
UNION ALL SELECT 'ingredients de liste', count(*) FROM shopping_list_ingredient x JOIN shopping_list l ON l.id = x.shopping_list JOIN ingredient i ON i.slug = x.ingredient
 WHERE i.creator IS NULL AND l.owner IN (SELECT user_id FROM ingredient_alias WHERE kind = 'adoption')
UNION ALL SELECT 'choix du drive', count(*) FROM drive_choice c JOIN ingredient i ON i.slug = c.ingredient_slug
 WHERE i.creator IS NULL AND c.user_id IN (SELECT user_id FROM ingredient_alias WHERE kind = 'adoption');

-- 2. Alias d'adoption invalides : la copie manque, n'est pas au compte ou ne vient pas du commun de l'alias (attendu : 0). Un alias que
--    la fusion de deux ingrédients (ING-005) a déplacé sur l'ingrédient gardé porte la source `merge` : seule sa copie compte
SELECT count(*) AS alias_invalides FROM ingredient_alias a
 WHERE a.kind = 'adoption' AND NOT EXISTS (SELECT 1 FROM ingredient o WHERE o.slug = a.ingredient_slug AND o.creator = a.user_id AND (o.adopted_from = a.alias_slug OR a.source = 'merge'));

-- 3. Aucun administrateur n'a d'alias, aucun compte sans recette ni ingrédient personnel non plus (attendu : 0)
SELECT count(*) AS alias_hors_perimetre FROM ingredient_alias a JOIN "user" u ON u.id = a.user_id
 WHERE u.roles::jsonb @> '["ROLE_ADMIN"]'::jsonb
    OR (NOT EXISTS (SELECT 1 FROM recipe r WHERE r.author = u.id) AND NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.creator = u.id AND i.adopted_from IS NULL));

-- 4. Le nombre de comptes migrés et d'alias par compte (attendu : un nombre d'alias égal au nombre de communs, pour chaque compte retenu)
SELECT count(*) AS comptes_migres, min(n) AS alias_min, max(n) AS alias_max, (SELECT count(*) FROM ingredient WHERE creator IS NULL) AS communs
  FROM (SELECT user_id, count(*) AS n FROM ingredient_alias WHERE kind = 'adoption' GROUP BY user_id) par_compte;

-- 5. La photographie globale : mêmes nombres qu'avant l'écriture (rien n'est créé ni supprimé dans ces tables), mêmes communs
SELECT (SELECT count(*) FROM recipe_row)               AS lignes_de_recettes,
       (SELECT count(*) FROM shopping_list_row)        AS lignes_de_listes,
       (SELECT count(*) FROM shopping_list_ingredient) AS ingredients_de_listes,
       (SELECT count(*) FROM drive_choice)             AS choix_du_drive,
       (SELECT count(*) FROM drive_choice_product)     AS produits_des_choix,
       (SELECT count(*) FROM ingredient WHERE creator IS NULL) AS communs,
       (SELECT md5(string_agg(t::text, ',' ORDER BY t.slug)) FROM ingredient t WHERE t.creator IS NULL) AS somme_des_communs,
       (SELECT count(*) FROM ingredient_alias)         AS alias;

-- 6. Références orphelines (attendu : 0 partout, comme avant)
SELECT 'recipe_row' AS t, count(*) FROM recipe_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'shopping_list_row', count(*) FROM shopping_list_row x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'shopping_list_ingredient', count(*) FROM shopping_list_ingredient x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient)
UNION ALL SELECT 'drive_choice', count(*) FROM drive_choice x WHERE NOT EXISTS (SELECT 1 FROM ingredient i WHERE i.slug = x.ingredient_slug);
```

Les contrôles 1 à 3 et 6 doivent donner **0**, le contrôle 4 donne un nombre d'alias égal au nombre de communs, le 5 est **identique** à la photographie de l'étape 1 sauf `alias` (qui a augmenté du nombre de copies). Ces requêtes sont exécutées par `AdoptionDocumentedSqlTest` sur un jeu de données migré : le texte ci-dessus est celui qui est testé.

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
| `drive_choice.ingredient_slug` | repointé du commun vers la copie, **toutes enseignes et tous magasins** de l'utilisateur ; la clé unique `(user_id, retailer, store_id, ingredient_slug)` ne peut pas être violée (le slug de la copie est neuf) | `DriveIngredientReferencesRepositoryTest` (le port `Shared` de `Drive` : toutes enseignes et tous magasins, clé unique respectée, rien d'autre ne change) + `AdoptCommonIngredientsCommandTest` : nombre de choix identique, aucun choix sous un slug de commun adopté, aucune violation de clé |
| `drive_choice_product` (produits acceptés, refusés, rang) | **inchangé** : rattaché à l'identifiant du choix, pas au slug | somme de contrôle avant/après dans la transaction de chaque compte (`DriveChoiceFingerprint`), y compris les colonnes `pack_size_*` |
| `drive_choice.search_query` (DRV-650) et `kind` | inchangés ; le texte cherché par défaut quand il n'y a pas de recherche mémorisée est le **nom** de l'ingrédient, identique dans la copie | `AdoptionDriveEquivalenceTest` : `searchQuery` identique avant/après |
| `drive_product` (contenu net, prix, étiquette) | inchangés : faits publics sans propriétaire | rien à tester de plus |
| `drive_choice_product.pack_size_value` / `pack_size_unit_slug` (DRV-652 : « 20 tranches dans ce produit ») | **inchangés** par la migration (rattachés à l'identifiant du choix ; l'unité est un slug d'unité) ; ils ne servent que si leur unité est exactement celle de la ligne, ce qui reste vrai puisque la copie garde l'unité d'achat. **Fusion** : si l'unité d'achat de `B` diffère de celle de `A`, la contenance d'un produit de `B` qui passe sur `A` devient **inerte** (le calcul retombe sur le contenu net, comme pour une liste qui change d'unité) ; elle est supprimée avec le choix de `B` quand `A` en a un | `PackCalculatorTest` (unité différente ignorée, existant) ; `ChoiceRepositoryTest` : les `pack_size_*` suivent le choix repointé ; test de fusion : choix de `B` repointé avec une unité différente donne un plan sans contenance |
| Plan d'envoi, nombre de produits (`PackCalculator`) | identiques : la copie garde les **mêmes unités d'achat** (dimension et facteur de base) | **test d'équivalence** (`AdoptionDriveEquivalenceTest`) : `GetDrivePlan` et `GetDriveEstimate` d'un utilisateur de test (choix, listes envoyées et non envoyées) donnent les mêmes lignes à l'ingrédient près, avant et après |
| Listes `SENT` | repointées avec leurs choix (sinon leurs produits mémorisés disparaissent du plan) ; contenu, statut, `sent_at` inchangés ; **`--accept-sent` obligatoire** | critères d'ING-003 (`AdoptCommonIngredientsCommandTest`, `AdoptCommonIngredientsForUserHandlerTest`) |
| `drive_department_rayon` (rayon de l'enseigne appris par rayon de Repas) | inchangé ; si l'utilisateur change le rayon de sa copie, l'apprentissage suit le **nouveau** rayon (voulu) | `IngredientDepartmentPostgreSQLResolverTest` |
| Écritures qui reçoivent un slug (`PUT/DELETE /drive/choices/{ingredientSlug}`, lot de choix de l'agent) | un ancien slug (brouillon d'envoi sur le téléphone, onglet ouvert) est **résolu** vers la copie ; jamais de choix créé sous un commun adopté | port `Shared` `IngredientAliasResolver` (ING-002 C) et ses tests ; `Drive` ne dépend que de `Shared` (Deptrac) |
| Fusion (ING-005, livré) | choix de `A` gagnant, celui de `B` supprimé avec ses produits, sinon passe sur `A` (port `DriveIngredientReferences::merge`, toutes enseignes et tous magasins) ; refus si une liste `SENT` est concernée ou si un autre utilisateur a un choix sur `B` | `DriveIngredientReferencesRepositoryTest`, `MergeDriveEquivalenceTest` (plan et estimation avant/après, le choix de `A` sert le plan), `IngredientMergeApiFlowTest` (un ancien slug dans un `PUT /drive/choices` atterrit sur `A`) |
| Plafonds de débit, interrupteurs | sans objet (aucune route du drive n'est ajoutée) | `DriveRoutesAreRateLimitedTest` inchangé |
| Suppression de compte | `PurgeUserDriveData` inchangé ; les alias sont purgés par `Repas` | `UserOwnedDataCoverageTest` |
| Plusieurs enseignes (ADR 0027) | aucun nom d'enseigne dans le code ajouté ; le repointage et la fusion sont par `(utilisateur, ingrédient)`, quelles que soient les enseignes | revue : aucun nom d'enseigne dans le diff (les registres d'enseignes de l'ADR 0027 restent les seuls endroits permis) |