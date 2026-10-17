# DRV-007 — Relevé de la vraie requête d'ajout au panier

- **Dépôt** : — (tâche humaine ; rapport dans le dépôt back)
- **Jalon** : M0 Découverte
- **Taille** : S
- **Dépend de** : —
- **Statut** : fait (relevé complet le 2026-10-17)
- **Lire d'abord** : [`../spikes/DRV-007-requete-ajout-panier.md`](../spikes/DRV-007-requete-ajout-panier.md), [`../spikes/DRV-002-rapport.md`](../spikes/DRV-002-rapport.md) (ligne 5)

## Contexte

Le 2026-10-17, l'ajout au panier du banc d'essai a répondu 404 sur le site réel (hôte `fd7`), alors que la lecture (recherche, prix, stock) marche. Sans écriture dans le panier, l'agent ne sert à rien : c'est le point bloquant de la décision G1.

## Portée

Suivre la procédure du rapport (outils de développement de Chrome sur ordinateur, onglet Réseau) pour relever la requête que le site envoie lui-même à l'ajout et au retrait d'un produit, remplir le tableau, ouvrir une PR docs. Essayer aussi l'ajout du banc avec « objet d'analytique vide » coché.

## Hors portée

Toute modification de code. Tout identifiant, cookie, jeton ou numéro de magasin dans le rapport.

## Critères d'acceptation

- Le tableau est rempli pour l'ajout et le retrait (« non vu » est une réponse).
- Aucun `Cookie`, `Set-Cookie`, `Authorization`, jeton, numéro de magasin ni donnée personnelle n'est copié.
- La conclusion dit ce qui diffère de la requête du banc.
