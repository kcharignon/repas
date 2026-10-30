# REC-003 — Supprimer un type de recette, recettes favorites (back)

- **Dépôt** : **back** (cette fiche) ; le front est **REC-004** (`repas-front`, branche `feat/type-recette-supprimer-et-favoris`)
- **Taille** : M
- **Effort recommandé pour l'agent** : **high**
- **Dépend de** : —
- **Statut** : fait (PR de la branche `feat/type-recette-suppression-et-favoris`)
- **Lire d'abord** : [ADR 0034](../../adr/0034-supprimer-un-type-de-recette-et-recettes-favorites.md) ; `CLAUDE.md` (puces « Types de recette » et « Recettes favorites ») ; skill `repas-dev`.

## Contexte

Demande du propriétaire (2026-10-30, au soir) : « Je veux pouvoir supprimer un type de recette. Lors de la suppression tu me demandes sur quel type tout transférer » et « Je veux pouvoir mettre une recette en favoris (sur le détail d'une recette). Quand j'ai au moins une recette en favoris, affiche un type de recette fictif avec l'icône étoile, en première position (à gauche), qui liste les recettes mises volontairement en favoris. Si aucun favori, ce type est invisible. Une recette en favoris est affichée dans la liste des favoris et dans son type. »

## Contrat

| Route | Rôle | Réponse |
|---|---|---|
| `DELETE /api/v1/recipe-types/{slug}?transferTo=…` | administrateur | `204` ; `404` type ou cible inconnus ; `422` sans `transferTo` ou cible identique |
| `PUT /api/v1/recipes/{id}/favorite` `{favorite: boolean}` | auteur de la recette | `204` ; `403` non-auteur (`RECIPE_FAVORITE_FORBIDDEN` pour un administrateur) ; `404` inconnue ou secrète d'un autre ; `412` version périmée ; `422` corps non booléen |
| `GET /api/v1/recipes/favorites` | tout utilisateur | `{member: [{id, name, secret}], totalItems}` |
| `GET /api/v1/recipes/{id}` | — | gagne `favorite: boolean` |

## Critères d'acceptation

- [x] Supprimer un type déplace les recettes de tous les utilisateurs vers la cible (version +1) et rien n'est écrit en cas de refus.
- [x] Une recette favorite est dans `GET /recipes/favorites` ET dans `GET /recipe-types/{slug}/recipes`.
- [x] Seul l'auteur change le choix ; la copie n'est jamais favorite ; une recette secrète reste secrète.
- [x] Migration additive `Version20261031100000`, compatible avec le code précédent.
- [x] Budgets SQL `supprimer-type-recette` (4) et `mettre-recette-en-favoris` (9).
