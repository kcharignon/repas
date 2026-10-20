# Rapprochement des ingrédients à la copie d'une recette

> **Amendement du 2026-10-20 : plus d'ingrédient commun** ([`sans-commun.md`](sans-commun.md)). Après la passation, l'étape 0 (provenance) prend la clé d'alias `adopted_from` de l'ingrédient source, **sinon son slug**, sans branche « commun » ni adoption à la volée ; la copie retient toujours son origine (décision D7, ING-013). Le reste de l'algorithme (seuils, garde-fou, même rayon) ne change pas.

Spécification de ce qui remplace le comportement décrit dans [`analyse.md`](analyse.md) § 6, **mesures à l'appui** (expérience reproductible dans [`experiments/`](experiments/)). Décision : [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision f. Ticket : [ING-004](tickets/ING-004-rapprochement-et-copie-de-recette.md).

## 1. Le principe : se tromper vers le doublon, jamais vers la fusion

Quand on copie une recette dont un ingrédient n'est pas déjà « à soi », deux erreurs sont possibles :

- **doublon** : on crée une copie alors que le copieur avait déjà cet ingrédient. L'utilisateur voit deux « Tomate » ; il peut les fusionner ([`fusion.md`](fusion.md)) et l'application les lui propose ;
- **faux rapprochement** : on branche la recette sur un ingrédient qui n'est pas le bon (« poivre noir » sur « poivre »). **Personne ne le voit**, les quantités se cumulent sur le mauvais produit dans la liste de courses et le drive cherche le mauvais article.

La première est visible et réparable en un geste ; la seconde est silencieuse. **On ne branche que sur une certitude** ; au moindre doute on crée la copie, comme si l'ingrédient n'existait pas.

## 2. Ce qui est comparé

Pour chaque ligne de la recette copiée, on parle de l'ingrédient **source** `X` (celui de l'auteur) et de l'ensemble des **candidats** du copieur :

- les ingrédients personnels du copieur ;
- les communs qu'il voit encore (ceux qu'il n'a pas adoptés, [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision b), dans l'état de la base ;
- **uniquement ceux du même rayon que `X`** (égalité des slugs de rayon). Un rayon est une liste fermée gérée par l'administrateur : « tomate » du rayon surgelé n'est jamais un candidat pour « tomate » du rayon fruits et légumes.

## 3. L'algorithme, dans l'ordre

Il vit dans un service de domaine pur `Repas\Domain\Service\IngredientNameMatcher` (aucune base ; les candidats lui sont donnés par un port de lecture légère), qui **réutilise `Shared\Domain\Tool\JaroWinkler`** (`src/Shared/Domain/Tool/JaroWinkler.php`, ADR 0019). Il **n'utilise pas** `SearchRelevance` : cette pertinence de recherche donne 0,946 à « tomate » contre « tomate cerise » parce que le texte tapé *commence* le nom ([`analyse.md`](analyse.md) § 8).

### Étape 0 : la provenance (certitude, sans comparer de noms)

Si `X` est lui-même l'**adoption d'un commun** `C` (`X.adopted_from = C`, décision d de l'ADR), on regarde ce que le copieur a fait de `C` :

1. il en a l'alias (adopté ou fusionné) : on se branche sur l'ingrédient désigné ;
2. il n'en a pas, et il est « adoptant » (au moins un alias d'adoption) : on adopte `C` à la volée (copie transparente, [ADR 0028](../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision b) puis on se branche dessus ;
3. il n'en a pas et n'est pas adoptant : on se branche sur le commun `C` lui-même (il le voit, comme aujourd'hui).

Si `X` est un commun, c'est le même arbre (1 à 3) avec `C = X`. Après la migration, **la grande majorité des lignes d'une recette sont des adoptions de communs** : elles se rapprochent donc sans aucune comparaison de noms, donc sans risque.

### Étape 1 : la normalisation du nom (`N2`)

```
tokens(nom) :
  1. s = StringTool::slugify(nom)        # minuscules, accents retirés, œ -> oe, tout ce qui n'est pas lettre ou chiffre -> « - »
  2. mots = s coupé sur « - », sans mot vide
  3. pluriels repliés : un mot de plus de 3 lettres qui finit par « s » ou « x » perd cette lettre
  4. mots vides retirés : de du des d l la le les un une a au aux en et pour
     (si tout est retiré, on garde les mots de l'étape 3)
```

Ainsi « Pommes de terre », « pomme-de-terre » et « Pomme de terre. » donnent tous `[pomme, terre]` ; « Œufs » et « oeuf » donnent `[oeuf]` ; « Lingette bébé » et « lingettes pour bébé » donnent `[lingette, bebe]`. Les mots vides ne contiennent ni « sans » (il change le sens) ni « sur ».

### Étape 2 : seuls les candidats « sans doute » passent le garde-fou `G6`

Le Jaro-Winkler d'un nom entier est dominé par sa partie commune : « couche (taille 4) » et « couche (taille 5) » ont 0,97, « confiture framboise » et « confiture fraise » 0,97 aussi. On exige donc, **mot à mot** :

- le **même nombre de mots** (« tomate » n'est jamais « tomate cerise », « poivre » n'est jamais « poivre noir ») ;
- chaque mot est **identique**, ou **à une seule faute près** (insertion, oubli, substitution ou permutation de deux lettres voisines : distance d'édition 1) quand le plus long des deux a **5 lettres ou plus** (longueurs mesurées sur les mots normalisés, pluriels repliés) ;
- un mot qui contient un **chiffre** doit être identique (« taille 4 » ≠ « taille 5 », « 2e âge » ≠ « 3e âge »).

### Étape 3 : le score et les deux niveaux

`score = JaroWinkler::similarity(nom normalisé du source, nom normalisé du candidat)` (mots joints par « - »), de 0 à 1.

- **Niveau exact** : les deux noms normalisés sont identiques (score 1). Pluriel, accent, majuscule, ponctuation, mot vide : certain. **On branche.**
- **Niveau faute de frappe** : score **de 0,97 au moins**. **On branche.**
- Sinon : **aucun lien**, on crée la copie.

### Étape 4 : le départage et l'ambiguïté

1. Un nom exact l'emporte sur tout. Si plusieurs candidats sont exacts (le copieur a lui-même deux fois « Tomate » dans ce rayon), on prend le premier par ordre alphabétique de slug : le résultat est **déterministe** (ces doublons sont justement ce que l'écran « Doublons possibles » fait fusionner).
2. Au niveau faute de frappe, si un **second candidat** est à moins de **0,02** du premier, le lien est **ambigu** : aucun lien. On ne choisit pas à la place de l'utilisateur.

### Étape 5 : l'unité doit suivre

Le candidat retenu doit pouvoir **convertir l'unité de la ligne** dans son unité d'achat par son propre graphe de conversions (`ConversionService::assertConvertibleToPurchaseUnit`, [`analyse.md`](analyse.md) § 7). Sinon la recette copiée échouerait à la planification. Si ce n'est pas le cas : aucun lien pour cette ligne, on crée la copie (qui garde les conversions de l'auteur).

### Quand il n'y a pas de lien : la copie

Comme aujourd'hui : un ingrédient au nom du copieur, mêmes nom, rayon, unités, image et **conversions propres** copiées. Trois corrections ([`analyse.md`](analyse.md) § 6) :

- le slug suit la règle de l'ADR (décision c) et **ne peut plus écraser** un ingrédient existant du copieur ;
- la copie garde `adopted_from` si `X` en avait un (la provenance voyage) ;
- seules les **vraies créations** sont annoncées aux statistiques (`RecipesOrIngredientsCreatedEvent`).

## 4. Ce que voit l'utilisateur

**Rien de plus qu'aujourd'hui** : la recette copiée s'ouvre. Pas de fenêtre, pas de rapport de rapprochement, pas de bandeau (principes produit : un écran, une décision). Si des doublons se sont glissés, l'écran « Doublons possibles » ([`fusion.md`](fusion.md) § 8) les propose le moment venu, avec un petit compteur sur la page des rayons.

## 5. Cas particuliers

| Cas | Comportement |
|---|---|
| Le copieur copie **sa propre** recette | `X` est dans les candidats : niveau exact, on se branche sur lui-même ; plus d'écrasement, plus de conversions dupliquées |
| Même nom, **autre rayon** | pas candidat (règle du rayon) : copie, avec un slug qui ne collisionne pas |
| Même nom, même rayon, **unité incompatible** | pas de lien (étape 5) : copie |
| L'auteur est un **administrateur** | ses ingrédients sont des communs : arbre de l'étape 0 |
| Le copieur est un **administrateur** | il voit tous les communs, aucun n'est caché pour lui : il se branche sur le commun |
| Le candidat exact est un commun encore visible | on se branche sur le commun ; si le copieur est adoptant, le commun est adopté à la volée d'abord |

## 6. L'expérience : méthode

Script jetable `experiments/evaluate.php`, **sans base ni réseau**, qui réutilise `JaroWinkler` et `StringTool` du dépôt. Données : les 256 ingrédients **communs** du jeu de référence (`src/Repas/Infrastructure/DataFixture/Data/ingredient.json`, publics).

**Vérité terrain** (`experiments/ground-truth.json`, 1 421 cas, produite par `build-ground-truth.php` avec une graine fixe) : une requête est un nom tapé dans un rayon ; l'attendu est le commun sur lequel il faut se brancher, ou **aucun**.

| Catégorie | Cas | Attendu |
|---|---|---|
| casse, ponctuation | 521 | le commun d'origine |
| accent (« œuf » écrit « oeuf », accent manquant) | 69 | idem |
| pluriel ou singulier | 284 | idem |
| faute de frappe (permutation, oubli, doublon, touche voisine du clavier) | 481 | idem |
| qualificatif (« poivre noir », « sel fin », « lait d'amande », « haricots verts »…) | 40 | **aucun** |
| frère trompeur (« Poivre » dans les fruits et légumes, « Tomme », « Poule », « Laitière »…) | 26 | **aucun** |
| *s'y ajoutent* chaque commun rejoué contre **les autres communs de son rayon** (aucun doublon dans ce jeu : tout lien est faux) | 256 | **aucun** |

Dont 1 276 cas générés et 145 écrits à la main (les fautes que le jeu de référence contient lui-même : « spaguetti », « bettrave », « gnocci », « steack »…). **Limite honnête** : les fautes générées sont à une seule opération, les « frères trompeurs » écrits exprès sont le pire cas plausible ; une base d'utilisateurs réels aura un mélange différent, avec moins de fautes et plus de qualificatifs.

On y fait varier : la **normalisation** (`N0` = le slug actuel ; `N1` = + pluriels repliés ; `N2` = + mots vides retirés), le **garde-fou** sur les mots (`G0` aucun ; `G1` même nombre de mots ; `G2`/`G3` Jaro-Winkler par mot ; `G4` une faute par mot, 5 lettres et plus ; `G6` comme `G4` mais un mot de 4 lettres peut avoir une faute si son pareil en a 5), la **marge d'ambiguïté** (aucune, ou 0,02) et le **seuil** de 0,85 à 0,99 par pas de 0,01. Une requête est jugée sur le résultat de bout en bout : bon lien, doublon créé (aucun lien alors qu'il fallait un lien), mauvais lien (un autre candidat), lien à tort (un lien alors qu'il ne fallait aucun).

## 7. Résultats

Résultats complets : [`experiments/resultats.md`](experiments/resultats.md) (rejouables). L'essentiel :

### 7.1 Courbe du seuil (15 seuils)

« Faux liens » = mauvais liens + liens à tort (sur 322 requêtes qui attendent *aucun* ou un autre candidat). « Doublons créés » : sur 1 355 requêtes qui attendent un lien.

| Seuil | Base : précision | Base : rappel | Base : doublons créés | Base : **faux liens** | Recommandé : précision | Recommandé : rappel | Recommandé : doublons créés | Recommandé : **faux liens** |
|---|---|---|---|---|---|---|---|---|
| 0,85 | 88,7 % | 99,8 % | 2 | 172 | 99,5 % | 97,0 % | 40 | 7 |
| 0,86 | 89,5 % | 99,8 % | 2 | 158 | 99,5 % | 97,0 % | 40 | 7 |
| 0,87 | 90,2 % | 99,6 % | 4 | 146 | 99,5 % | 97,0 % | 41 | 6 |
| 0,88 | 91,5 % | 99,6 % | 4 | 126 | 99,5 % | 96,9 % | 42 | 6 |
| 0,89 | 92,8 % | 99,4 % | 7 | 105 | 99,5 % | 96,7 % | 45 | 6 |
| 0,90 | 94,1 % | 99,0 % | 13 | **84** | 99,5 % | 96,4 % | 49 | **6** |
| 0,91 | 96,3 % | 98,3 % | 22 | 51 | 99,5 % | 95,8 % | 57 | 6 |
| 0,92 | 96,9 % | 97,9 % | 27 | 43 | 99,5 % | 95,4 % | 62 | 6 |
| 0,93 | 98,3 % | 96,4 % | 48 | **23** | 99,5 % | 94,2 % | 78 | **6** |
| 0,94 | 98,8 % | 94,7 % | 72 | 15 | 99,7 % | 93,2 % | 92 | 4 |
| 0,95 | 99,0 % | 91,3 % | 118 | **12** | 99,8 % | 90,9 % | 123 | **3** |
| 0,96 | 99,0 % | 87,2 % | 174 | 12 | 99,7 % | 87,7 % | 166 | 3 |
| **0,97** | 99,5 % | 78,0 % | 298 | **5** | 100,0 % | 83,4 % | 225 | **0** |
| 0,98 | 100,0 % | 59,0 % | 556 | 0 | 100,0 % | 75,1 % | 337 | 0 |
| 0,99 | 100,0 % | 44,3 % | 755 | 0 | 100,0 % | 65,1 % | 473 | 0 |

(La précision, c'est-à-dire bons liens / liens faits, flatte : 95 % des cas attendent un lien. **Le nombre de faux liens est le chiffre qui compte.**)

**Lecture.**

1. **Le Jaro-Winkler seul ne suffit pas.** Il faut monter à 0,98 pour ne plus avoir un seul faux lien, et on ne retrouve alors que 59 % des ingrédients identiques. À 0,90, un quart des « aucun » seraient branchés à tort. Les erreurs sont des qualificatifs (« jambon blanc » sur « jambon »), des frères (« confiture fraise » / « confiture framboise », « saucisse » / « saucisson ») et des numéros (« couche (taille 4) » / « (taille 5) »).
2. **Avec la normalisation et le garde-fou « mot à mot », aucun faux lien « naturel » à aucun seuil de 0,85 à 0,99** : sur les 256 communs rejoués contre leur rayon, les 40 qualificatifs et les autres cas écrits à la main, aucun mauvais lien (au plus 1, « Tomme » -> « pomme », sous 0,87). Les 3 à 7 faux liens restants sont **tous** parmi les 6 pièges à une lettre écrits exprès (« Poule » -> « poulet », « Laitière » -> « litière », « Cote » -> « comté », « Boeuf » demandé dans le rayon de l'œuf…, certains placés exprès dans le rayon du voisin), indiscernables d'une vraie faute de frappe (0,93 à 0,97). Ils disparaissent à 0,97.
3. **Le seuil fait payer des fautes de frappe, rien d'autre** : à 0,97, tous les pluriels, accents, majuscules, ponctuations et mots vides sont retrouvés (100 %) ; les fautes de frappe le sont à 53 % (0 % des noms de moins de 6 lettres, 41 % de 6 à 9, 68 % de 10 lettres et plus). Ce qui reste devient un **doublon visible**, que l'écran « Doublons possibles » propose avec un seuil plus bas (§ 8).
4. **Le rayon** : sans la règle du même rayon, la règle recommandée fait 13 faux liens à 0,90 au lieu de 6, la base 110 au lieu de 84 (`resultats.md`, « Même rayon ou non »).
5. **La marge d'ambiguïté** est sans effet mesuré avec les garde-fous (les mots sont déjà exigés) ; elle supprime 30 faux liens en base. On la garde (défense en profondeur et déterminisme).
6. **La normalisation** vaut environ 6 points de rappel à 0,97 (77 % -> 83 %, garde-fou égal) : pluriels repliés et mots vides.

### 7.2 Effet de chaque règle (extraits de `resultats.md`)

| Seuil | Normalisation / garde-fou / marge | Bons liens | Doublons créés | Faux liens |
|---|---|---|---|---|
| 0,90 | `N0` / aucun / non | 1 341 | 13 | 84 |
| 0,90 | `N0` / aucun / 0,02 | 1 341 | 14 | 54 |
| 0,90 | `N0` / même nombre de mots / 0,02 | 1 339 | 16 | 27 |
| 0,90 | `N2` / même nombre de mots / 0,02 | 1 336 | 19 | 32 |
| 0,90 | `N2` / une faute par mot (`G6`) / 0,02 | 1 306 | 49 | **6** |
| 0,97 | `N0` / aucun / 0,02 | 1 057 | 298 | 0 |
| 0,97 | `N2` / une faute par mot (`G6`) / 0,02 | 1 130 | 225 | **0** |

### 7.3 Sur la production (comptes seulement, aucun nom publié)

Mesure locale sur l'export de production (environ 370 ingrédients dont environ 110 personnels, créés par **trois** comptes) :

- Un ingrédient personnel contre les communs et les ingrédients personnels des autres comptes **du même rayon**, noms différents : le Jaro-Winkler seul aurait branché **25** ingrédients à tort à 0,85, **10** à 0,90, **0** à 0,93. La règle recommandée n'en branche **aucun**, à aucun seuil de 0,85 à 0,97. Presque tous ces faux liens sont un nom précisé par un qualificatif ou une marque dont le début est le nom d'un commun : la forme exacte des « frères » que le garde-fou « même nombre de mots » écarte.
- Des noms **identiques** à un commun une fois normalisés : 2 dans le même rayon, 5 dans un autre rayon seulement (la règle du rayon les sépare) ; en nom **exactement** identique (donc au même slug : ce que la migration doit gérer, [`migration.md`](migration.md) § 5), 1 dans le même rayon et 4 dans un autre.
- Écran « Doublons possibles » : 2 paires « même nom » et 0 paire « à une faute près » sur les trois comptes, plus 22 paires « nom précisé » (« X » et « X avec un qualificatif ») qui ne sont proposées que par l'écran, jamais branchées.

## 8. Recommandation

| Paramètre | Valeur | Raison |
|---|---|---|
| Normalisation | `N2` (§ 3, étape 1) | environ +6 points de rappel sans aucun faux lien |
| Même rayon | obligatoire | règle recommandée : 13 faux liens sans la règle, 6 avec ; base : 110 contre 84 |
| Garde-fou | `G6` : même nombre de mots, chaque mot identique ou à une faute près (5 lettres et plus), chiffres identiques | 0 faux lien naturel à tout seuil ; 84 -> 6 à 0,90 |
| Marge d'ambiguïté | 0,02 | déterminisme, défense en profondeur |
| **Seuil du branchement automatique** | **0,97** | **0 faux lien, y compris sur les pièges écrits exprès** ; 83 % de rappel dont 100 % des variantes d'écriture (pluriel, accent, casse, ponctuation) ; le reste devient un doublon visible |
| Seuil des **suggestions** de l'écran « Doublons possibles » | **0,90** (même normalisation, même garde-fou) | l'utilisateur décide : on cherche le rappel (96 %) en acceptant les 6 pièges ; plus la règle « même nom normalisé » et la règle « nom précisé » (limitée à 10 propositions) |
| Ne jamais proposer | deux ingrédients qui sont l'adoption de **deux communs différents** | l'administrateur les a voulus distincts (« tomate », « tomate cerise », « tomate à farcir ») : sinon l'écran s'ouvrirait sur une centaine de fausses paires pour un compte qui a adopté tout le catalogue |

Les trois valeurs numériques (0,97 ; 0,90 ; 0,02) et la liste des mots vides sont **des constantes nommées** de `IngredientNameMatcher`, testées par le jeu de vérité terrain : le ticket ne devine rien, il rejoue la vérité terrain.

À valider par le propriétaire : le **0,97** peut paraître sévère (il réduit le Jaro-Winkler à un rôle de filet pour les fautes dans les noms de 10 lettres et plus). C'est le choix qui respecte « un faux rapprochement est pire qu'un doublon » ; descendre à 0,95 gagne 7 points de rappel (des fautes de frappe seulement) contre 3 faux liens sur les pièges écrits exprès, 0,93 gagne 11 points contre 6.

## 9. Tests exigés par le ticket

- `IngredientNameMatcherTest` (domaine, sans base) : le jeu `experiments/ground-truth.json`, copié dans `tests/Repas/Domain/Service/IngredientNameMatching/` (le script d'expérience garde la sienne : si le jeu change, regénérer les deux), rejoué avec les constantes de production : **zéro faux lien** sur toutes les lignes `expected: null`, **rappel de 100 %** sur les catégories `casse`, `ponctuation`, `accent`, `pluriel`, au moins 50 % sur `faute` ; plus des cas de départage (deux exacts, deux presque exacts à moins de 0,02, ordre alphabétique).
- Les mêmes constantes sont vérifiées par un test de non-régression de l'expérience : le script `evaluate.php` n'a pas de dépendance au reste de `src/` hors `JaroWinkler` et `StringTool`.
- `CopyRecipeHandlerTest` (en mémoire) : provenance (étape 0, trois branches), exact, faute, ambiguïté, unité incompatible, rayon différent, copie de sa propre recette, absence d'écrasement, événement de statistiques (seulement les créations).
- Test de base du port de candidats (rayon, communs cachés, ingrédients d'un autre jamais candidats).
- `WriteBudget` : `copier-recette` monte (candidats lus en une requête, alias lus en une requête) ; le dire dans la PR.

## 10. Rejouer l'expérience

```
docker compose exec php php docs/ingredients/experiments/build-ground-truth.php > docs/ingredients/experiments/ground-truth.json   # idempotent
docker compose exec php php docs/ingredients/experiments/evaluate.php > docs/ingredients/experiments/resultats.md
docker compose exec php php docs/ingredients/experiments/evaluate.php --explain=N2,G6,0.02,0.90     # liste les faux liens et les liens manqués d'une configuration
docker compose exec php php docs/ingredients/experiments/evaluate.php --prod=export.tsv             # mesure locale : COMPTES seulement ; --dump=paires.txt écrit des noms : fichier local, jamais versionné
```

L'option `--prod` lit un export (slug, nom, rayon, commun `t`/`f`) ; elle n'imprime que des comptes. Aucun fichier de production n'est dans le dépôt.
