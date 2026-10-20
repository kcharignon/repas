# DRV-649 — Bouton « Ajouter à la liste » compact

- **Dépôt** : front (`src/features/recipes/PlanButton.vue`, `src/design/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : —
- **Statut** : en cours (agent lancé le 2026-10-20, demande de l'utilisateur)

## Contexte

L'utilisateur, 2026-10-20 : le bouton « + Ajouter à la liste » prend trop de place, il est trop large. Il laisse l'assistant proposer plusieurs designs et trancher.

## Options étudiées

| Option | Place | Lisibilité | Verdict |
|---|---|---|---|
| A. Bouton rond de 44 px, icône seule (+ puis ✓) | minimale | bonne : « + » sur une carte de recette est un geste courant, l'état est visible | **retenu pour les cartes** |
| B. Pastille courte « Ajouter » avec icône | moyenne | explicite | **retenu pour la fiche d'une recette** (la place existe, le geste est l'action principale de la page) |
| C. Puce « + Liste » | moyenne | jargon | écartée |
| D. Bouton superposé au coin de la carte | nulle | cache le contenu, cible tactile délicate | écartée |
| E. Balayage de la carte | nulle | invisible | écartée : rien ne dit qu'il existe |

## Décision

- Cartes de la liste des recettes : bouton rond 44 × 44 px, « + » (contour) devenant « ✓ » (fond doux, icône primaire) ; `aria-label` complet (« Ajouter {nom} à la liste de courses »), `aria-pressed`, `title` identique. Le nom de la recette récupère la largeur.
- Fiche d'une recette : pastille « Ajouter » / « Dans la liste ».
- Jetons du design système uniquement, clair et sombre, cible ≥ 44 px, aucun changement de logique.

## Critères d'acceptation

- Given la liste des recettes sur 360 px, When un nom de recette est très long, Then il passe à la ligne sans troncature brutale et le bouton reste de 44 px.
- Given une lectrice d'écran, Then le nom du bouton reste « Ajouter {nom} à la liste de courses ».
