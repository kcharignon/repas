# VOX-205 — Paquet du skill : manifeste et modèle d'interaction `fr-FR`

- **Dépôt** : back (`docs/voice/skill/`)
- **Jalon** : M2
- **Taille** : S
- **Dépend de** : VOX-204 (les noms d'intentions et de créneaux), VOX-001 (nom d'invocation)
- **Faisable sans téléphone** : oui
- **Statut** : fait (PR #267, 2026-10-25)
- **Lire d'abord** : [`../alexa.md`](../alexa.md) § 2 ; documentation Amazon du modèle d'interaction (JSON : `interactionModel.languageModel`) ; `docs/ingredients/base-ingredients.tsv`.

## Portée

1. `docs/voice/skill/interaction-model.fr-FR.json` : `invocationName` (D1), intentions `AddItemIntent` (exemples : « ajoute {item} », « ajouter {item} », « mets {item} », « rajoute {item} », « ajoute {item} à la liste »), `AMAZON.HelpIntent`, `AMAZON.StopIntent`, `AMAZON.CancelIntent`, `AMAZON.FallbackIntent` ; type de valeurs `INGREDIENT` rempli avec les noms du catalogue de base (le fichier TSV) pour orienter la reconnaissance — **sans limiter** la valeur reconnue ; le créneau `item` est de ce type.
2. `docs/voice/skill/skill.json` : manifeste (nom, description en français, catégorie, `apis.custom.endpoint.uri`, `sslCertificateType: Trusted`), `permissions` vides, **sans** accès aux listes.
3. `docs/voice/skill/README.md` : comment coller le modèle dans la console (JSON Editor), comment reconstruire. Un test (`DocsVoiceSkillTest`) vérifie que le JSON est valide, que chaque intention du modèle est gérée par le webhook et que le type de valeurs ne contient que des noms uniques.

## Critères d'acceptation

- Le JSON se colle sans erreur dans la console (vérifié à VOX-002) ; aucun nom d'ingrédient de plus de 140 caractères ; au plus les valeurs permises par Amazon par type (relire la limite).

## Avancement

- 2026-10-25 : le paquet est écrit (`docs/voice/skill/` : modèle d'interaction, manifeste, mode d'emploi). Le test de cohérence (`DocsVoiceSkillTest` : JSON valide, chaque intention gérée par le webhook, valeurs uniques) est ajouté avec le webhook (VOX-204, PR #267), qui sait désormais les intentions que le modèle déclare. Il vérifie aussi le manifeste (route du webhook, aucune permission) et la couverture du catalogue de base. Ce que la console d'Amazon accepte (JSON collé, nombre de valeurs par type) reste à vérifier à VOX-002.
