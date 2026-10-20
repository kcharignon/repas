# ING-009 — Les communs deviennent les ingrédients de l'administrateur

- **Dépôt** : back (+ front d'administration)
- **Jalon** : I6 Plus tard
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** (réécrit le propriétaire de tout le catalogue ; droits)
- **Dépend de** : ING-008 en production, et une vérification à zéro (« Contexte ») ; décision du propriétaire
- **Statut** : à faire, **plus tard** (ne rien faire maintenant)
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) décision a ; [`analyse.md`](../analyse.md) §§ 3, 4, 14 ; ADR 0021 (compte protégé) ; skills `/repas-dev`, `/repas-ops`.

## Contexte

Le propriétaire a dit que les communs « appartiennent à l'admin : cela devra être **ses** ingrédients ». Aujourd'hui un commun n'appartient à personne (`creator` vide) et seul un administrateur le modifie. La voie, **à n'emprunter que quand plus aucune donnée d'utilisateur ne référence un commun** (après ING-008) :

1. Vérifier, en lecture seule, que **aucune** ligne de `recipe_row`, `shopping_list_row`, `shopping_list_ingredient`, `drive_choice` d'un **utilisateur non administrateur** ne référence un ingrédient dont `creator` est vide (la requête de santé d'ING-008) ;
2. désigner **un** compte propriétaire du catalogue (paramètre `CATALOG_OWNER_USER_ID`, par défaut le compte de `User::PROTECTED_EMAIL`, passé explicitement par le script : le contexte `Repas` n'importe pas `User`) ;
3. `UPDATE ingredient SET creator = :owner WHERE creator IS NULL` (une instruction, un utilisateur, réversible par l'instruction inverse), après sauvegarde.

## Portée

1. **Prédicat « commun » unique** : `IngredientVisibility` d'ING-002 devient `creator = :catalogOwner` (au lieu de `creator IS NULL`) ; `CreateIngredientHandler` (`isAdmin ? null : userId`) crée un ingrédient **du propriétaire du catalogue** quand l'auteur est administrateur ; `canEdit`, `OwnerVoter::INGREDIENT_OWNER` et `Recipe::assertIngredientIsAvailable` passent par le même prédicat (un seul endroit).
2. **Le catalogue pour les nouveaux arrivants** : les ingrédients du propriétaire marqués « catalogue » sont les suggestions adoptables par chacun (ING-008) ; un ingrédient personnel du propriétaire qui n'est pas dans le catalogue ne l'est pas : drapeau explicite `ingredient.catalog` (booléen, `true` pour les anciens communs), posé par la même migration, **jamais déduit**.
3. **Administration** : l'écran des conversions et l'écran des ingrédients de l'administrateur montrent le catalogue ; l'administrateur voit son monde comme un utilisateur (ses ingrédients personnels et le catalogue sont ses ingrédients).
4. **Script d'exploitation** `references/communs-a-l-administrateur.sh` (lecture seule sans argument ; avec `--appliquer` : sauvegarde, vérification à zéro, `UPDATE`, nouvelle vérification), exécuté par l'humain.

## Hors portée

Supprimer un commun ; fusionner des communs (le catalogue n'est pas fusionnable) ; plusieurs propriétaires de catalogue.

## Critères d'acceptation

- Étant donné la vérification à zéro, l'`UPDATE` change uniquement `ingredient.creator` (comptage) et **aucun écran** des utilisateurs ne change ; un administrateur voit exactement les mêmes ingrédients qu'avant ; un non-administrateur sans alias voit le catalogue (suggestions) comme avant.
- La vérification **échoue** (et le script s'arrête) s'il reste une référence d'utilisateur non administrateur à un commun.
- Les tests de visibilité d'ING-002 passent en changeant **une seule** définition de « commun ».
- Retour arrière : l'`UPDATE` inverse (`SET creator = NULL WHERE catalog`) restitue l'état antérieur.

## Tests exigés

Tout ce qui précède sur base réelle ; `OwnerVoterTest` ; parcours administrateur (créer un ingrédient du catalogue, le modifier, l'adopter par un utilisateur). `make test quality`.

## Definition of Done

`repas-dev` § 4 ; ADR 0028 : décision a passe à « livrée » ; `CLAUDE.md` et le skill `repas-dev` mis à jour (plus de `creator IS NULL` comme définition d'un commun).

## Pièges

- Ne pas faire ce ticket avant ING-008 : sans lui, des utilisateurs non migrés perdraient la visibilité de tout ce qu'ils utilisent.
- `User::PROTECTED_EMAIL` est une constante du contexte `User` : le script lit l'identifiant du compte en SQL et le passe en paramètre, le code `Repas` ne connaît que l'identifiant.
- L'`OwnerVoter` laisse déjà tout passer à un administrateur ([`analyse.md`](../analyse.md) § 14 point 5) : le propriétaire du catalogue doit rester un **seul** compte, pas « tout administrateur ».
