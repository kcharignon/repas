# Roadmap : envoi d'une liste au drive

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Le numéro d'issue GitHub est ajouté dans la colonne « Issue » quand les issues sont créées. Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md), [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Architecture : [`architecture.md`](architecture.md).

**Dépôts** : `back` = `kcharignon/repas` (ce dépôt) ; `front` = `kcharignon/repas-front` (dossier `drive-agent/` pour l'agent) ; `humain` = tâche pour l'utilisateur ; `assistant` = rédaction de documentation.

**Règle du chantier** : aucun code produit avant la décision **G1** (DRV-005). Peuvent démarrer tout de suite : DRV-001, DRV-003, DRV-004.

## M0 — Découverte (spikes, décision G1)

- [ ] [DRV-001](tickets/DRV-001-banc-d-essai-favori.md) front · M · Banc d'essai du favori (outil de test jetable) · dépend de : — · Issue : —
- [ ] [DRV-002](tickets/DRV-002-execution-telephone-rapport.md) humain · S · Exécution sur le téléphone Android et rapport · dépend de : 001 · Issue : —
- [ ] [DRV-003](tickets/DRV-003-lecture-cgu-leclerc.md) humain · S · Lecture des CGU/CGV de Leclerc · dépend de : — · Issue : —
- [ ] [DRV-004](tickets/DRV-004-releve-entetes-securite.md) humain · S · Relevé des en-têtes de sécurité · dépend de : — · Issue : —
- [ ] [DRV-005](tickets/DRV-005-synthese-decision-g1.md) assistant · S · Synthèse et décision G1 · dépend de : 002, 003, 004 · Issue : —

**Gate G1** : le favori s'exécute sur le téléphone, les requêtes passent DataDome, les CGU n'interdisent pas l'usage, le cadre (P1) ou la liste de résultats (P2) fonctionne. Sinon : Firefox + gestionnaire de scripts, ou mode assisté seulement, ou arrêt. ADR 0022 passe `Accepted` ou `Rejected`.

## M1 — Back `Repas` (après G1)

- [ ] [DRV-101](tickets/DRV-101-liste-envoyee-verrou.md) back · L · Liste envoyée : statut `SENT`, verrou, transitions · dépend de : 005 · Issue : —
- [ ] [DRV-102](tickets/DRV-102-api-send-reopen.md) back · M · API `send` / `reopen` et DTO · dépend de : 101, 103 · Issue : —
- [ ] [DRV-103](tickets/DRV-103-interrupteur-drive-enabled.md) back · S · Interrupteur `DRIVE_ENABLED` · dépend de : — · Issue : —
- [ ] [DRV-104](tickets/DRV-104-lignes-liste-slugs.md) back · S · Lignes de liste : `ingredientSlug` et `unitSlug` · dépend de : — · Issue : —
- [ ] [DRV-105](tickets/DRV-105-unite-dimension.md) back · M · Unités : `dimension` et `baseFactor` · dépend de : — · Issue : —

## M2 — Back `Drive` (après G1)

- [ ] [DRV-201](tickets/DRV-201-squelette-contexte-drive.md) back · S · Squelette du contexte `Drive` · dépend de : — · Issue : —
- [ ] [DRV-202](tickets/DRV-202-port-shared-liste-pour-drive.md) back · S · Port `Shared` de lecture de la liste · dépend de : 201, 104, 105 · Issue : —
- [ ] [DRV-203](tickets/DRV-203-reglages-du-drive.md) back · M · Réglages du drive · dépend de : 201, 103 · Issue : —
- [ ] [DRV-204](tickets/DRV-204-produits-et-choix.md) back · L · Produits et choix · dépend de : 201 · Issue : —
- [ ] [DRV-205](tickets/DRV-205-pack-calculator.md) back · M · `PackCalculator` · dépend de : 201 · Issue : —
- [ ] [DRV-206](tickets/DRV-206-plan-d-envoi.md) back · M · Plan d'envoi · dépend de : 202, 203, 204, 205 · Issue : —
- [ ] [DRV-207](tickets/DRV-207-purge-compte-drive.md) back · S · Purge à la suppression du compte · dépend de : 203, 204 · Issue : —

## M3 — Agent (front, `drive-agent/`)

- [ ] [DRV-301](tickets/DRV-301-squelette-agent.md) front · M · Squelette de l'agent · dépend de : 005 · Issue : —
- [ ] [DRV-302](tickets/DRV-302-protocole-v1.md) front · M · Protocole v1 · dépend de : 301 · Issue : —
- [ ] [DRV-303](tickets/DRV-303-adaptateur-leclerc.md) front · L · Adaptateur Leclerc · dépend de : 301, 302 · Issue : —
- [ ] [DRV-304](tickets/DRV-304-interface-superposee.md) front · L · Interface superposée · dépend de : 301 · Issue : —
- [ ] [DRV-305](tickets/DRV-305-orchestrateur-phase-a.md) front · M · Orchestrateur, phase A · dépend de : 302, 303, 304 · Issue : —
- [ ] [DRV-306](tickets/DRV-306-choix-guide-phase-b.md) front · L · Choix guidé, phase B · dépend de : 305 · Issue : —
- [ ] [DRV-307](tickets/DRV-307-mode-configuration.md) front · S · Mode configuration · dépend de : 302, 303 · Issue : —
- [ ] [DRV-308](tickets/DRV-308-mode-diagnostic.md) front · S · Mode diagnostic · dépend de : 303, 304 · Issue : —
- [ ] [DRV-309](tickets/DRV-309-faux-site-leclerc-e2e.md) front · L · Faux site Leclerc et tests e2e de l'agent · dépend de : 303 · Issue : —
- [ ] [DRV-310](tickets/DRV-310-publication-favori.md) front · S · Publication du favori · dépend de : 306 · Issue : —

## M4 — Front

- [ ] [DRV-401](tickets/DRV-401-liste-envoyee-ui.md) front · M · Liste envoyée dans l'interface · dépend de : 102, 104 · Issue : —
- [ ] [DRV-402](tickets/DRV-402-module-drive-bridge.md) front · M · Module `driveBridge` · dépend de : 302 · Issue : —
- [ ] [DRV-403](tickets/DRV-403-reglages-drive-ui.md) front · L · Réglages du drive · dépend de : 203, 204, 307, 402 · Issue : —
- [ ] [DRV-404](tickets/DRV-404-flux-d-envoi.md) front · L · Flux d'envoi · dépend de : 102, 206, 401, 402 · Issue : —
- [ ] [DRV-405](tickets/DRV-405-retour-et-recapitulatif.md) front · L · Retour et récapitulatif · dépend de : 204, 402 · Issue : —
- [ ] [DRV-406](tickets/DRV-406-tests-e2e-pixel.md) front · M · Tests e2e Pixel 7, accessibilité, visuel · dépend de : 403, 404, 405 · Issue : —
- [ ] [DRV-407](tickets/DRV-407-adr-front-et-claude-md.md) front · S · ADR front 0008 et `CLAUDE.md` · dépend de : 404 · Issue : —
- [ ] [DRV-408](tickets/DRV-408-page-d-aide.md) front · S · Page d'aide · dépend de : 403 · Issue : —
- [ ] [DRV-409](tickets/DRV-409-admin-unites-dimension.md) front · S · Administration des unités : dimension · dépend de : 105 · Issue : —

## M5 — Intégration et livraison

- [ ] [DRV-501](tickets/DRV-501-integration-dev.md) back+front · M · Intégration en développement et checklist de recette · dépend de : M2, M3, M4 · Issue : —
- [ ] [DRV-502](tickets/DRV-502-deploiement.md) back · S · Déploiement, interrupteur, retour arrière · dépend de : 501 · Issue : —
- [ ] [DRV-503](tickets/DRV-503-runbook-leclerc-change.md) back · S · Runbook « Leclerc a changé » · dépend de : 501 · Issue : —
- [ ] [DRV-504](tickets/DRV-504-note-vie-privee.md) back+front · S · Note de vie privée · dépend de : 204 · Issue : —
- [ ] [DRV-505](tickets/DRV-505-recette-reelle.md) humain · S · Recette en conditions réelles et activation · dépend de : 502, 503 · Issue : —

## Backlog (non détaillé, à écrire quand il devient utile)

DRV-601 suggestions automatiques · DRV-602 journal d'envoi · DRV-603 Firefox + gestionnaire de scripts ou extension · DRV-604 vignettes dans Repas (exception CSP ou relais d'images) · DRV-605 conversions unité↔poids par ingrédient · DRV-606 reprise des choix au changement de magasin (EAN, libellé) · DRV-607 deuxième enseigne (spike + adaptateur) · DRV-608 enregistrement direct des choix par jeton à usage unique · DRV-609 partage de choix entre proches · DRV-610 rapport d'exécution anonyme.

## Chemin critique et parallélisme

- Critique : 001 → 002 → 005 (G1) → 301 → 302 → 303 → 304 → 305 → 306 → 309 → 501.
- Back en parallèle : 101 → 102 ; 201 → (203, 204, 205) → 206 → 207 ; 103, 104, 105 indépendants.
- Front en parallèle : 402 (après 302) → 404 / 405 ; 401 après 102 et 104 ; 403 après 203, 204, 307.
- **Première version utilisable** = M0 à M5 sans le backlog.

## Definition of Ready d'un ticket

Ses dépendances sont fusionnées, ses décisions ouvertes sont tranchées (sinon le ticket les liste), il tient dans une PR relisible.

## Definition of Done (communes)

- **Back** : celle du skill `repas-dev` (`.claude/skills/repas-dev/SKILL.md` § 4) : `make test`, `make quality`, scénario `WriteBudget` pour tout use case d'écriture, `docs/api/openapi.json` régénéré (`make openapi`), purge du compte pour toute colonne `user`, ADR amendé si une convention change.
- **Front** : `make check` vert (lint, format, types, tests), seuil de couverture non abaissé, types de l'API générés (`npm run api:update`), textes dans `src/i18n/fr.ts`, test visuel Chromium mobile et bureau pour tout écran, `CLAUDE.md` du front à jour si une règle change.
- **Tous** : commits courts en français sans `Co-Authored-By`, PR avec le gabarit Quoi / Vérifié / À relire sans pied d'attribution, fusion en squash seulement si la CI est verte, jamais de push sur `main` ni de force-push, cette roadmap cochée, aucun identifiant ni secret dans le dépôt (public).
