# 0030 — Première lettre des noms en majuscule

- **Statut** : Accepted (2026-10-28, demande du propriétaire)
- **Date** : 2026-10-28
- **Liens** : [0002](0002-modele-domaine-riche.md), [0016](0016-controle-de-version-etag-if-match.md), [0017](0017-deploiement-et-releases.md) règle 6, [0028](0028-ingredients-propres-a-chaque-utilisateur.md), `StringTool::capitalizeFirst`, `NamesStartWithACapitalTest`, `CapitalizeNamesMigrationTest`

## Contexte

Les noms de recettes et d'ingrédients s'affichent tels qu'ils ont été saisis (« pâtes carbonara », « de la farine T55 », « œufs »), et les écrans mélangent les casses. Rien dans le back ne les normalise : `Recipe::create`/`update` et `Ingredient::create`/`update` recopient le nom reçu, et le slug (`StringTool::slugify`, tout en minuscules sans accent) et le nom normalisé (`slugged_name`, détection du doublon) sont calculés à part. Le front (`kcharignon/repas-front`) va forcer la majuscule à la saisie, mais l'API est aussi appelée par l'ancien site, par la commande vocale (`SpokenIngredientCreatorAdapter` passe par `CreateIngredientHandler`) et par les copies de recettes. Seules deux colonnes portent un tel nom dans le schéma : `recipe.name` et `ingredient.name`.

## Décision

1. **Règle** : le nom d'une recette et celui d'un ingrédient commencent par une majuscule. Seule la **première lettre** change ; la casse de la suite est celle de l'utilisateur (« pâtes carbonara » devient « Pâtes carbonara », « de la farine T55 » devient « De la farine T55 », « éclair » devient « Éclair », « œufs » devient « Œufs »). Un nom qui commence déjà par une majuscule est rendu caractère pour caractère ; un nom dont le premier caractère n'est pas une lettre (chiffre, parenthèse, apostrophe) est inchangé ; les espaces de tête sont conservés et la lettre qui les suit passe en majuscule.
2. **Un outil pur** : `Shared\Domain\Tool\StringTool::capitalizeFirst(string): string`, multioctet (accents, ligatures, mise en forme de titre pour une initiale : « ﬁlet » donne « Filet »), idempotent, sans effet sur une chaîne vide ou invalide en UTF-8. Jamais `ucfirst` (un octet à la fois).
3. **Appliquée dans les handlers, en silence** : `CreateRecipeHandler`, `UpdateRecipeHandler`, `CreateIngredientHandler` et `UpdateIngredientHandler` passent le nom reçu par `capitalizeFirst` avant de le donner au modèle. Aucune erreur, aucun message : le nom enregistré est le nom corrigé, la lecture le renvoie. `CopyRecipeHandler` l'applique au nom de la recette copiée et à ceux des ingrédients copiés (une copie reprend le nom de l'original ; la règle n'a aucun effet sur un nom déjà bon). La commande vocale la reçoit de `CreateIngredientHandler` ; ce qu'Alexa dit est inchangé (`VoiceReplies::speakable` met les noms en minuscules).
4. **Ce qui ne change pas** : les slugs, `slugged_name` et la détection du doublon (`existsForCreator`) ne dépendent pas de la casse ; la version (`ETag`, `If-Match`, `412`, `409`) ne change pas ; un client qui envoie des minuscules reçoit le succès habituel (`201`, `204`) et relit un nom corrigé.
5. **Hors périmètre** : les noms des types de recette, des rayons, des unités, des listes de courses et des produits du drive. L'amorçage des ingrédients à l'activation (copie en SQL de `ingredient.name`, vérifiée conforme à sa source) ne transforme pas le nom : sa source, le catalogue du propriétaire, est en majuscule par les décisions 3 et 6.
6. **Migration des données existantes** : `Version20261028160000` met en majuscule la première lettre de tous les `recipe.name` et `ingredient.name` déjà enregistrés. Elle est écrite en **PHP** (`mb_*`, lecture puis `UPDATE` des seules lignes qui changent, gardé par l'ancien nom), jamais avec `upper()` de SQL : la locale de PostgreSQL sur l'image Alpine de production traite mal les accents. Elle ne touche ni aux slugs, ni à `slugged_name`, ni à la version des recettes ; données seulement, compatible avec la version précédente du code (ADR 0017 règle 6), rejouable sans effet, `down` sans effet. La règle y est recopiée (une migration reste stable seule) ; un test la compare à `StringTool::capitalizeFirst` sur tous les premiers caractères.
7. **Données de développement** : `ingredient.json`, `RecipeFixture` et `DemoRepasFixture` portent des noms déjà en majuscule ; `DevelopmentFixturesTest` le vérifie sur la base réelle.

## Conséquences

- Positives : des noms homogènes quel que soit le client (ancien site, front, Alexa, copie d'une recette) ; le déploiement du back avant celui du site ne casse rien ; aucune requête de plus (budgets SQL inchangés).
- Négatives / coûts : la règle est répétée dans cinq handlers plutôt que posée une fois dans le modèle ; un nom saisi en minuscules est affiché autrement qu'il n'a été envoyé (documenté dans le contrat OpenAPI) ; la copie en SQL de l'amorçage repose sur l'invariant « le catalogue est en majuscule ».
- Garde-fou : `NamesStartWithACapitalTest` échoue si une classe de `src/*/Application` appelle `Recipe::create(`, `Ingredient::create(`, `new Ingredient(`, `update(` ou `setName(` d'une recette ou d'un ingrédient sans `StringTool::capitalizeFirst(`, si une copie ne corrige pas le nom repris de l'original, ou si un modèle gagne une méthode publique qui reçoit un `$name` que le test ne connaît pas ; tests unitaires de chaque handler, du flux de l'API (nom en minuscules, `If-Match`) et de la migration sur une vraie base.

## Alternatives écartées

- La règle dans le modèle de domaine (`Recipe::create`, `Ingredient::update`…) : une seule place, mais le propriétaire a retenu les handlers, et le chargement d'une donnée existante (`load()`) ne doit rien juger.
- Refuser un nom en minuscules par une erreur de validation : l'ancien site, qui envoie des minuscules, ne pourrait plus rien écrire pendant le déploiement.
- Mettre le nom entier en minuscules, ou en « casse de titre » (chaque mot) : contraire à « on conserve la casse envoyée » (sigles, « T55 », noms propres).
- Migration en SQL (`upper(left(name, 1))`, `initcap`) : dépend de la locale de la base.
