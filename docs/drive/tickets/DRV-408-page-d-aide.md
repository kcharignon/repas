# DRV-408 — Page d'aide : installer et lancer le favori

- **Dépôt** : front
- **Jalon** : M4 Front
- **Taille** : S
- **Dépend de** : DRV-403
- **Statut** : à faire
- **Lire d'abord** : DRV-403 ; rapport DRV-002 (ce qui marche réellement sur Chrome Android).

## Portée

Route `/drive/aide`, en français, tutoiement, phrases courtes, étapes numérotées avec captures d'écran **inventées ou prises sur Repas** (jamais de capture de Leclerc avec compte visible) :
1. Installer le favori (copier le code, créer un favori, coller le code dans l'adresse).
2. Ouvrir le drive Leclerc dans Chrome (pas dans l'application installée de Repas), se connecter.
3. Lancer le favori en tapant son nom dans la barre d'adresse et en choisissant l'entrée « javascript: ».
4. Que faire en cas de problème : le favori ne se lance pas, « connecte-toi », « Leclerc a ralenti les requêtes », « le site a changé », comment lancer le diagnostic et copier le rapport.
5. Ce que Repas fait et ne fait pas (ne valide pas la commande, ne paie pas, ne vide pas le panier, ne stocke pas tes identifiants).

## Critères d'acceptation

La page est accessible sans compte Leclerc, lisible sur 360 px, testée visuellement et en accessibilité ; chaque message d'erreur de l'agent renvoie vers la section qui l'explique.

## Definition of Done

Celle du front.
