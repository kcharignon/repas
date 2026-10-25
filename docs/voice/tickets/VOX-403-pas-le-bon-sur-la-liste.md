# VOX-403 — Front : « Ajouté par Alexa — pas le bon ? »

- **Dépôt** : front
- **Jalon** : M4
- **Taille** : S
- **Dépend de** : VOX-301
- **Statut** : à faire
- **Lire d'abord** : l'écran de la liste en cours ; la recherche d'ingrédients existante (`/api/v1/ingredients/search`) ; skill `repas-po`.

## Portée

Sur une ligne dont `origin = voice` et non cochée : une petite pastille « Alexa » et une action « Pas le bon ? » qui ouvre la recherche d'ingrédients existante ; le choix appelle `POST /api/v1/voice/corrections`. Rien d'autre à l'écran (pas d'explication sur l'apprentissage).

## Critères d'acceptation

- La pastille n'apparaît que sur les lignes vocales ; la correction remplace la ligne sans recharger la page ; liste envoyée au drive : l'action n'existe pas ; hors-ligne : l'action est masquée ; captures lues.
