# DRV-006 — Test d'installation d'un favori sur le téléphone

- **Dépôt** : — (tâche humaine ; rapport dans le dépôt back)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : —
- **Statut** : à faire
- **Lire d'abord** : [`../spikes/DRV-006-installation-favori.md`](../spikes/DRV-006-installation-favori.md), [`../analyse.md`](../analyse.md) (risque 11, décision D20)

## Contexte

Le 2026-10-17, l'utilisateur n'a pas pu installer le favori du banc d'essai (49,6 Ko) sur Chrome Android : pas de synchronisation depuis l'ordinateur, chaîne coupée au collage sur le téléphone. L'agent final étant plus gros, l'installation d'un favori « complet » est impossible ou fragile. Il faut connaître la limite réelle avant de choisir comment livrer l'agent (voir D20).

## Portée

Suivre le « Test de taille » du rapport (quatre favoris de 2, 8, 16 et 32 Ko créés sur l'ordinateur, lancés depuis le téléphone), remplir le tableau et la conclusion, ouvrir une PR docs.

## Hors portée

Toute modification de code.

## Critères d'acceptation

- Le tableau est rempli (« non testé » avec la raison si impossible).
- La plus grande taille installable est notée.
- Le modèle du téléphone et la version de Chrome sont notés.
