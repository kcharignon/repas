# MAR-004 — Copier une recette Marmiton (front)

- **Dépôt** : **front** (`kcharignon/repas-front`) ; le back est MAR-003 (contrat dans l'ADR 0037 et l'issue #434)
- **Taille** : M
- **Effort recommandé pour l'agent** : **high**
- **Dépend de** : le contrat de MAR-003 (peut démarrer avant : le contrat est écrit ci-dessous ; la fusion attend la release du back et le contrat figé dans `docs/api/`)
- **Statut** : à faire
- **Lire d'abord** : [ADR 0037](../../adr/0037-copier-une-recette-depuis-marmiton.md) (back) ; `CLAUDE.md` du front ; skills `repas-front-dev` et `repas-po` ; la **maquette validée** : <https://claude.ai/artifact/PmprUNG3ZBHD1EXc3gyqKk> (six écrans : nouvelle recette clair et sombre, copie en cours, lien refusé, recette copiée avec le bouton « Voir sur Marmiton », « Recettes des autres » avec la pastille) ; issue #434.

## Parcours (touches avant / après)

- **Avant** : copier une recette d'un site = l'écrire à la main (une dizaine de touches par ligne).
- **Après** : « Nouvelle recette », coller le lien, « Copier la recette » = **2 touches** après avoir collé ; on arrive sur SA recette. Écrire la sienne à la main : **0 touche de plus** (la carte est au-dessus du formulaire, qui est inchangé).

## À livrer

1. **Écran `RecipeEditorView` en création seulement** (`/recettes/nouvelle`, jamais à la modification) : une carte « **Une recette Marmiton ?** » en haut (texte exact de la maquette : « Colle son lien, je la copie dans tes recettes. »), champ « Lien de la recette » (`type="url"`, `inputmode="url"`, `autocomplete="off"`), bouton « Copier la recette » (primaire, pleine largeur, 48 px), séparateur « ou écris la tienne », puis le formulaire actuel **sans changement**. Composants du design existants (`UiField`, `UiTextInput`, `UiButton`), jetons du thème (pas de couleur écrite à la main), thèmes clair et sombre, saisons sans effet de bord.
2. **Appel** `POST /api/v1/recipes/import/marmiton` `{url}` : `201` ou `200` + `Location` → aller à la fiche de la copie (`RecipeDetailView`) et dire « « {name} » est dans tes recettes. » par le mécanisme de notification existant (`copiedToast` ; pour `200`, la même phrase suffit : la copie est déjà là). Les requêtes de recettes sont invalidées (liste des recettes, « Recettes des autres »).
3. **En cours** : bouton désactivé « Je copie la recette… » (`aria-busy`), champ bloqué, « Ça prend quelques secondes. » en `role="status"` ; pas de fenêtre, pas de barre ; le formulaire du dessous grisé et inaccessible au clavier pendant l'appel. Délai du client : 20 s (puis l'erreur « Marmiton ne répond pas »).
4. **Erreurs**, sous le champ, `role="alert"`, icône et texte (jamais la couleur seule), le lien reste dans le champ, le bouton redevient actif, **trois phrases de 15 mots au plus** (ce qui s'est passé, ce qui est sauf, une action), traduites d'après le **message** de l'erreur du back (contrat, `errors` dans `fr.ts` ; message inconnu : le texte générique existant) :
   - `MARMITON_URL_INVALID` : « Ce lien n'est pas une recette Marmiton. Rien n'a été copié. Colle le lien d'une recette de marmiton.org. »
   - `MARMITON_RECIPE_NOT_FOUND` : « Marmiton ne trouve pas cette recette. Rien n'a été copié. Vérifie le lien et réessaie. »
   - `MARMITON_RECIPE_UNREADABLE` : « Je n'arrive pas à lire cette recette. Rien n'a été copié. Tu peux l'écrire toi-même ci-dessous. »
   - `MARMITON_UNAVAILABLE` (502) ou réseau : « Marmiton ne répond pas pour le moment. Rien n'a été copié. Réessaie dans un instant. »
   - `RECIPE_LIMIT_REACHED` : le texte existant ; `MARMITON_IMPORT_RATE_LIMITED` / `429` : « Tu as copié beaucoup de recettes. Rien n'a été perdu. Réessaie dans quelques minutes. »
   Un lien vide ne part pas : le champ dit « Colle le lien d'une recette Marmiton. ». Un contrôle côté client rapide (le texte contient `marmiton.org`) peut éviter un appel inutile, **le back reste le seul juge**.
5. **Fiche d'une recette** (`RecipeDetailView`) : si la réponse porte `sourceUrl`, un bouton secondaire « **Voir sur Marmiton** » sous le titre (lien `<a>`, `target="_blank"`, `rel="noopener noreferrer"`, 44 px au moins, icône de lien externe du jeu d'icônes du projet, **pas le logo de Marmiton**). Le lien n'est rendu que si `sourceUrl` est une adresse `https://www.marmiton.org/recettes/…` (sinon rien, sans erreur) ; l'adresse est lue de la réponse, jamais composée par le front.
6. **« Recettes des autres »** : une pastille « Marmiton » (même forme que `SecretBadge`, jetons `--secret-soft` ou un jeton dédié AA clair et sombre) sur la carte d'une recette dont l'API dit qu'elle vient de Marmiton. **Si le back ne rend pas encore ce champ** (le contrat de MAR-003 ne l'ajoute pas à `other-people` dans cette version), ne pas la dessiner : le noter dans la PR comme suite possible, ne pas inventer de champ.
7. **Contrat** : mettre à jour `docs/api/` du front sur le contrat figé du back dès que MAR-003 est publié ; en attendant, un faux `MockServer` / `msw` du dépôt (selon l'outil du projet) porte les cas des tests. i18n : toutes les phrases dans `src/i18n/fr.ts` (aucune ailleurs).

## Tests

- Vitest : la carte n'existe pas à la modification ; `201` va à la fiche, `200` aussi ; chacune des erreurs affiche sa phrase, garde le lien, rend le bouton actif ; lien vide ; double touche sur le bouton (un seul appel) ; `sourceUrl` valide affiche le bouton avec les bons attributs, `javascript:`, `http://autre-site`, `https://www.marmiton.org.evil.com/` n'affichent rien ; glossaire du `repas-po` (aucun « vous », aucun impératif en « -ez », aucune phrase de plus de 15 mots).
- e2e Playwright (suite sur faux back, budget 5 minutes strict : **un seul** parcours) : coller un lien, copier, arriver sur la fiche avec le bouton « Voir sur Marmiton ».
- Captures **lues** (Chromium) à 360×640 et 390×844, clair et sombre : carte, en cours, erreur, fiche avec bouton ; comparées à la maquette.

## Critères d'acceptation

- [ ] Parcours en 2 touches après collage, formulaire manuel inchangé.
- [ ] `make check` (ou l'équivalent du dépôt) vert, CI verte, couverture maintenue.
- [ ] Aucune couleur ni texte en dur hors jetons et `fr.ts`.
- [ ] PR vers `main` du front, description au gabarit avec **touches avant / après** et les captures lues ; fusion seulement après la release du back et le contrat figé.
