# 0019 — Recherche d'ingrédients par pertinence (Jaro-Winkler)

- **Statut** : Accepted
- **Date** : 2026-10-16
- **Liens** : [0004](0004-repository-vs-loader.md), [0005](0005-conventions-dto-lecture.md), [0007](0007-api-rest-v1.md), `src/Shared/Domain/Tool/SearchRelevance.php`, `src/Shared/Domain/Tool/JaroWinkler.php`, `IngredientPostgreSQLLoader::search()`

## Contexte

Pour ajouter un ingrédient à la liste, le front obligeait à choisir un rayon puis à parcourir ses ingrédients. L'utilisateur veut taper un nom depuis la page des rayons, obtenir les ingrédients les plus pertinents d'abord, les ajouter à la liste depuis le résultat et voir l'icône du rayon pour y aller directement la fois suivante.

`GET /api/v1/ingredients?q=` existe (`ListAvailableIngredients`) mais il sert le sélecteur des recettes : il garde les noms qui *contiennent* le texte, triés par nom, sans rayon. Il ne tolère pas une faute de frappe et ne classe pas.

## Décision

1. **Un nouveau use case de lecture `SearchIngredients`** : `GET /api/v1/ingredients/search?q=` (`q` obligatoire, 100 caractères au plus, `422` sinon). `ListAvailableIngredients` reste tel quel. La route porte `priority: 10` pour ne pas être prise pour `GET /api/v1/ingredients/{slug}`.
2. **Le résultat est classé par pertinence décroissante** (`{member, totalItems}`, 30 résultats au plus) ; à pertinence égale, par nom puis par slug, pour que deux appels donnent le même ordre. Chaque résultat porte son rayon (`departmentSlug`, `departmentName`, `departmentImage`) en plus des champs de `IngredientRowDTO` : le front affiche l'icône du rayon sans second appel.
3. **La pertinence est calculée par `Shared\Domain\Tool\SearchRelevance`**, une fonction pure sur deux slugs (`StringTool::slugify()`), de 0 à 1 :
   - nom identique au texte : 1 ;
   - le nom ou un de ses mots *commence* par le texte : de 0,9 à 1 selon la part du nom que le texte couvre (« tom » : `tomate` avant `tomate cerise`) ; un texte au milieu d'un mot ne compte pas (« riz » ne trouve pas `chorizo`) ;
   - sinon la similarité de **Jaro-Winkler** (`Shared\Domain\Tool\JaroWinkler`) du texte avec le nom entier, ou avec un de ses mots affectée de 0,9, ce qui rattrape les fautes de frappe (« tomtae » trouve `tomate`).
   Un nom n'est un résultat qu'à partir de `SearchRelevance::MINIMUM` = 0,85, seuil choisi sur les 256 ingrédients du jeu de données de référence : à 0,8, « crème » ramenait `caramel`, « tomate » `comte`.
4. **Le calcul se fait dans le Loader, en PHP** : `IngredientPostgreSQLLoader::search()` lit en une requête les ingrédients visibles (communs et propres à l'utilisateur) avec leur rayon et leur unité d'achat, note chacun, garde les 30 meilleurs. Le `QueryHandler` délègue au Loader sans rien d'autre (ADR 0004) ; le double en mémoire reste idiot, la pertinence se prouve dans les tests de `SearchRelevance` et du Loader réel.
5. **Un texte sans lettre ni chiffre ne trouve rien** (liste vide, pas d'erreur) ; la saisie est normalisée comme les noms (casse, accents, espaces).

## Conséquences

- Positives : une recherche tolérante, classée, avec son rayon, sans extension PostgreSQL ni migration (rien à défaire au retour arrière, [0017](0017-deploiement-et-releases.md) règle 6) ; l'algorithme est testé sans base.
- Négatives / coûts : toute recherche lit tous les ingrédients visibles (256 aujourd'hui, une requête de quelques ms) ; au-delà de quelques milliers d'ingrédients par utilisateur, il faudra préfiltrer en SQL (`pg_trgm`, qui demande l'extension et une migration). Un ingrédient dont le slug est `search` ne serait plus joignable par `GET /api/v1/ingredients/{slug}` : le slug d'un ingrédient vient de son nom et personne n'a d'ingrédient « search ».
- Garde-fou : `SearchRelevanceTest` et `JaroWinklerTest` (valeurs de référence de Winkler), `IngredientPostgreSQLLoaderTest::testSearch*`, `SearchIngredientsApiControllerTest` (dont la route non captée par `{slug}`), `QueryConventionTest`.

## Alternatives écartées

- **`pg_trgm` (`similarity()`)** : prefiltre et classe en SQL, mais exige l'extension, une migration et un score par trigrammes qui note mal les mots courts (« riz », « sel »). À reprendre si le volume l'impose.
- **Étendre `GET /api/v1/ingredients?q=`** : changerait l'ordre et la forme d'un endpoint déjà consommé par le sélecteur de recettes.
- **Distance de Levenshtein** : ne favorise pas le début du mot, or on cherche en tapant.
- **Chercher dans le front** : il ne charge pas tous les ingrédients, et la règle du projet est « pas de logique métier dans les composants ».
