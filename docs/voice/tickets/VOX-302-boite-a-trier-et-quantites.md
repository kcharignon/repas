# VOX-302 — Facultatif : boîte à trier (produits introuvables) et quantité dite

- **Dépôt** : back puis front
- **Jalon** : M3
- **Taille** : M
- **Dépend de** : VOX-301
- **Statut** : facultatif, à proposer à l'utilisateur après la recette

## Idée

1. Un produit introuvable (« xyz ») n'est pas perdu : il va dans une boîte à trier (`voice_unmatched(user_id, phrase, count, last_at)`), montrée dans le front par une bannière « 2 produits à vérifier » : l'utilisateur l'associe à un ingrédient (mémoire de phrase, VOX-301) ou le crée (ingrédient personnel, flux existant).
2. Quantité dite : « trois tomates », « deux kilos de pommes » → créneau `AMAZON.NUMBER` + unité ; MVP : nombre d'unités d'achat seulement ; unités avec conversion ensuite.

À cadrer avec `repas-po` avant tout code (le parcours doit rester à deux gestes).
