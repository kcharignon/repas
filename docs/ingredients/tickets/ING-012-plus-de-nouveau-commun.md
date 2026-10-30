# ING-012 — Plus de nouveau commun : l'administrateur crée ses ingrédients comme tout le monde

- **Dépôt** : back
- **Jalon** : I6 Plus d'ingrédient commun
- **Taille** : S
- **Effort recommandé pour l'agent** : **high** (change qui possède ce qui est créé ; pas de schéma)
- **Dépend de** : ING-011 (mêmes fichiers : `CreateIngredientHandler`, `IngredientPayload`)
- **Statut** : fait (PR #243)
- **Sortie** : **R1**
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-20 (décision 2) ; [`sans-commun.md`](../sans-commun.md) §§ 2, 7, 9 ; skill `/repas-dev`.

## Contexte

Aujourd'hui un administrateur qui crée un ingrédient crée un **commun** (`creator` vide, visible de tous). La passation (ING-009) fige le stock des anciens communs à 261 ; tant que ce « robinet » reste ouvert, un commun créé entre-temps ferait échouer ses préconditions (P5), et après la passation il recréerait un ingrédient sans créateur. Ce ticket ferme le robinet **avant** : l'administrateur crée des ingrédients à lui. C'est la première marche de « l'administrateur est un utilisateur comme les autres » ; le reste (droits, fiche, conversions) attend la passation (ING-013).

## Portée

1. `CreateIngredientHandler` : `creatorId = $command->userId` **toujours**. `CreateIngredientCommand::$isAdmin` est retiré, `CreateIngredientApiController` ne le transmet plus ; l'alias de même clé que le slug créé est toujours effacé ; `IngredientCreatedEvent` est toujours dispatché (le compteur « ingrédients créés » de l'administrateur compte ses créations).
2. **Même nom, même compte : `409`** pour tous (*règle précisée par ING-016, 2026-10-30 : même nom ET même rayon ; `existsForCreator` est remplacé par `creatorHasNameInDepartment`*). Le slug d'un ingrédient créé est `nom + identifiant du créateur` ; les anciens communs ont un slug **sans** identifiant, donc le propriétaire du catalogue pourrait recréer « Tomate » sans que `exists(slug)` le voie. Une **nouvelle** méthode de port `IngredientRepository::existsForCreator(slug, creatorId, sluggedName)` répond, en une **seule** requête, « le slug existe, **ou** le créateur a déjà un ingrédient de même `slugged_name` » (budget inchangé) ; `CreateIngredientHandler` seul l'appelle. `exists(slug)` ne change **pas** : l'adoption, la copie de recette et la fusion s'en servent pour tout autre chose (collision de slug, rejeu d'une fusion). Effet pour les autres comptes : un ingrédient dont le nom normalisé est déjà à eux est refusé (`409` `INGREDIENT_ALREADY_EXISTS`), ce qui était déjà le cas pour le même nom exact.
3. **Documentation de l'API** : résumés et descriptions de `POST /api/v1/ingredients` (« Crée un ingrédient de l'utilisateur », sans parler de commun), `make openapi`, diff relu (texte seulement).
4. `CLAUDE.md` / skill : la phrase « un administrateur crée des ingrédients communs » (ADR 0013 règle 8, `CreateIngredientCommand::isAdmin`) devient « le contexte `Repas` n'a plus besoin de savoir qu'un utilisateur est administrateur pour créer un ingrédient ».
5. Test d'architecture `NoNewCommonIngredientTest` : aucun `Ingredient::create(` de `src/*/Application` ne reçoit un créateur vide ou `null` (les fixtures et les tests de domaine sont exclus).

## Hors portée

`UpdateIngredientHandler`, `RemoveIngredientHandler`, `OwnerVoter` (le passe-droit administrateur reste jusqu'à ING-013) ; les conversions ; la visibilité ; le marqueur (ING-011).

## Critères d'acceptation

- Étant donné un administrateur, quand il crée « Safran », alors l'ingrédient existe avec `creator` = lui, un `IngredientCreatedEvent` est dispatché, et **aucun autre compte** ne le voit (liste du rayon, recherche, `GET /ingredients`).
- Étant donné un utilisateur ordinaire, la création est exactement celle d'avant (même slug, même événement).
- Étant donné le propriétaire qui a l'ancien commun « Tomate » (slug sans identifiant) et un ingrédient « Tomate » créé ensuite, alors la création répond `409` (nom déjà à lui) — **après la passation** ; avant elle, ses anciens communs ne sont pas à lui (`creator` vide) : le doublon est permis et sera proposé par « Doublons possibles ».
- Étant donné deux comptes qui créent chacun « Tomate », alors deux slugs distincts, aucun conflit.
- Le budget `creer-ingredient` ne monte pas ; un scénario `creer-ingredient-administrateur` est ajouté au même budget.
- `NoNewCommonIngredientTest` échoue si un handler d'application crée un ingrédient sans créateur.

## Tests exigés

`CreateIngredientHandlerTest` (en mémoire : administrateur, utilisateur, même nom normalisé, événement) ; `IngredientRepositoryTest` (`existsForCreator` : par slug, par nom normalisé du créateur, pas par celui d'un autre) ; controller (`isAdmin` n'est plus lu) ; parcours avec les vrais bus (administrateur crée, un utilisateur ne voit pas) ; `make test quality openapi-check`.

## Definition of Done

`repas-dev` § 4 ; PR « À relire » : le seul effet visible (un ingrédient créé par l'administrateur n'est plus partagé).

## Pièges

- **Rien à migrer** : les anciens communs restent sans créateur jusqu'à la passation ; ne pas toucher à `Ingredient::create(?string)` (le garde-fou de sa signature vient avec ING-014).
- Avec **l'ancien code** (retour arrière du code d'avant R1) l'administrateur recrée un commun : documenté dans `sans-commun.md` § 7, rejouer la passation le reprend.
- Le front n'envoie jamais `isAdmin` : rien à changer côté contrat de requête.

## Réalisation (PR back #243)

Livré comme décrit ci-dessus. **Rien n'a été exécuté en production**, aucun schéma ni aucune donnée ne change ; le seul effet visible est qu'un ingrédient créé par un administrateur n'est plus partagé.

**Comportement** : `POST /api/v1/ingredients` pose toujours `creator` = l'utilisateur du jeton (administrateur compris). Slug inchangé pour un utilisateur ordinaire (`slugify(nom + identifiant)`) ; pour un administrateur il passe de `slugify(nom)` (commun) à `slugify(nom + identifiant)`. `IngredientCreatedEvent` est toujours dispatché (le compteur « ingrédients créés » de l'administrateur compte ses créations) et l'alias de même clé que le slug créé est toujours effacé. `CreateIngredientCommand::$isAdmin` et sa transmission par le controller sont retirés ; **le corps de la requête ne change pas** (le front n'a jamais envoyé `isAdmin`) et les champs de réponse non plus. Codes : `201` + `Location`, `403` `INGREDIENT_BASE_FORBIDDEN` (ING-011, inchangé), `404` rayon ou unité inconnu, `409` doublon, `422` corps invalide ou coefficient manquant. Le texte du `409` reste celui de `IngredientException::alreadyExists()` (`Ingredient '<slug>' already exists`) : ce n'est pas un code stable, le front ne s'appuie que sur le statut.

**Doublon** : nouvelle méthode de port `IngredientRepository::existsForCreator(slug, creatorId, sluggedName)` : vrai si le slug existe **ou** si le créateur a déjà un ingrédient de même `slugged_name`, en **une seule requête** (`WHERE slug = :slug OR (creator = :creator AND slugged_name = :name) LIMIT 1`). Seul `CreateIngredientHandler` l'appelle ; `exists(slug)` n'a pas changé (adoption, copie de recette, fusion). Avant la passation un ancien commun (`creator` vide) n'est à personne : un propriétaire du catalogue peut créer « Tomate » à côté du commun « Tomate » (le doublon sera proposé par « Doublons possibles »). Après la passation (créateur = propriétaire, slug toujours sans identifiant) la même création répond `409`.

**Budgets** : `creer-ingredient` reste à 10 ; scénario `creer-ingredient-administrateur` ajouté à 10 ; `creer-ingredient-base` (11) inchangé. Aucune migration.

**Garde-fou** : `NoNewCommonIngredientTest` parcourt `src/*/Application` et échoue si un `Ingredient::create(` ne reçoit pas `creatorId: $variable` (jamais `null`, `''` ni une expression conditionnelle) ; il vérifie aussi que `CreateIngredientCommand` n'a plus de `isAdmin`. `Ingredient::create(?string)` garde sa signature (ING-014).

**Compatibilité avant, pendant, après la passation** : la visibilité, `OwnerVoter`, `canEdit`, les conversions, la copie de recette, la fusion et les 261 anciens communs ne sont pas touchés ; rien ne lit ni n'écrit `creator IS NULL` de plus. Les ingrédients créés ensuite sont à leur créateur sur les formes F0 et F1. Effets de bord qui découlent du modèle (aucun code n'a été ajouté pour eux) : la fusion fonctionne pour un administrateur sur **ses** ingrédients (le cas d'usage contrôle `creator`) ; `canEdit` est vrai pour l'administrateur sur ses ingrédients (créateur = lui) ; un autre administrateur ne voit pas ces ingrédients dans le rayon ni en recherche, mais peut encore lire la fiche, la modifier ou la supprimer par le passe-droit `ROLE_ADMIN` de `OwnerVoter`/`GetIngredient`, **jusqu'à ING-013** (voulu : hors portée).

**Écarts avec le ticket** : le critère « aucun autre compte ne le voit (… `GET /ingredients`) » est vrai pour un utilisateur ordinaire (`404`) et pour la liste du rayon et la recherche de tout autre compte ; le `GET` de la fiche par un **autre administrateur** reste permis jusqu'à ING-013 (passe-droit conservé comme l'exige « hors portée »). Rien d'autre.

**Points d'attention** :

- **ING-008** : l'administrateur (propriétaire du catalogue) a désormais des ingrédients à lui avec des slugs **avec** identifiant ; la règle d'amorçage « de base et créateur propriétaire ou vide » les inclut s'ils sont marqués ; l'amorçage ne doit pas supposer qu'un ingrédient du propriétaire a un slug sans identifiant.
- **ING-009** : P5 (« les slugs sans créateur sont exactement ceux du fichier ») voit tout commun recréé par un ancien code ; les ingrédients créés par le propriétaire depuis R1 ne sont pas dans le fichier mais ont un créateur, donc ne gênent pas P5 ; la requête de retour arrière reconnaît un ancien commun à son slug **sans** identifiant et à son origine vide : un ingrédient créé après R1 a un identifiant dans son slug, donc il n'est jamais remis « sans créateur ».
- **ING-015** : `canSetBase` est vrai dès qu'un ingrédient est créé par le propriétaire (avant la passation aussi) ; l'interrupteur « de base » peut donc apparaître sur un ingrédient récent avant la passation, ce qui est voulu.

**Non vérifié** : le comportement du code précédent (retour arrière avant R1) n'a pas été rejoué ; il recrée un commun (documenté dans `sans-commun.md` § 7).
