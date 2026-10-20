# ING-008 — Adoption à la première utilisation, pour tous les utilisateurs

- **Dépôt** : back
- **Jalon** : I6 Plus tard
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (change le comportement de **tous** les comptes, nouveaux et anciens)
- **Dépend de** : ING-001, ING-002 et ING-003 **en production et stables** ; décision du propriétaire (voir « Contexte »)
- **Statut** : à faire, **à ne démarrer que sur décision du propriétaire**
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décisions a et b ; [`migration.md`](../migration.md) §§ 2 et 12 ; [`analyse.md`](../analyse.md) §§ 3 et 4 ; skills `/repas-dev`, `/repas-ops`.

## Contexte

Après ING-003, les utilisateurs **concernés** ont leurs ingrédients ; les autres (comptes sans recette ni ingrédient personnel, et **tous les comptes créés depuis**) utilisent encore les communs partagés. Le propriétaire a demandé qu'on ne leur copie rien « pour rien » (pas de 261 copies par compte). Ce ticket fait que **chaque utilisateur** adopte un commun **la première fois qu'il l'utilise**, de façon transparente : il ne voit rien de plus, mais son monde est le sien. C'est la condition d'ING-009.

## Portée

1. **Mode d'adoption** : `INGREDIENT_ADOPTION_MODE` (`adopters`, par défaut ; `all`), lu par `IngredientSlugResolver` d'ING-002 ; en `all`, **tout utilisateur non administrateur** est traité comme un adoptant : un commun reçu en écriture est adopté à la volée (alias `first-use`) et la copie est utilisée. L'humain l'allume (comme `DRIVE_ENABLED`), jamais l'agent ; documenté dans `docs/production.md`, le script `ecrire-…` suit le modèle `ecrire-drive-enabled.sh`.
2. **Masquage** : l'adoption à la première utilisation masque ce commun **pour cet utilisateur** (règle d'ING-002, inchangée) : au fil du temps, chacun voit son catalogue se constituer ; les communs qu'il n'a jamais utilisés restent visibles comme **suggestions** tant qu'il ne les a pas adoptés.
3. **Rattrapage** : relancer `repas:ingredients:adopt-commons` (ING-003) pour les utilisateurs devenus concernés depuis (une première recette créée) ; le rapport à blanc le dit.
4. **Contrôle de bout en bout** : un utilisateur créé après ING-008 qui crée une recette, ajoute des ingrédients à une liste et envoie la liste au drive n'a que des ingrédients **à lui** (`creator = lui`) dans ses lignes, et le plan d'envoi lit ses choix.
5. **Requête de santé** (lecture seule) à intégrer au mode `--check` d'ING-003 : nombre de lignes (recettes, listes, choix du drive) d'un **utilisateur adoptant** qui référencent encore un commun : doit être **0** ; sinon une écriture n'a pas été résolue (ING-002 D).

## Hors portée

Changer les communs en ingrédients de l'administrateur (ING-009) ; copier le catalogue à l'inscription (écarté, [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md)) ; modifier le front.

## Critères d'acceptation

- Étant donné `INGREDIENT_ADOPTION_MODE=all` et un utilisateur sans alias : créer une recette avec `tomate` enregistre `tomate<U>` ; ajouter `tomate` à la liste, idem ; l'alias a `source = first-use`.
- Étant donné `adopters` : comportement d'ING-002 inchangé (un non-adoptant garde `tomate`).
- Un administrateur n'adopte rien dans aucun mode.
- Deux requêtes simultanées qui adoptent le même commun pour le même utilisateur : une seule copie (clé primaire + `ON CONFLICT`), les deux requêtes réussissent.
- Basculer `all` -> `adopters` n'annule rien : les alias existants continuent de masquer et de résoudre.
- Les budgets des écritures concernées montent d'une adoption au plus (une première fois seulement).

## Tests exigés

`IngredientSlugResolverTest` par mode ; handlers en mémoire ; base réelle (concurrence simulée par deux appels successifs sur le même alias) ; parcours bout en bout nouvel utilisateur ; `make test quality`.

## Definition of Done

`repas-dev` § 4 ; ADR 0028 : décision b passe à « livrée » ; `docs/production.md`.

## Pièges

- **Ne pas allumer avant que la migration soit stable** et qu'`--check` affiche zéro référence résiduelle ; l'ordre est ING-003 en production, quelques jours d'observation, puis ce ticket.
- La **latence de la première utilisation** : une adoption est quelques `INSERT` (un ingrédient, ses conversions propres, un alias) ; mesurer, ne pas adopter plus que le slug reçu.
- Un **nouveau commun** créé par l'administrateur est visible de tous, adopté seulement par ceux qui l'utilisent.
- Les modifications du commun par l'administrateur **après** l'adoption ne se propagent pas (c'est voulu : chacun est chez soi) ; le dire dans l'aide de l'administrateur.
