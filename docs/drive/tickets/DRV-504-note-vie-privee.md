# DRV-504 — Note de vie privée

- **Dépôt** : back + front
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-204
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 10 ; page de confidentialité ou mentions existantes du front si elles existent.

## Portée

Texte clair en français, affiché dans l'aide du drive et dans les réglages : ce que Repas enregistre (magasin choisi, produits mémorisés par ingrédient, ingrédients « toujours chez moi », statut et date d'envoi d'une liste), ce qu'il n'enregistre jamais (identifiants, mots de passe, cookies, adresse, créneau, paiement, contenu du panier Leclerc), où et combien de temps, comment tout supprimer (suppression du compte ; « Oublier » par produit), et que la fonction agit dans la session Leclerc de l'utilisateur sans passer par nos serveurs. Mettre à jour l'éventuel registre des traitements du projet.

## Critères d'acceptation

Le texte est relu, cohérent avec les invariants, sans promesse inexacte ; il est accessible depuis `/drive/reglages` et `/drive/aide`.

## À dire aussi (constat CGU du 2026-10-17, [DRV-003](../spikes/DRV-003-cgu.md))

Le texte doit prévenir chaque utilisateur que l'envoi au drive agit dans sa propre session Leclerc, que les conditions de Leclerc permettent à l'enseigne de suspendre un compte E.Leclerc (et la cagnotte de Tickets associée) en cas d'usage jugé abusif, que Repas ne voit jamais ses identifiants et que l'envoi se fait à faible cadence, à sa demande, pour sa seule liste.
