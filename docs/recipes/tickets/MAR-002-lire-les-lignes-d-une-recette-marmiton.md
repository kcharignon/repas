# MAR-002 — Comprendre les lignes d'une recette Marmiton (back, logique pure)

- **Dépôt** : **back** ; suite : MAR-003
- **Taille** : M
- **Effort recommandé pour l'agent** : **high**
- **Dépend de** : — (en parallèle de MAR-001 et MAR-004 : aucun fichier en commun)
- **Statut** : fait (2026-11-08)
- **Lire d'abord** : [ADR 0037](../../adr/0037-copier-une-recette-depuis-marmiton.md) décisions 1, 2, 7 ; `src/Repas/Domain/Service/IngredientNameMatcher.php` ; `StringTool` ; `docs/ingredients/rapprochement.md` ; skill `repas-dev`.

## But

Transformer les textes de Marmiton en valeurs que Repas comprend, par des **fonctions pures** (aucune base, aucun réseau, aucun conteneur), prêtes à brancher en MAR-003.

## À livrer (`src/Repas/Domain/Service/Marmiton/`, `final readonly`, sans état)

1. **`MarmitonIngredientLineParser::parse(string $line): ParsedIngredientLine`** avec `ParsedIngredientLine` (`quantity: float` > 0 jamais nul, `unitSlug: string` parmi les slugs de `unit.json` + `pincee`, `name: string` propre, `raw: string`). Doit lire au moins :
   - `250 g de farine` → 250, `gramme`, « farine » ; `125 g de beurre doux` ; `5 cl d'eau` → 5, `centilitre`, « eau » ; `1 pincées de sel` → 1, `pincee`, « sel » ; `0.5 cuillères à café de levure chimique` → 0,5, `cuillere-a-cafe` ; `1 cuillères à soupe de maïzena` ;
   - sans unité : `2 jaunes d'oeuf` → 2, `unite`, « jaunes d'oeuf » ; `4 citrons de taille moyenne` → 4, `unite`, « citrons » (précision après « de taille », « moyen(ne)s », « gros », « petit(e)s » retirée du nom) ; `3 oeufs` ; `1 pâte brisée` ;
   - quantités écrites autrement : `1,5 kg de pommes de terre`, `1/2 litre de lait`, `½ citron`, `1 1/2 tasse à café de sucre`, `2 à 3 gousses d'ail` (la plus petite), `une pincée de sel`, `un peu de poivre`, `quelques feuilles de basilic` (quantité 1, unité `unite`), `un` / `une` / `deux`… `douze` ;
   - sans quantité : `sel`, `Poivre du moulin`, `du beurre pour le moule` (1 `unite`, nom sans « pour le moule » : tout ce qui suit « pour » est retiré) ;
   - abréviations et formes : `cuillère(s) à soupe`, `c. à s.`, `c à s`, `càs`, `CS`, `cuillères à café`, `c. à c.`, `càc`, `CC`, `tasse(s) à café`, `verre(s)`, `bol(s)`, `kg`, `kilo(s)`, `gr`, `grammes`, `l`, `litre(s)`, `cl`, `ml`, `sachet(s)`, `paquet(s)`, `boîte(s)`/`boite(s)`, `bouteille(s)`, `brique(s)`, `pot(s)`, `gousse(s)`, `botte(s)`, `tranche(s)`, `plaquette(s)`, `tablette(s)`, `cube(s)`, `conserve(s)`, `flacon(s)`, `pincée(s)`, avec ou sans accent, majuscules, espaces insécables, retours à la ligne, entités HTML (`&#39;`, `&nbsp;`) ;
   - « de », « d' », « du », « des », « de la », « de l' » après l'unité sont retirés du nom ; le nom garde ses mots (« jaunes d'oeuf », « sucre glace », « levure chimique ») ; `oeuf`/`œuf` : la ligature ne change pas le sens (le rapprochement passe déjà par `StringTool::slugify`) ; le nom est borné à 100 caractères.
   - **Jamais d'exception** : une ligne illisible (« . », « — ») rend `null` (`parse(): ?ParsedIngredientLine`) et sera ignorée ; une quantité hors de `QuantityBounds` est ramenée à 1.
2. **`MarmitonUnitWords`** : le tableau mot → slug d'unité, **un seul endroit**, testé contre `unit.json` (`MarmitonUnitWordsTest` échoue si un slug du tableau n'existe pas dans les données de référence ni n'est `pincee`).
3. **`MarmitonRecipeTitle::clean(string $raw): string`** : retire le suffixe de titre de Marmiton (« : la meilleure recette », « - Marmiton », « : recette facile » et variantes, insensible à la casse, une fois, en fin), espaces normalisés, première lettre en majuscule par `StringTool::capitalizeFirst`, 255 caractères au plus ; `"Tarte au citron meringuée : la meilleure recette"` → `"Tarte au citron meringuée"`. Un titre vide après nettoyage est rendu tel quel (MAR-003 refuse).
4. **`MarmitonServings::from(?string $yield): int`** : le premier nombre (« 6 personnes » → 6, « 4 à 6 personnes » → 4, « 12 madeleines » → 12, « pour 8 » → 8), borné à 1–100, 4 à défaut ou si hors bornes.
5. **`MarmitonRecipeCategory::typeSlug(?string $category): string`** : table de l'ADR (décision 2), insensible à la casse et aux accents, `plat` à défaut.
6. **L'unité `pincee`** : ajouter l'entrée dans `src/Repas/Infrastructure/DataFixture/Data/unit.json` (`{"slug":"pincee","name":"pincée","symbol":"pincée","dimension":null,"baseFactor":null}`) ; la **migration** est faite en MAR-003 (ne pas écrire de migration ici).

## Tests

- `MarmitonIngredientLineParserTest` : un jeu de données (`#[DataProvider]`) d'**au moins 80 lignes**, dont les 13 de la page d'exemple (voir l'issue #434), toutes celles de la liste ci-dessus et une quinzaine de lignes tirées de recettes Marmiton courantes que tu inventes dans le même style ; la table dit pour chacune quantité, unité et nom attendus. Ajoute des lignes pièges : unité collée au nombre (`250g`, `5cl`), `1l`, virgule décimale, deux nombres (`2 x 200 g de crème` → 400 g est **trop malin** : prends 2 `unite` et le reste dans le nom, et dis-le), parenthèses (`200 g de farine (type 55)` → « farine », la parenthèse est retirée), texte en anglais, chaîne vide, 10 000 caractères (borné, rapide : le test mesure < 50 ms).
- `MarmitonRecipeTitleTest`, `MarmitonServingsTest`, `MarmitonRecipeCategoryTest` : les cas de l'ADR plus les bords.
- Aucun test n'utilise le réseau ni la base.

## Critères d'acceptation

- [ ] Toutes les lignes de la page d'exemple donnent un résultat plausible (affiché dans la PR, tableau « ligne → quantité, unité, nom »).
- [ ] Aucune expression régulière sujette au retour sur trace catastrophique (test de lignes pathologiques de 10 000 caractères borné en temps).
- [ ] `make test` et `make quality` verts.
- [ ] PR vers `main`, description au gabarit, CI verte.

## Réalisation (2026-11-08)

- **Cinq fonctions pures statiques** sous `src/Repas/Domain/Service/Marmiton/` (`final readonly`, constructeur privé) : `MarmitonIngredientLineParser::parse`, `MarmitonRecipeTitle::clean`, `MarmitonServings::from`, `MarmitonRecipeCategory::typeSlug`, et le tableau `MarmitonUnitWords` (un seul endroit) ; `ParsedIngredientLine` en est le résultat. Plus de 250 cas de test, dont 150 lignes d'ingrédients et 22 lignes pathologiques de 10 000 caractères (moins de 50 ms chacune ; l'entrée est bornée à 1 000 caractères avant toute expression régulière, aucune n'est imbriquée).
- **Choix pour les cas ambigus** : « tasse » seule est une tasse à café et « cuillère » seule une cuillère à soupe (les deux seules de Repas) ; « dl » n'est pas une unité de Repas (il reste dans le nom, en `unite`) ; « 2 x 200 g de crème » vaut 2 `unite` et le nom « 200 g de crème » (le « x » est retiré) ; le nom est coupé à la première virgule ou au premier point-virgule (indications de préparation) et à « pour » ; « gros », « petit(e)s », « moyen(ne)s » ne sont retirés qu'en FIN de nom (« gros sel » et « petits pois » restent) ; une ligne sans quantité qui commence par une unité (« pincée de sel ») vaut 1 de cette unité ; une quantité sans produit (« 250 g », « 1/2 ») est ignorée (`null`) ; une fourchette « 2 à 3 », « 2 ou 3 », « 2-3 » prend la plus petite ; « un peu de » et « quelques » valent 1 `unite` ; un nom sans lettre ni chiffre est ignoré.
- **`pincee`** : ajoutée à `unit.json`. Comme le modèle du skill Alexa est généré depuis ce fichier (et que `DocsVoiceSkillTest` impose qu'il couvre toutes les unités), `SpokenUnits::FORMS` et `UNIT_SYNONYMS` de `generate-model.mjs` la disent aussi et `make voice-model` a régénéré le modèle ; **le modèle n'est pas envoyé à Amazon** (`make voice-model-push` reste à faire par le propriétaire quand il le voudra). La migration de l'unité reste en MAR-003.
