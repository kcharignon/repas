# ING-017 — Front : l'icône du rayon, le même nom dans deux rayons, créer un ingrédient dans la recette

- **Dépôt** : **front** (`kcharignon/repas-front`)
- **Jalon** : I6 Plus d'ingrédient commun (suite)
- **Taille** : M
- **Effort recommandé pour l'agent** : **high** (écrans visibles, textes à la voix du produit, accessibilité)
- **Dépend de** : ING-016 (contrat : `icon` du rayon, `departmentSlug` et `departmentName` des lignes, `409 INGREDIENT_NAME_ALREADY_USED`). Le site doit se comporter correctement avec un back plus ancien (pas de `icon`, pas de `departmentSlug`) : aucun repère, aucune erreur
- **Statut** : fait (front : partie A PR #275, partie B PR #277 ; pas encore déployé quand ce ticket a été écrit)
- **Lire d'abord** : [ADR 0028](../../adr/0028-ingredients-propres-a-chaque-utilisateur.md) amendement du 2026-10-30 ; [`ING-016`](ING-016-meme-nom-par-rayon-et-icone-de-rayon.md) ; `CLAUDE.md` du front (puces « Repère de rayon… » et « Créer un ingrédient sans quitter la recette ») ; skill `repas-po`.

## Contexte

Retours du propriétaire (2026-10-30), maquettes validées (Claude Design, planches 1, 1b, 4 et 7) : deux ingrédients de même nom dans deux rayons se distinguent d'un coup d'œil par l'icône du rayon ; et créer l'ingrédient qu'on ne trouve pas ne doit pas faire quitter la recette.

## A. Repère de rayon et même nom dans deux rayons (PR #275)

- `DepartmentMarker` : pastille décorative de 24 px (28 px dans un choix), `aria-hidden`, flocon (`snowflake`) et boîte (`can`), couleurs en jetons du thème (`cold-soft`, `can-soft`), clair et sombre ; contraste AA gardé par un test. Posée **devant le nom** d'un ingrédient : choix d'un ingrédient et ligne choisie du formulaire de recette, lecture d'une recette, lignes d'une liste de courses, listes de l'écran des rayons ; **jamais** dans un champ, un bouton ni une case.
- L'icône vient de la liste des rayons déjà chargée, jointe par `departmentSlug` (aucune requête par ligne). Rayon sans icône, icône inconnue (valeur future) ou back plus ancien : rien, sans erreur ; la place d'une pastille n'est gardée que si une autre ligne de la même liste en a une.
- Le rayon s'affiche sous le nom (14 px, gris) **seulement** quand deux ingrédients de même nom et de rayons différents sont dans la même liste.
- `409 INGREDIENT_NAME_ALREADY_USED` : « Tu as déjà « X » dans Y. Choisis-le plutôt que d'en créer un autre. » (création et modification) ; à la création d'un nom qui existe dans un autre rayon, l'information sans blocage « Celui-ci sera dans Z. ».
- Administration des rayons : un choix « Icône » (Aucune, Flocon, Boîte) ; `icon` n'est envoyé que s'il a changé, `null` l'efface.

## B. Créer un ingrédient sans quitter la recette (PR #277)

- Dans le formulaire de recette, ce qui est tapé ne correspond à aucun ingrédient : « Aucun ingrédient ne s'appelle « x ». » et « Créer « x » » (48 px).
- Feuille « Nouvel ingrédient » : Nom (prérempli, modifiable), Rayon (liste de choix avec repères, **jamais deviné**, obligatoire), Unité (pastilles g, kg, ml, l, unité, puis « Autre » ; **la même unité pour l'achat et la cuisine**, aucun coefficient, comme la création à la voix).
- « Créer et l'ajouter à la recette » : `POST /api/v1/ingredients`, puis l'ingrédient (et son unité) est choisi dans la ligne en cours ; le reste de la recette n'est ni quitté ni rechargé ; le focus revient au champ.
- Erreurs en tutoiement dans la feuille : `409 INGREDIENT_NAME_ALREADY_USED` (dit aussi tout de suite quand la liste chargée le montre), `INGREDIENT_LIMIT_REACHED`, droits, hors ligne. Accessibilité : titre, focus piégé et rendu, Échap, cibles de 44 px au moins, `role="alert"` pour les erreurs.

## Hors portée

Les écrans du drive (`ChoiceDTO`, `DrivePlanLineDTO`, `DriveEstimateLineDTO` ne disent pas le rayon : ING-016 « hors périmètre ») ; une icône autre que le flocon et la boîte (le back en ajoutera, le front les ignore tant qu'il ne les dessine pas).

## Vérifié

`make check` du front vert (tests de composant : repère selon l'icône, absent sans icône, inconnu ignoré, back plus ancien, rayon sous le nom seulement s'il y a doublon, messages `409`, information de création, administration, feuille de création complète). Pas de scénario Playwright : les scénarios de recette ne tournent qu'avec le vrai back (`E2E_WITH_BACK`). Captures à relire par le propriétaire après le déploiement.
