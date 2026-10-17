# DRV-004 — Relevé des en-têtes de sécurité de leclercdrive.fr

- **Dépôt** : — (tâche humaine ; rapport dans le dépôt back)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : —
- **Statut** : à faire
- **Lire d'abord** : [`../spikes/DRV-004-entetes.md`](../spikes/DRV-004-entetes.md)

## Contexte

La politique de sécurité (CSP) et les en-têtes de cadre de Leclerc décident de plusieurs choix : le favori peut-il tourner (script en ligne), un cadre de même origine est-il possible (choix du produit en P1), l'ouverture depuis notre origine garde-t-elle ses liens (COOP). Ces en-têtes sont les mêmes sur ordinateur et sur téléphone ; un ordinateur est plus simple pour les lire.

## Portée

Avec les outils de développement d'un navigateur de bureau (F12, onglet Réseau), relever les en-têtes de réponse listés dans le gabarit pour trois pages (accueil du magasin, recherche, panier ou récapitulatif) et remplir `docs/drive/spikes/DRV-004-entetes.md`. Ouvrir une PR docs.

## Hors portée

Toute interprétation poussée (faite en DRV-005).

## Critères d'acceptation

- Le tableau est rempli pour les trois pages (« absent » est une réponse).
- L'adresse (sans identifiants) de la page panier ou récapitulatif est notée.
- Aucun `Cookie`, `Set-Cookie`, `Authorization` ni jeton n'a été copié.
