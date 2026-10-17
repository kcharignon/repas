# DRV-301 — Squelette de l'agent (build, favori, taille, CI)

- **Dépôt** : front (`kcharignon/repas-front`), dossier `drive-agent/`
- **Jalon** : M3 Agent
- **Taille** : M
- **Dépend de** : DRV-005 (décision G1 : Go)
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 2, 8, 10, 11 ; `CLAUDE.md` du front (tout tourne dans Docker : `make check`) ; `docs/adr/0004-front-dans-docker.md` ; DRV-001 (banc d'essai, à ne pas dupliquer).

## Contexte

L'agent est un script autonome qui tourne dans la page de l'enseigne, lancé par un favori. Il faut une structure, une chaîne de construction qui produit un fichier unique minifié et une chaîne `javascript:` prête à coller, un budget de taille, et des tests, avant d'écrire la moindre fonctionnalité.

## Portée

1. Structure de `drive-agent/` :
   - `src/index.ts` (point d'entrée : exécute l'agent au chargement), `src/protocol/` (DRV-302), `src/leclerc/` (DRV-303), `src/ui/` (DRV-304), `src/flow/` (DRV-305, 306), `src/diagnostic/` (DRV-308), `src/version.ts` ;
   - `test/` et `fixtures/` (données **synthétiques** inventées) ;
   - `scripts/build.mjs`, `README.md` (comment construire, vérifier l'empreinte, installer le favori) ;
   - `lab/` (DRV-001) reste à part et sera retiré après G1.
2. Construction (dans Docker, `make agent-build`) : un bundle unique **IIFE**, sans dépendance à l'exécution (aucune bibliothèque tierce dans le favori), minifié, cible navigateurs mobiles récents ; sorties `drive-agent/dist/agent.template.js` et `drive-agent/dist/manifest.json` (`{version, bytes, sha256}` du gabarit).
3. **Origine de Repas** : le gabarit contient une seule constante `const REPAS_ORIGIN = "__REPAS_ORIGIN__"` ; c'est le front qui, à l'installation du favori (DRV-403), remplace ce marqueur par `location.origin` avant d'encoder la chaîne `javascript:`. L'image de production du front ne contient ainsi aucun nom de domaine (règle du `CLAUDE.md` du front). L'agent refuse de s'exécuter si le marqueur n'a pas été remplacé (`UNSUPPORTED_PAGE` côté diagnostic) et ne lit jamais l'origine de retour dans un message.
4. Fonction `buildBookmarklet(template: string, origin: string): string` (module partagé avec le front) : remplace le marqueur (une seule occurrence exigée, sinon erreur), encode en `javascript:` + `encodeURIComponent`, vérifie le budget (`AGENT_MAX_URL_BYTES`, 60 000 par défaut ; la construction échoue au-delà).
5. Construction **reproductible** : versions épinglées, aucune date ni chemin absolu dans le bundle ; deux constructions successives donnent le même `sha256` (vérifié par un test et par la CI).
6. Qualité : lint (oxlint + ESLint), format (Prettier), types stricts (`tsconfig` dédié), Vitest (jsdom) ; une cible `make agent-test` ; intégrer `agent-build` et `agent-test` à `make check` (ou à la CI) sans ralentir inutilement le front.
7. Règles de code de l'agent, vérifiées par ESLint : pas de `eval`, pas de `new Function`, pas de `innerHTML` (affichage par `textContent`/`createElement`), pas de `fetch` vers un autre hôte que celui de la page, pas de dépendance d'exécution.
8. Version : `src/version.ts` exporte `AGENT_VERSION` (semver, `0.1.0` au départ), repris dans le manifeste et dans le résultat.

## Hors portée

Les fonctionnalités de l'agent (tickets suivants).

## Critères d'acceptation

- `make agent-build` produit `agent.template.js` et `manifest.json` ; `make agent-test` est vert ; la CI exécute les deux.
- `buildBookmarklet` échoue si le marqueur est absent ou présent deux fois, ou si la taille dépasse le budget.
- Deux constructions donnent le même `sha256`.
- Une règle ESLint interdit `eval`, `new Function` et `innerHTML` dans `drive-agent/src`.

## Tests exigés

Tests unitaires de `buildBookmarklet` (substitution, taille, marqueur) et de la reproductibilité du hachage.

## Definition of Done

Celle du front : `make check` vert, couverture non abaissée, PR avec le gabarit.

## Pièges connus

- Le front n'a pas Node sur l'hôte de l'utilisateur : tout passe par Docker (`make up`, `make check`).
- Ne pas committer `dist/`.
