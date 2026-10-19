# DRV-406 — Tests e2e Pixel 7, accessibilité et test visuel

- **Dépôt** : front, `e2e/`
- **Jalon** : M4 Front
- **Taille** : M
- **Dépend de** : DRV-403, DRV-404, DRV-405
- **Statut** : fait de fait, sans PR dédiée (constat du 2026-10-19 : les scénarios ont été écrits avec DRV-304, 316, 401, 403 à 405, 410, 501, 612, 614 et 615 ; deux écarts assumés, voir « Constat »)
- **Lire d'abord** : `playwright.config.ts`, `e2e/` existants, `CLAUDE.md` du front (`make e2e`, `make e2e-visual`, comptes de démonstration).

## Contexte

Le parcours complet se joue sur téléphone. Il faut des tests de bout en bout contre le vrai back de développement, avec un agent **simulé** (le vrai agent est testé contre le faux site dans DRV-309).

## Portée

1. Scénarios Playwright (profil Pixel 7 et bureau) : envoyer une liste (avec `DRIVE_ENABLED=true` en développement) → écran du plan → surcharges → ouverture simulée (une page de test sert de « Leclerc » et lit le fragment) → retour avec un résultat préparé → enregistrement des choix → récapitulatif → « Terminer » ; liste envoyée non modifiable ; « Rouvrir » ; « J'en ai toujours » puis « Réactiver » ; page de réglages.
2. Accessibilité (axe) sur chaque nouvel écran, clair et sombre.
3. Test visuel : captures mobile et bureau lues avant fusion.
4. Données de démonstration : `make fixtures` fournit les comptes `admin@repas.fr` et `user@repas.fr` ; ajouter ce qu'il faut côté back (magasin et choix de démonstration) uniquement si nécessaire et dans le dépôt back.

## Critères d'acceptation

- `make e2e` passe sur les nouveaux scénarios, séquentiellement (`workers: 1`) ; un échec est reproductible.
- Aucun secret ni identifiant réel dans les tests ; mots de passe de démonstration par variables d'environnement.

## Definition of Done

Celle du front ; captures jointes ou décrites dans la PR.

## Pièges connus

- Vider le limiteur de débit avant une série : `docker compose exec php bin/console cache:pool:clear cache.rate_limiter` (voir le `CLAUDE.md` du front).

## Constat du 2026-10-19 : déjà couvert par les tests du front

Relevé sur `main` du front (`54c5e3a`, lecture seule ; le job « Bout en bout (Playwright) » de la CI y est vert). Profils de `playwright.config.ts` : `chromium` (bureau) et `mobile` (Pixel 7), `workers: 1` en CI et contre un hôte externe, mots de passe de démonstration par les variables `E2E_EMAIL` / `E2E_PASSWORD` du `Makefile` (aucun secret).

| Portée du ticket | Où c'est couvert |
|---|---|
| 1. Envoyer une liste, la lire sans pouvoir la modifier, la rouvrir (y compris interrupteur coupé) | `e2e/shopping-list-sent.spec.ts` (bureau et Pixel 7) |
| 1. Écran du plan, « Choisir un autre produit », « Passer cette fois », ouverture simulée du drive, attente retrouvée après rechargement | `e2e/drive-send.spec.ts` (bureau et Pixel 7) |
| 1. Retour d'un résultat préparé, enregistrement des choix en un appel, récapitulatif, « Terminer la liste », résultat falsifié (seules les lignes du plan sont retenues), passage arrêté | `e2e/drive-return.spec.ts` (bureau et Pixel 7) |
| 1. Parcours complet réglages, liste envoyée, envoi, retour, choix enregistrés, récapitulatif | `e2e/drive-journey.spec.ts` (Pixel 7 seulement, `test.skip` hors mobile) |
| 1. Page de réglages (magasin saisi, lecture dans l'application, drive coupé) | `e2e/drive-settings.spec.ts` ; produits mémorisés : `e2e/drive-products.spec.ts` ; estimation : `e2e/drive-estimate.spec.ts` ; aide : `e2e/drive-help.spec.ts` ; limiteur de débit : `e2e/drive-rate-limit.spec.ts` |
| 2. Accessibilité (axe, WCAG A et AA), clair et sombre | `scan()` de `e2e/support/drive.ts`, appelé dans chacune des spécifications ci-dessus ; interface de l'agent : `e2e/agent-ui.spec.ts` (axe, 360 × 640 sans défilement horizontal, cibles de 44 px) |
| 3. Test visuel | `make e2e-visual` (`E2E_VISUAL=1` : capture, vidéo et trace de chaque test) ; la lecture des captures se fait à chaque PR d'écran |

**Deux écarts au texte du ticket, assumés** :

1. **L'API y est simulée** (`page.route`), non le vrai back de développement. `drive-journey.spec.ts` rejoue les **exemples de contrat** du back (`drive-agent/fixtures/contract/*.sample.json`, copie de `docs/drive/samples/`), que `tests/Drive/Contract/DriveContractSamplesTest` garde identiques aux vraies réponses (DRV-501). Le back réel est prouvé par ses propres tests de bout en bout (`tests/Drive/…ApiFlowTest`).
2. **« J'en ai toujours » puis « Réactiver » n'a pas de scénario e2e.** Le texte du ticket est antérieur à DRV-410 : il n'existe plus de bouton « Réactiver », c'est l'interrupteur « J'en ai toujours » de la fiche d'un produit (`src/features/drive/__tests__/Products.spec.ts`, test de composant). Si l'on veut un scénario de bout en bout, c'est un petit ticket de plus dans `e2e/drive-products.spec.ts` (le faux back `e2e/support/driveBack.ts` gère déjà `haveAtHome`).
