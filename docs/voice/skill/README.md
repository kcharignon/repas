# Paquet du skill Alexa « mes courses »

Deux fichiers, à coller dans la console Amazon (ticket [VOX-002](../tickets/VOX-002-compte-amazon-et-skill.md)). Ils ne contiennent aucun secret.

| Fichier | À quoi il sert | Où on le colle |
|---|---|---|
| `interaction-model.fr-FR.json` | le nom d'invocation, les intentions (ajouter un article, répondre aux questions d'un produit inconnu, aide, stop, oui, non…) et les types de valeurs `INGREDIENT`, `DEPARTMENT`, `UNIT`, `OPTION_RANK`. **Généré** par `generate-model.mjs`, jamais modifié à la main | console → *Build* → *Interaction Model* → *JSON Editor* → *Save* puis *Build* |
| `skill.json` | le manifeste (nom, description, adresse du webhook). **Seulement si l'on utilise l'ASK CLI** ; avec la console on saisit ces champs à la main | `ask deploy` (facultatif) |
| `generate-model.mjs` | le script qui produit le modèle d'interaction (node pur, aucune dépendance, aucun appel réseau) | `make voice-model` pour l'écrire, `make voice-model-check` pour vérifier qu'il est à jour |

## Régénérer le modèle

```
make voice-model          # node docs/voice/skill/generate-model.mjs : écrit le JSON, relis le diff
make voice-model-check    # n'écrit rien, échoue si le JSON n'est pas celui que le script produit
```

Le script lit `docs/ingredients/base-ingredients.tsv` (type `INGREDIENT`), `src/Repas/Infrastructure/DataFixture/Data/department.json` et `unit.json` (types `DEPARTMENT` et `UNIT`, dont l'identifiant de chaque valeur est le **slug** du rayon ou de l'unité). Un rayon ou une unité sans synonymes dans le script fait échouer le script : on écrit ses synonymes dans les tables du début du fichier. Node doit être installé sur la machine (il ne l'est pas dans le conteneur `php`, et la CI ne lance pas le script ; `DocsVoiceSkillTest` vérifie côté PHP que les identifiants du modèle sont ceux des fixtures).

## Le modèle d'interaction

- **Nom d'invocation** : `mes courses` (défaut de la décision D1 de l'[ADR 0029](../../adr/0029-commande-vocale-alexa.md)). S'il change, modifier la constante `INVOCATION_NAME` du script, puis `alexa.md` § 2. La console peut refuser un nom : prévoir un second choix.
- **Intentions** (douze, exactement celles de `AlexaIntent`, que `DocsVoiceSkillTest` impose) :

  | Intention | Créneau (type) | Quand |
  |---|---|---|
  | `AddItemIntent` | `item` (`INGREDIENT`) | « ajoute du pain » ; en séance, la réponse brève « du pain » ou « pain » |
  | `ChooseOptionIntent` | `rank` (`OPTION_RANK`) | « le deuxième » : choisir l'une des trois propositions |
  | `CreateItemIntent` | — | « un nouveau produit », « aucun », « aucun des trois », « crée-le », « ni l'un ni l'autre » |
  | `ListChoicesIntent` | — | « lesquels », « lesquelles », « la liste », « je ne sais pas », « énumère » : aux questions du rayon et de l'unité, lire tous les choix (douze phrases d'exemple) |
  | `AnswerDepartmentIntent` | `department` (`DEPARTMENT`) | « fruits et légumes » : la réponse à « Dans quel rayon ? » |
  | `AnswerUnitIntent` | `unit` (`UNIT`) | « des pièces », « en kilos » : la réponse à « Quelle unité ? » |
  | `AMAZON.YesIntent`, `AMAZON.NoIntent` | — | « oui », « non » : à « Tu veux dire … ? », « Je le crée ? », « C'est bon ? » |
  | `AMAZON.HelpIntent`, `AMAZON.StopIntent`, `AMAZON.CancelIntent`, `AMAZON.FallbackIntent` | — | comme d'habitude |

  `LaunchRequest` et `SessionEndedRequest` n'ont pas de phrase : Amazon les envoie seul. **Alexa choisit l'intention sans connaître l'état de notre dialogue** : « persil » peut arriver comme `AddItemIntent` ou comme `AnswerUnitIntent`. Le webhook interprète donc toujours la réponse d'après l'**état** de la séance (`SpokenAnswerInterpreter`), pas d'après l'intention seule.
- **Phrases** : `AddItemIntent` a sept verbes (ajoute, ajouter, mets, mettre, rajoute, rajouter, note) × six articles (aucun, du, de la, des, un, une), avec et sans « à la liste » (84 phrases), cinq formes courtes (« du {item} »…) et la forme réduite au créneau `{item}` : 90 phrases. Les quatre autres intentions à créneau ont de 6 à 10 phrases d'exemple, dont la **forme réduite au créneau** (`{rank}`, `{department}`, `{unit}`) pour les réponses brèves ; `CreateItemIntent` en a dix.
- **Types** : `INGREDIENT` (les 260 noms du catalogue de base, sans doublon) **oriente** la reconnaissance sans la limiter : Alexa envoie ce qu'elle entend, même hors liste, et c'est le webhook qui choisit l'ingrédient de l'utilisateur (`SpokenIngredientRanker`). `DEPARTMENT` (les 21 rayons) et `UNIT` (les 25 unités) ont chacun un `id` (le slug) et des **synonymes** parlés (« légumes », « fruits » → `fruit-et-legume` ; « pièce », « pièces », « unités » → `unite` ; « kilos » → `kilo`…) : la résolution d'entité d'Alexa rend cet `id` dans `request.intent.slots.<nom>.resolutions.resolutionsPerAuthority[].values[].value.id` quand `status.code` vaut `ER_SUCCESS_MATCH`, et le webhook le lit ; à défaut il rapproche le texte entendu des noms du catalogue. `OPTION_RANK` a trois valeurs d'`id` « 1 », « 2 », « 3 » (premier, deuxième/second, troisième/dernier, un, deux, trois).
- **Écriture des valeurs** : Amazon refuse chiffres et parenthèses dans les valeurs d'un type personnalisé (les `id` peuvent être des chiffres). Les noms sont donc écrits comme on les dit (« couche taille quatre », « lait premier âge », « sauce quatre fromages », « fruit et légume »), et « cone 3d » est absent. Les noms propres à chaque utilisateur ne sont pas dans le modèle (pas de liste par utilisateur dans cette version).

## Le test de cohérence

`tests/Voice/DocsVoiceSkillTest.php` (lancé par `make test`) vérifie, sans rien appeler chez Amazon : que les deux JSON sont valides ; que les intentions du modèle sont exactement celles du webhook ; que chaque intention a son créneau, du bon type, et de 6 à 10 phrases d'exemple bien formées dont la forme réduite au créneau ; que les valeurs et synonymes de chaque type sont uniques, sans chiffre ni parenthèse ni majuscule, de 140 caractères au plus ; que les `id` de `DEPARTMENT` et `UNIT` sont ceux des fixtures et ceux d'`OPTION_RANK` « 1 » à « 3 » ; que `INGREDIENT` couvre le catalogue de base ; que le manifeste désigne la route du webhook, sans permission ni accès à la liste d'Amazon.

## Après un changement

Reconstruire dans la console (*Save* puis *Build*) puis rejouer, avec l'Echo, une phrase de chaque intention (VOX-602). Un changement du catalogue de base n'oblige pas à mettre le modèle à jour : il ne sert qu'à orienter la reconnaissance. Un changement des rayons ou des unités (`department.json`, `unit.json`) oblige à régénérer le modèle (`make voice-model`) et à le recoller.

## Réserves (non vérifiables sans la console)

- **Phrases réduites au seul créneau** (`{item}`, `{rank}`, `{department}`, `{unit}`) : le modèle les contient pour que les réponses brèves d'une séance (« du pain », « le deuxième », « fruits et légumes ») reconnaissent la bonne intention. La console peut les refuser (« une phrase ne peut pas se réduire à un créneau »). Dans ce cas : `node docs/voice/skill/generate-model.mjs --no-bare`, puis régénérer et recoller ; `DocsVoiceSkillTest` exige aujourd'hui ces formes : l'assouplir dans ce cas. Les formes avec article (« du {item} ») restent, et le webhook interprète d'après l'état même quand Alexa envoie `FallbackIntent`.
- **Les règles du nom d'invocation en français**, le **nombre de valeurs permis par type**, le **nombre de synonymes**, la forme exacte des champs de `skill.json` : la console fait foi.
- **Reconnaissance des rangs et des réponses** (« le deuxième », « un nouveau produit », « aucun des trois ») : à essayer à voix haute à la recette (VOX-602).
