# MAR-004 — Copier une recette Marmiton (front)

- **Dépôt** : **front** (`kcharignon/repas-front`) ; le back est MAR-003 (contrat dans l'ADR 0037 et l'issue #434)
- **Taille** : M
- **Effort recommandé pour l'agent** : **high**
- **Dépend de** : le contrat de MAR-003 (peut démarrer avant : le contrat est écrit ci-dessous ; la fusion attend la release du back et le contrat figé dans `docs/api/`)
- **Statut** : à faire
- **Lire d'abord** : [ADR 0037](../../adr/0037-copier-une-recette-depuis-marmiton.md) (back) ; `CLAUDE.md` du front ; skills `repas-front-dev` et `repas-po` ; la **maquette validée** : <https://claude.ai/artifact/PmprUNG3ZBHD1EXc3gyqKk> (huit écrans : le choix, écrire ma recette, copier depuis Marmiton, copie en cours, lien refusé, le choix en sombre, recette copiée avec le bouton « Voir sur Marmiton », « Recettes des autres » avec la pastille) ; issue #434.

**Décision du propriétaire (2026-11-08)** : *deux parcours distincts dès le début, quitte à avoir un clic de plus ; ne pas commencer le parcours Marmiton ni le parcours « recette perso » avant d'avoir choisi la méthode.* Une première maquette (carte Marmiton au-dessus du formulaire, séparateur « ou écris la tienne ») a été écartée : trop discrète, des utilisateurs ne lisent pas cette ligne.

## Parcours (touches avant / après)

- **Avant** : « Nouvelle recette » ouvre directement le formulaire ; copier une recette d'un site = l'écrire à la main (une dizaine de touches par ligne).
- **Après** : « Nouvelle recette » ouvre **le choix** (deux grosses cartes de même poids). Écrire à la main : **+1 touche** (le choix), puis le même formulaire. Copier : le choix, coller le lien, « Copier la recette » = **3 touches** dont un collage ; on arrive sur SA recette.

## À livrer

1. **Trois écrans** (pas de carte, pas de séparateur, rien de Marmiton dans le formulaire) :
   - **Le choix** : `recipe-new` (`/recettes/nouvelle`), titre « Nouvelle recette », question « Comment veux-tu ajouter ta recette ? », deux cartes pleine largeur empilées, de même poids, 96 px au moins, icône dans un rond, titre, une ligne dessous, chevron : « **Écrire ma recette** » / « Tu choisis le nom et les ingrédients. » (icône crayon) et « **Copier depuis Marmiton** » / « Tu colles un lien, je m'occupe du reste. » (icône lien). Ce sont deux liens (`<a>`) vers les deux écrans suivants. Le bouton « Nouvelle recette » de « Mes recettes » y mène (rien à changer).
   - **Écrire ma recette** : le `RecipeEditorView` de création actuel, **sans changement**, sur une route à part (par exemple `recipe-new-write`, `/recettes/nouvelle/ecrire`), titre « Écrire ma recette », la flèche de retour revient au choix ; la modification d'une recette (`/recettes/:id/modifier`) ne change pas. Les tests et l'e2e qui ouvraient `/recettes/nouvelle` pour remplir le formulaire passent par cette route (ou par le choix).
   - **Copier depuis Marmiton** : une route à part (par exemple `recipe-new-marmiton`, `/recettes/nouvelle/marmiton`), titre « Copier depuis Marmiton », la phrase « Colle le lien de la recette Marmiton, je la copie dans tes recettes. », champ « Lien de la recette » (`type="url"`, `inputmode="url"`, `autocomplete="off"`, placeholder `https://www.marmiton.org/recettes/…`), bouton « Copier la recette » (primaire, pleine largeur, 52 px) ; la flèche de retour revient au choix.
   Composants du design existants (`UiField`, `UiTextInput`, `UiButton`), jetons du thème (pas de couleur écrite à la main), thèmes clair et sombre, saisons sans effet de bord.
2. **Appel** `POST /api/v1/recipes/import/marmiton` `{url}` : `201` ou `200` + `Location` → aller à la fiche de la copie (`RecipeDetailView`) et dire « « {name} » est dans tes recettes. » par le mécanisme de notification existant (`copiedToast` ; pour `200`, la même phrase suffit : la copie est déjà là). Les requêtes de recettes sont invalidées (liste des recettes, « Recettes des autres »).
3. **En cours** : bouton désactivé « Je copie la recette… » (`aria-busy`), champ bloqué, « Ça prend quelques secondes. » en `role="status"` ; pas de fenêtre, pas de barre. Délai du client : 20 s (puis l'erreur « Marmiton ne répond pas »).
4. **Erreurs**, sous le champ, `role="alert"`, icône et texte (jamais la couleur seule), le lien reste dans le champ, le bouton redevient actif, **trois phrases de 15 mots au plus** (ce qui s'est passé, ce qui est sauf, une action), traduites d'après le **message** de l'erreur du back (contrat, `errors` dans `fr.ts` ; message inconnu : le texte générique existant) :
   - `MARMITON_URL_INVALID` : « Ce lien n'est pas une recette Marmiton. Rien n'a été copié. Colle le lien d'une recette de marmiton.org. »
   - `MARMITON_RECIPE_NOT_FOUND` : « Marmiton ne trouve pas cette recette. Rien n'a été copié. Vérifie le lien et réessaie. »
   - `MARMITON_RECIPE_UNREADABLE` : « Je n'arrive pas à lire cette recette. Rien n'a été copié. Tu peux l'écrire toi-même. »
   - `MARMITON_UNAVAILABLE` (502) ou réseau : « Marmiton ne répond pas pour le moment. Rien n'a été copié. Réessaie dans un instant. »
   - `RECIPE_LIMIT_REACHED` : le texte existant ; `MARMITON_IMPORT_RATE_LIMITED` / `429` : « Tu as copié beaucoup de recettes. Rien n'a été perdu. Réessaie dans quelques minutes. »
   Un lien vide ne part pas : le champ dit « Colle le lien d'une recette Marmiton. ». Un contrôle côté client rapide (le texte contient `marmiton.org`) peut éviter un appel inutile, **le back reste le seul juge**.
5. **Fiche d'une recette** (`RecipeDetailView`) : si la réponse porte `sourceUrl`, un bouton secondaire « **Voir sur Marmiton** » sous le titre (lien `<a>`, `target="_blank"`, `rel="noopener noreferrer"`, 44 px au moins, icône de lien externe du jeu d'icônes du projet, **pas le logo de Marmiton**). Le lien n'est rendu que si `sourceUrl` est une adresse `https://www.marmiton.org/recettes/…` (sinon rien, sans erreur) ; l'adresse est lue de la réponse, jamais composée par le front.
6. **« Recettes des autres »** : une pastille « Marmiton » (même forme que `SecretBadge`, jetons `--secret-soft` ou un jeton dédié AA clair et sombre) sur la carte d'une recette de `GET /recipes/other-people` dont `fromMarmiton` est vrai (champ booléen que MAR-003 ajoute à chaque recette de la liste, clé toujours écrite). **Tant que le contrat figé du front ne le contient pas**, ne pas inventer le champ : dessiner la pastille derrière `fromMarmiton === true` (absent, elle ne s'affiche pas) et le dire dans la PR.
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
