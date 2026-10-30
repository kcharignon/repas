# DRV-730 — `offerId` facultatif dans l'OpenAPI et marge de temps du test Alexa instable

- **Dépôt** : back (`src/Drive/`, `docs/api/openapi.json`, `tests/Voice/`)
- **Jalon** : M7 Plusieurs enseignes (suite de DRV-721)
- **Taille** : S
- **Dépend de** : DRV-721
- **Statut** : fait. Effort recommandé : `medium`.
- **Lire d'abord** : le rapport de DRV-722 (PR front #251) ; `.claude/skills/repas-dev/SKILL.md`.

## Contexte

1. Le contrat OpenAPI marque `offerId` **requis** dans `DrivePlanProductDTO` et `ChoiceProductDTO` (artefact du `default: null` du générateur) alors que la clé est **absente** quand le produit n'a pas d'offre. Le front type ses produits de test par assertion pour le contourner.
2. Le test `Repas\Tests\Voice\InputAdapter\AlexaWebhookApiFlowTest::testARequestForAnotherSkillOrAStaleOneIsRefusedEvenIfCorrectlySigned` a échoué une fois en CI (« horodatage dans 151 s » accepté : `Failed asserting that 200 is identical to 400`) : il se joue à une seconde de la limite de 150 s.

## Portée

1. Retirer le `default: null` d'`offerId` dans les deux DTO et régénérer l'OpenAPI (`make openapi`, relire le diff : `offerId` n'est plus requis). Prévenir le front (ses assertions de type pourront être retirées).
2. Donner une marge au test Alexa (par exemple 160 s au lieu de 151 s, ou une horloge figée) sans affaiblir la règle des 150 s, qui reste testée à 149 s (accepté) et 151 s avec horloge figée (refusé).

## Critères d'acceptation

OpenAPI : `offerId` absent de `required` ; `make test` et `make quality` verts ; le test Alexa ne dépend plus de l'horloge réelle à la seconde près.
