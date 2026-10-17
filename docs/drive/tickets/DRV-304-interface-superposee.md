# DRV-304 — Interface superposée de l'agent

- **Dépôt** : front, `drive-agent/src/ui/`
- **Jalon** : M3 Agent
- **Taille** : L
- **Dépend de** : DRV-301
- **Statut** : fait (PR [repas-front#79](https://github.com/kcharignon/repas-front/pull/79), 2026-10-17)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 8, 10 ; règles d'accessibilité du `CLAUDE.md` du front (WCAG AA, cibles ≥ 44 px).

## Contexte

L'agent affiche ses écrans par-dessus la page Leclerc, sur un téléphone. L'interface doit être isolée des styles de la page, lisible, accessible et sans aucune injection de HTML.

## Portée

1. Racine en **Shadow DOM** fermé attaché à un conteneur plein écran (`position: fixed`, `z-index` maximal) ; styles internes seulement ; aucune police externe, aucune image distante sauf les vignettes Leclerc du même site si DRV-306 (variante P2) les utilise.
2. Petite couche de rendu maison (fonctions `h()`/`text()` créant des nœuds avec `createElement` et `textContent`), **jamais** `innerHTML` (règle ESLint de DRV-301).
3. Écrans (composants purs recevant des données et des callbacks) :
   - **Accueil sans message** : « Ouvre d'abord Repas » + bouton « Diagnostic ».
   - **Récapitulatif et confirmation** : nombre de produits connus, nouveaux, « j'en ai toujours », ignorés ; liste repliable ; boutons « Commencer » et « Annuler » ; rappel « Repas ne vide pas ton panier Leclerc ».
   - **Progression** : barre, ligne en cours, journal des dernières actions, bouton « Arrêter » ; `aria-live="polite"`.
   - **Quantité à vérifier** : produit, quantité demandée, champ numérique 1..99 prérempli, « Valider » / « Passer ».
   - **Choix de produit (hôte)** : barre supérieure (ingrédient en cours, compteur) avec « Passer cette fois », « J'en ai toujours », « Annuler le passage », et zone de contenu (cadre natif P1 ou liste de candidats P2 — contenu fourni par DRV-306).
   - **Erreurs** par code (`NOT_LOGGED_IN` : « Connecte-toi à Leclerc puis relance le favori » ; `STORE_MISMATCH` ; `BLOCKED` : « Leclerc a ralenti les requêtes, attends puis recharge la page » ; `CONTRACT_CHANGED` : « Le site a changé, mets à jour le favori » ; `NETWORK` ; `INVALID_MESSAGE` ; `UNSUPPORTED_PAGE`).
   - **Fin** : récapitulatif (ajoutés, choisis, passés, échecs) et bouton « Retourner dans Repas ».
4. Textes en français dans un module unique `strings.ts` (tutoiement, phrases courtes).
5. Accessibilité : cibles tactiles ≥ 44 px, contrastes AA, focus géré (premier bouton à l'ouverture de chaque écran, piège de focus), rôles et libellés ARIA, thème clair/sombre selon `prefers-color-scheme`, `prefers-reduced-motion`, aucune dépendance à la couleur seule.
6. Une API `ui.show(screen, props)` retournant une promesse résolue par l'action de l'utilisateur, pour que l'orchestrateur soit testable sans DOM réel.

## Hors portée

Logique des phases (DRV-305, 306).

## Critères d'acceptation

- Chaque écran s'affiche sur un viewport 360×640 sans défilement horizontal, avec des libellés complets.
- Un libellé de produit contenant `<img onerror=…>` ou `<script>` s'affiche tel quel, sans exécution.
- La navigation au clavier et au lecteur d'écran fonctionne (test d'accessibilité automatisé sans violation).
- Le style de la page hôte (`* { display:none }` simulé) n'altère pas l'interface.

## Tests exigés

Vitest (jsdom) pour chaque écran (rendu, callbacks, échappement) ; test Playwright d'accessibilité (axe) sur une page de démonstration locale `drive-agent/demo/` qui affiche chaque écran (hors production) ; captures lues sur mobile.

## Definition of Done

Celle du front, y compris test visuel Chromium mobile.

## Pièges connus

- Un Shadow DOM fermé empêche la page hôte de lire l'interface : c'est voulu.
- Ne pas stocker de données dans le DOM de la page hôte.

## Réalisation (2026-10-17, PR repas-front#79)

`drive-agent/src/ui/index.ts` : `createUi({ win?, mode? })` → `UiHandle { show(écran, props), update(patch), close() }`. Écrans : `welcome`, `summary`, `resume`, `progress`, `quantity`, `chooser`, `error`, `end`. `ui.show('chooser', { ingredient, position, total, mount(zone, answer) })` : `mount` remplit la zone (peut rendre un nettoyeur), `answer(valeur)` conclut `{kind:'content', value}` ; les boutons de la barre rendent `{kind:'skip' | 'have' | 'cancel'}`. Aides : `h`, `text`, `append`, `clear`, `S`, `ERROR_TEXTS`, `count`. Textes : « relance l'envoi depuis Repas » et « mets à jour l'application Repas Drive » à la place du favori. Test Playwright `e2e/agent-ui.spec.ts` (axe sans violation A/AA sur 18 écrans, mobile et bureau, clair et sombre).
