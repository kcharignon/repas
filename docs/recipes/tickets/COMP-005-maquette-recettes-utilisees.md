# COMP-005 — Maquette du designer : les recettes utilisées (éditeur, fiche, copie, erreurs)

- **Dépôt** : **designer** (maquette Claude Design, skill `repas-designer` ; **aucune ligne de code**, ni back ni front) ; elle est lue par COMP-006
- **Jalon** : I9 Recettes utilisées dans d'autres recettes
- **Taille** : S à M
- **Effort recommandé pour l'agent** : **high** (écrans visibles, textes à la voix du produit, états vides et d'erreur à dessiner avec soin)
- **Dépend de** : l'[ADR 0040](../../adr/0040-recettes-utilisees-dans-d-autres-recettes.md) acceptée (c'est le cas). Peut commencer **tout de suite**, en parallèle du back ; **doit être validée par le propriétaire avant la première ligne de COMP-006**.
- **Statut** : à faire
- **Lire d'abord** : ADR 0040 décisions 1, 2, 5, 19, 22, 23, 29 ; skills `repas-designer` et **`repas-po`** (`docs/drive/principes-produit.md` : tutoiement, 15 mots au plus par phrase, aucun avertissement hors conditions d'utilisation, erreurs en trois phrases, glossaire) ; la maquette des parties pour la forme : <https://claude.ai/artifact/3Yd2usWrEx7iSAE98TUvWy> (SUB-007) ; issue #435 (la demande du propriétaire en ses mots).

## But

Dessiner, sur téléphone (360 px), en clair et en sombre, avec le thème de la saison courante, **tout ce qu'un utilisateur voit** quand une recette en utilise une autre. Le propriétaire dit « sous-recette » ; l'écran **ne le dit jamais** : « recette utilisée », « Ajouter une de mes recettes ». « Partie » est pris (ingrédients). Aucune notion de « composant », de « parent », de « type préparation ».

## Écrans à dessiner (liste exacte)

1. **Éditeur d'une recette, section « Recettes utilisées »** sous les ingrédients. Vide : une phrase et le bouton « Ajouter une de mes recettes ». Remplie : une carte par recette utilisée (de 1 à 10), dans l'ordre.
2. **Ligne d'une recette utilisée** dans l'éditeur : nom (ouvre la recette), nombre de **fois** (« 1 fois », réglage − / + ou champ, valeurs courantes 0,5 / 1 / 2 / 3 ; bornes 0,01 à 100), sa phrase d'aide (« 1 fois = la recette comme elle est écrite. »), « Retirer » ; états : nombre invalide, onzième recette refusée, ligne dont une recette n'a plus aucun ingrédient convertible.
3. **Feuille du bas « Choisis une de tes recettes »** : recherche, une ligne par recette (nom, type, nombre de personnes, pastille « secrète »), état vide (« Tu n'as pas d'autre recette à utiliser. »), état « cette recette sert déjà dans une autre » (la section n'apparaît pas).
4. **Fiche d'une recette, section « Avec »** : une carte par recette utilisée (nom cliquable, « 1 fois », ingrédients mis à l'échelle repliables : quantité × fois × personnes choisies ÷ personnes de la recette) ; états : recette utilisée avec une ligne « à régler » (« Il manque une conversion pour farine dans Pâte sablée. », bouton « Ouvrir Pâte sablée », le bouton « Ajouter à la liste » bloqué comme pour une ligne propre « à régler »).
5. **Fiche d'une recette qui sert ailleurs** : une note discrète « Cette recette sert dans Tarte au citron. Les listes déjà faites ne changent pas. » (avec « et 2 autres » au-delà de deux) et un lien « Voir lesquelles » (feuille avec la liste).
6. **Suppression refusée** : la feuille d'erreur « Cette recette sert dans d'autres recettes. Retire-la d'abord de chacune. » avec les recettes qui l'utilisent (liens).
7. **Copie d'une recette composée** (« Recettes des autres » et fiche d'un autre) : la fiche d'un autre montre « Avec » ; le toast après « Copier » dit ce qui a été copié (« Tarte au citron et Pâte sablée sont dans tes recettes. »), un seul message.
8. **Les erreurs de l'éditeur**, une par code (voir « Textes »), en trois phrases au plus, jamais le code.
9. **La ligne de la liste de courses ne change pas** (rien à dessiner : « Farine 250 g ») ; le dire dans la maquette pour éviter qu'on y ajoute « dont Pâte sablée » (hors v1).

## Textes proposés (à confirmer par `repas-po`, destinés à `src/i18n/fr.ts`)

- Section : « Recettes utilisées ». Vide : « Ta pâte, ta sauce : ajoute une de tes recettes, elle compte dans celle-ci. » Bouton : « Ajouter une de mes recettes ». Feuille : « Choisis une de tes recettes. »
- Fois : « 1 fois », « 2 fois », « 0,5 fois ». Aide : « 1 fois = la recette comme elle est écrite. » Bouton : « Retirer ».
- Fiche : « Avec ». Note : « Cette recette sert dans Tarte au citron. Les listes déjà faites ne changent pas. »
- Erreurs (trois phrases au plus, tutoiement) : `RECIPE_USED_AS_COMPONENT` « Cette recette sert dans d'autres recettes. Retire-la d'abord de chacune. Tu peux voir lesquelles. » ; `RECIPE_COMPONENT_CHAIN` « Une recette utilisée ne peut pas en utiliser d'autres. Choisis une recette simple. » ; `RECIPE_COMPONENT_SECRET` « Une recette visible de tous ne peut pas utiliser une recette secrète. Rends l'une des deux comme l'autre. » ; `RECIPE_SECRET_USED_BY_PUBLIC` « Cette recette sert dans une recette visible de tous. Elle ne peut pas devenir secrète. » ; `RECIPE_COMPONENT_LIMIT_REACHED` « Tu as déjà dix recettes utilisées ici. Retires-en une. » ; `RECIPE_COMPONENT_MULTIPLIER_INVALID` « Mets un nombre entre 0,01 et 100. » ; `RECIPE_EMPTY` « Ajoute au moins un ingrédient ou une recette. » ; `RECIPE_COMPONENT_DUPLICATE` « Cette recette est déjà utilisée ici. Change plutôt le nombre de fois. » ; `RECIPE_COMPONENT_SELF` jamais montrée (le sélecteur exclut la recette).
- Voix (rappel, déjà dans le back) : « Il manque une conversion pour farine, dans pâte sablée. Ouvre-la dans Repas. »

## Contraintes à respecter

- Zones tactiles de 44 px, clair et sombre, thèmes saisonniers sans régression (au moins un thème dessiné), texte qui ne déborde pas à 360 px.
- Un compte **sans** recette utilisée ne voit aucun changement hors le bouton de l'éditeur (la section « Avec » n'apparaît pas sans composant).
- Aucun avertissement hors conditions d'utilisation ; l'écran sert l'action en cours.
- Le mot « fois » est la question ouverte de l'ADR 0040 : proposer **une variante** (« × 2 ») et dire laquelle est recommandée.

## Critères d'acceptation

- [ ] Un lien de maquette (artifact) avec les neuf écrans et leurs états ; captures lues.
- [ ] **Validée par le propriétaire** (date notée ici) avant que COMP-006 commence.
- [ ] Les textes relus par `repas-po` : aucun « sous-recette », « composant », « parent » à l'écran ; chaque phrase ≤ 15 mots.
