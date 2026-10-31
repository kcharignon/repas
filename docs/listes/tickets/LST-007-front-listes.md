# LST-007 — Front : pastilles de listes, produit à part, message

- **Dépôt** : front — **Taille** : L — **Effort** : high — **Dépend de** : LST-004, LST-005, LST-006
- **Lire d'abord** : skills `repas-front-dev`, `repas-po` ; ADR 0036 ; ADR front 0006 (hors ligne) ; maquette (écrans 1, 4 et 5).
- **Amendé le 2026-10-31 (LST-010, demande du propriétaire)** : les pastilles de l'écran « Liste » sont retirées, l'écran montre une seule liste et on change de liste par « Mes listes » (pastille en tête de l'écran). Le reste de ce ticket est inchangé.

## À faire

- **Liste** : pastilles « Principale · types » avec le nombre de produits à cocher, seulement si au moins un type a une liste ouverte ; le bouton du bas agit sur la liste affichée (« Terminer cette liste », envoi au drive de cette liste) ; sans type, l'écran est identique à aujourd'hui.
- **Fiche d'un produit** : champ « Liste de courses » (Comme son rayon avec le nom de la liste dessous / Liste principale / un choix par type), visible si le compte a au moins un type ; enregistrer le produit puis la destination (seulement si elle a changé).
- **Après avoir planifié une recette** : message « Ajouté à Boucherie, Marché et Liste principale. » (une seule : « Ajouté à Boucherie. »), action Voir.
- **Hors ligne** : toutes les listes ouvertes restent lisibles ; invalidation de toutes les listes après une planification ; « Mes listes » montre le nom du type.
- Erreur « Une des listes de ce repas est partie au drive. Rouvre-la, puis réessaie. ».

## Critères d'acceptation

- Les critères d'acceptation de l'issue #380 (§ 9) passent à la main sur le vrai back, mobile et bureau, captures lues.
