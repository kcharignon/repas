# LST-006 — Front : Moi > Types de liste

- **Dépôt** : front (`repas-front`) — **Taille** : M — **Effort** : high — **Dépend de** : LST-002, LST-003 fusionnés (`make api-update` sur le commit du back)
- **Lire d'abord** : skills `repas-front-dev`, `repas-po` ; ADR 0036 ; maquette (écrans 2 et 3) ; issue #380 § 6 (textes exacts).

## À faire

- Entrée **Types de liste** dans Moi (sous-titre « Sépare tes courses par magasin. »), écran des types (liste, « Liste principale » en bas non cliquable, « Ajouter un type »), fiche d'un type (nom, un interrupteur par rayon avec « Dans {type} » pour un rayon déjà pris, produits à part avec « retirer », « Enregistrer », « Supprimer ce type » avec confirmation « Sa liste en cours passe dans Mes listes. Rien n'est perdu. »).
- Erreurs en trois phrases (limite de 10, nom déjà pris). Tests unitaires et e2e ; captures lues à 360 px.

## Critères d'acceptation

- « Moi, Types de liste, Ajouter un type, nom, Enregistrer » : 5 touches, un type est créé ; cocher un rayon l'y range ; le déplacer depuis un autre type se voit.
- Un compte sans type voit « Ajouter un type » et rien d'autre de nouveau dans le reste de l'application.
