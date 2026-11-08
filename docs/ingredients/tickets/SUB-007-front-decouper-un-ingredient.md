# SUB-007 — Front : découper un ingrédient, voir « 5 œufs » et ce qu'ils contiennent

- **Dépôt** : **front** (`kcharignon/repas-front`)
- **Jalon** : I8 Sous-ingrédients
- **Taille** : L
- **Effort recommandé pour l'agent** : **high** (écrans visibles, textes à la voix du produit, captures lues, maquette du designer faite avant)
- **Dépend de** : SUB-001 et SUB-003 **en production** (champs `parts`, `partOf`, `partOf*`, `includes`, routes `PUT|DELETE /ingredients/{slug}/parent`, erreurs). Les champs sont lus comme facultatifs (un ancien back : traités comme vides).
- **Statut** : à faire (maquette du designer faite le 2026-11-08 : <https://claude.ai/artifact/3Yd2usWrEx7iSAE98TUvWy>, six écrans téléphone, clair ; à faire valider par le propriétaire avant la première ligne de code)
- **Lire d'abord** : [ADR 0038](../../adr/0038-sous-ingredients.md) décisions 1, 15, 16, 28, 29 ; `CLAUDE.md` du front ; skills `repas-front-dev`, **`repas-po`** (`docs/drive/principes-produit.md` : tutoiement, 15 mots au plus, aucun avertissement, erreurs en trois phrases), `repas-designer` ; ING-015 et ING-017 (écrans voisins).

## Écrans à dessiner (maquette Claude Design, avant le code)

1. **Fiche d'un ingrédient entier** (`IngredientEditorView` en modification) : une section « Ses parties » sous les unités. Vide : une phrase d'aide et le bouton « Ajouter une partie ». Remplie : une carte par partie (nom, « 1 œuf = 1 jaune d'œuf »), toucher pour changer le ratio, croix pour détacher.
2. **Ajouter une partie** (feuille du bas sur téléphone) : choisir un ingrédient à toi (recherche, comme le choix d'un ingrédient dans une recette) **ou** « Nouvelle partie » (nom seulement : le rayon et les unités sont ceux du parent, modifiables ensuite) ; une ligne « Combien dans 1 œuf ? » avec le nombre (1 par défaut) et l'unité en texte ; bouton « Ajouter ».
3. **Fiche d'une partie** : un bandeau « Partie de : Œuf » (lien vers l'œuf), la phrase « Ça ne s'achète pas tout seul : ça compte dans tes œufs. », un bouton « Ne plus en faire une partie ». Pas de réglage du produit à part (`listDestination`) : une partie suit son parent (masqué).
4. **Ligne de la liste de courses** : « Œuf 5 » avec, en petit et gris dessous, « Comprend 2 jaunes d'œuf et 2 blancs d'œuf. » (`includes` ; « dont » est écarté par la maquette : les jaunes et les blancs viennent des MÊMES œufs, « dont » dirait 4 œufs sur 5) ; rien dessous sans partie. Rien d'autre ne change (cocher, +/−, rayons).
5. **Ligne de recette** qui contient une partie : « 2 jaunes d'œuf » avec une petite mention discrète « compte pour des œufs » (`partOfName`), pas d'alerte. Le sélecteur d'ingrédient montre « partie de Œuf » en sous-titre (`partOf*`).

## Textes proposés (à confirmer par `repas-po`, dans `src/i18n/fr.ts`)

- Section vide : « Un œuf, c'est un jaune et un blanc. Ajoute ses parties : les jaunes et les blancs comptent dans tes œufs. »
- Boutons : « Ajouter une partie », « Nouvelle partie », « Ne plus en faire une partie ».
- Feuille : « Combien dans 1 œuf ? » ; ratio affiché : « 1 œuf = 1 jaune d'œuf ».
- Bandeau : « Partie de : Œuf. » ; aide : « Ça ne s'achète pas tout seul : ça compte dans tes œufs. »
- Liste : « Comprend 2 jaunes d'œuf et 2 blancs d'œuf. » ; après un ajout à la main d'une partie : « C'est ajouté. Ça fait 3 œufs dans ta liste. »
- Erreurs (trois phrases, jamais le code) : `INGREDIENT_PART_OF_ITSELF` « Un ingrédient ne peut pas être sa propre partie. » ; `INGREDIENT_PART_CHAIN` « Une partie n'a pas de parties. Choisis un ingrédient entier. » ; `INGREDIENT_PART_LIMIT_REACHED` « Tu as déjà dix parties pour cet ingrédient. » ; `INGREDIENT_PART_IN_LIST` « Cet ingrédient est dans une liste en cours. Termine la liste, puis recommence. » ; `INGREDIENT_PART_RATIO_INVALID` « Mets un nombre plus grand que zéro. »

## Portée

1. `npm run api:update` (contrat figé à un commit où SUB-001 et SUB-003 sont fusionnés), types régénérés, nouveaux champs facultatifs.
2. Les cinq écrans ci-dessus, zones tactiles de 44 px, clair et sombre, thèmes saisonniers sans régression.
3. Le front **n'envoie jamais** un parent d'un autre compte ; il masque « Ajouter une partie » sur une partie et sur un ingrédient qui est déjà une partie (profondeur 1) ; la liste des parties possibles exclut l'ingrédient lui-même et les parents.
4. Doubles et e2e : `src/__tests__/helpers.ts`, faux back des e2e, `IngredientEditor.spec.ts`, `ShoppingList.spec.ts`, `voice-and-glossary.spec.ts` (aucun « sous-ingrédient » ni « parent » à l'écran) ; **budget des e2e respecté** (flux critique seulement : découper, voir la ligne de liste).
5. À vérifier sans rien changer (captures lues) : `RecipeRowEditor.vue`, `IngredientSearchResults.vue`, la fusion (`PART_CONFLICT`, `PART_IN_LIST` rendus en trois phrases).

## Critères d'acceptation

- [ ] Maquette validée par le propriétaire avant la première ligne de code ; captures lues avant de fusionner.
- [ ] Un compte sans partie ne voit aucun changement d'écran.
- [ ] CI du front verte ; release du site **après** celle du back, une à la fois.
