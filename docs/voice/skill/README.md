# Paquet du skill Alexa « mes courses »

Deux fichiers, à coller dans la console Amazon (ticket [VOX-002](../tickets/VOX-002-compte-amazon-et-skill.md)). Ils ne contiennent aucun secret.

| Fichier | À quoi il sert | Où on le colle |
|---|---|---|
| `interaction-model.fr-FR.json` | le nom d'invocation, l'intention `AddItemIntent` (ses phrases), les intentions d'Amazon (aide, stop, annuler, repli) et le type `INGREDIENT` | console → *Build* → *Interaction Model* → *JSON Editor* → *Save* puis *Build* |
| `skill.json` | le manifeste (nom, description, adresse du webhook). **Seulement si l'on utilise l'ASK CLI** ; avec la console on saisit ces champs à la main | `ask deploy` (facultatif) |

## Le modèle d'interaction

- **Nom d'invocation** : `mes courses` (défaut de la décision D1 de l'[ADR 0029](../../adr/0029-commande-vocale-alexa.md)). S'il change, modifier le champ `invocationName` à la main, ici et dans `alexa.md` § 2. La console peut refuser un nom : prévoir un second choix.
- **Intentions** : `AddItemIntent` (créneau `item` de type `INGREDIENT`) ; `AMAZON.HelpIntent`, `AMAZON.StopIntent`, `AMAZON.CancelIntent`, `AMAZON.FallbackIntent`. `LaunchRequest` et `SessionEndedRequest` n'ont pas de phrase : Amazon les envoie seul. Le webhook (VOX-204) gère exactement ces cinq intentions : `AlexaIntent` les liste, et `DocsVoiceSkillTest` échoue si le modèle et le webhook ne déclarent pas les mêmes.
- **Phrases** de `AddItemIntent` : sept verbes (ajoute, ajouter, mets, mettre, rajoute, rajouter, note) × six articles (aucun, du, de la, des, un, une), avec et sans « à la liste » : 84 phrases.
- **Type `INGREDIENT`** : les 260 noms du catalogue de base (`docs/ingredients/base-ingredients.tsv`, colonne `nom`), sans doublon. Il **oriente** la reconnaissance vocale mais ne la limite pas : Alexa envoie ce qu'elle entend, même hors liste, et c'est le webhook qui choisit l'ingrédient de l'utilisateur (`SpokenIngredientRanker`).
- **Écriture des valeurs** : Amazon refuse chiffres et parenthèses dans un type personnalisé. Les noms sont donc écrits comme on les dit (« couche taille quatre », « lait premier âge », « sauce quatre fromages »), et « cone 3d » est absent. Les noms propres à chaque utilisateur ne sont pas dans le modèle (pas de liste par utilisateur dans cette version).

## Le test de cohérence

`tests/Voice/DocsVoiceSkillTest.php` (lancé par `make test`) vérifie, sans rien appeler chez Amazon : que les deux JSON sont valides ; que les intentions du modèle sont exactement celles du webhook ; que le créneau `item` et ses phrases sont bien formés ; que les valeurs du type `INGREDIENT` sont uniques, sans chiffre ni parenthèse, de 140 caractères au plus, et couvrent le catalogue de base ; que le manifeste désigne la route du webhook, sans permission ni accès à la liste d'Amazon.

## Après un changement

Reconstruire dans la console (*Save* puis *Build*) puis rejouer, avec l'Echo, une phrase de chaque intention (VOX-602). Un changement du catalogue de base n'oblige pas à mettre le modèle à jour : il ne sert qu'à orienter la reconnaissance.

## Réserves

- **Réponse courte en séance** : toutes les phrases de `AddItemIntent` commencent par un verbe (« ajoute du pain »). Après « Que veux-tu ajouter ? », répondre « du pain » seul risque de n'y correspondre à aucune phrase : Alexa enverrait `AMAZON.FallbackIntent` et répondrait « Dis par exemple : ajoute du pain. ». À essayer sur l'Echo (VOX-602) ; si besoin, ajouter au modèle des formes courtes (« du {item} », « des {item} », « un {item} »…). Une phrase réduite au seul créneau est à éviter (non vérifié : la console peut la refuser).

À vérifier à la création du skill (VOX-002), aucune n'a pu l'être sans la console : les règles du nom d'invocation en français, le nombre de valeurs permis par type, la forme exacte des champs de `skill.json` (la console fait foi).
