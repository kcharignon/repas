# VOX-302 — Facultatif : boîte à trier (produits introuvables) et quantité dite

- **Dépôt** : back puis front
- **Jalon** : M3
- **Taille** : M
- **Dépend de** : VOX-301
- **Statut** : facultatif, à proposer à l'utilisateur après la recette

## Idée

1. Un produit introuvable (« xyz ») n'est pas perdu : il va dans une boîte à trier (`voice_unmatched(user_id, phrase, count, last_at)`), montrée dans le front par une bannière « 2 produits à vérifier » : l'utilisateur l'associe à un ingrédient (mémoire de phrase, VOX-301) ou le crée (ingrédient personnel, flux existant).
2. ~~Quantité dite : « trois tomates », « deux kilos de pommes » → créneau `AMAZON.NUMBER` + unité~~ **fait le 2026-10-25** (décision du propriétaire), voir le point 11 de [VOX-303](VOX-303-produit-inconnu-propositions-et-creation.md) : quantité de 1 à 99, unité dite utilisée si l'ingrédient la connaît, conversion dans l'unité d'une ligne existante.

À cadrer avec `repas-po` avant tout code (le parcours doit rester à deux gestes).
