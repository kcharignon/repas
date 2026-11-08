# MAR-005 — L'administrateur met à jour la recette Marmiton neutre (back + front)

- **Dépôt** : **back** (cette fiche, partie A) puis **front** (partie B, `kcharignon/repas-front`)
- **Taille** : M
- **Effort recommandé pour l'agent** : **xhigh** pour A (droits sur la recette sans propriétaire, résolution d'ingrédients pour le propriétaire réservé), **high** pour B
- **Dépend de** : MAR-001 à 004 en production (fait) ; la correction de la modification d'une copie (PR #447)
- **Statut** : à faire
- **Lire d'abord** : [ADR 0037](../../adr/0037-copier-une-recette-depuis-marmiton.md), surtout l'amendement du 2026-11-08 ; `RecipeCopier`, `MarmitonNeutralRecipeCreator`, `OwnerVoter` ; `CLAUDE.md` ; skill `repas-dev` ; issue #434.

## Contexte

Demande du propriétaire (2026-11-08), après l'essai de la tarte au citron en production : « Il faut aussi que l'admin puisse changer la recette neutre pour que les modifications soient effectives pour tous les futurs utilisateurs. » et « Il faut pouvoir laisser l'utilisateur modifier sa copie au cas où il y a des petits bugs » (corrigé séparément, PR #447).

## Parcours (touches)

- L'administrateur ouvre SA copie d'une recette Marmiton, la corrige avec l'éditeur ordinaire (retirer « Jaunes d'œuf » et « Blancs d'œuf », ajouter « Œuf » ×2, régler les conversions), revient sur la fiche : **« Mettre à jour la recette Marmiton »** → une confirmation → fait. 2 touches après la correction.
- Le suivant qui copie la recette Marmiton reçoit la version corrigée. Les copies déjà faites ne changent pas.

## A. Back

1. `POST /api/v1/recipes/{id}/apply-to-marmiton` : `ROLE_ADMIN`, `RECIPE_OWNER` (la copie est à l'administrateur), `204`, corps vide, `If-Match` facultatif sur la copie (ADR 0016). `403` pour qui n'est pas administrateur ; `404` recette inconnue ou d'un autre ; `422 RECIPE_NOT_A_MARMITON_COPY` si `original_id` ne désigne pas une recette neutre (`marmiton_id` présent) ; `422 RECIPE_EMPTY` si la copie n'a aucune ligne. Plafond `repas_write` comme les autres écritures.
2. Cas d'usage `ApplyRecipeToMarmitonNeutral` (command + handler, `Application/Command/`) : charge la copie, charge la neutre, **remplace** nom (`StringTool::capitalizeFirst`), nombre de personnes, type et lignes de la neutre par ceux de la copie ; les ingrédients des lignes sont résolus pour `Recipe::MARMITON_OWNER_ID` par le mécanisme de la copie (`RecipeCopier`, copier = le propriétaire réservé : réutiliser un ingrédient réservé de même origine ou de même nom, sinon en copier un avec ses conversions propres et son origine) ; `marmiton_id` et `source_url` de la neutre ne changent pas ; version de la neutre +1 ; la copie de l'administrateur ne change pas. Aucune statistique, aucun événement pour le propriétaire réservé. Les ingrédients réservés devenus inutiles restent (inoffensifs).
3. `Recipe` : une méthode de domaine qui remplace le contenu d'une neutre (`replaceMarmitonContent(...)`), refusée sur toute autre recette ; `assertNotFrozen()` ne bloque QUE les routes ordinaires (inchangé) ; `OwnerVoter` ne change pas.
4. `GET /api/v1/recipes/{id}` rend `copyOfMarmiton: boolean` (clé toujours écrite, vrai si l'original direct est une neutre), sans requête de plus (jointure déjà là ou colonne de l'original lue dans la même instruction). `openapi.json` régénéré.
5. Budget SQL `appliquer-recette-a-marmiton` (scénario dans `tests/Repas/WriteBudget`), lectures constantes selon le nombre de lignes.
6. Docs : ADR 0037 (déjà amendé), `CLAUDE.md` (puce du chantier), `docs/production.md`, ce ticket « fait » avec « Réalisation ».

Tests (vraie base pour le cœur) : un administrateur met à jour ; un utilisateur ordinaire reçoit `403` et rien n'est écrit ; une copie d'une copie, une recette ordinaire, la neutre elle-même : `422` ; la copie d'un autre : `404` ; après la mise à jour, **un autre utilisateur qui copie la neutre reçoit les nouvelles lignes** (ses propres ingrédients retrouvés par provenance, aucun doublon pour « Œuf ») et **une copie faite avant n'a pas bougé** ; la neutre n'est toujours modifiable par aucune route ordinaire (administrateur compris) ; les ingrédients réservés restent invisibles à tout compte.

## B. Front (après A en production)

Maquette du designer : <https://claude.ai/artifact/PmprUNG3ZBHD1EXc3gyqKk> (écrans 8 et 9). Sur la fiche d'une recette dont `copyOfMarmiton` est vrai, **pour un administrateur seulement** (`isAdmin`), un bloc à part « **Recette Marmiton** » (« Quand ta copie est comme il faut, les prochains qui copient cette recette auront ta version. ») avec le bouton « **Mettre à jour la recette Marmiton** » ; une confirmation (`UiConfirmDialog`) : titre « Mettre à jour la recette Marmiton ? », texte « Les prochains qui la copient auront ta version. Les copies déjà faites ne changent pas. », boutons « Mettre à jour » et « Annuler » ; à la réussite : « La recette Marmiton est à jour. » ; erreurs en trois phrases. Tests Vitest, captures lues.
