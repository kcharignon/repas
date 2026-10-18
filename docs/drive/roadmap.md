# Roadmap : envoi d'une liste au drive

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Le numéro d'issue GitHub est ajouté dans la colonne « Issue » quand les issues sont créées. Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md), [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Architecture : [`architecture.md`](architecture.md).

**Dépôts** : `back` = `kcharignon/repas` (ce dépôt) ; `front` = `kcharignon/repas-front` (dossier `drive-agent/` pour l'agent) ; `humain` = tâche pour l'utilisateur ; `assistant` = rédaction de documentation.

**Règle du chantier** : G1 (DRV-005) est décidée (GO, 2026-10-17) : les tickets M1 à M5 peuvent démarrer selon leurs dépendances. Le spike `android-lab`, le banc `lab` et le contenu de `drive-agent/lab/` restent des outils jetables.

**Épiques GitHub** (dépôt back) : [M0 #88](https://github.com/kcharignon/repas/issues/88) · [M1 #89](https://github.com/kcharignon/repas/issues/89) · [M2 #90](https://github.com/kcharignon/repas/issues/90) · [M3 #91](https://github.com/kcharignon/repas/issues/91) · [M4 #92](https://github.com/kcharignon/repas/issues/92) · [M5 #93](https://github.com/kcharignon/repas/issues/93). Une issue par ticket, dans le dépôt indiqué (labels `drive`, `spike`, `agent`, `humain`, `epic`).

## M0 — Découverte (spikes, décision G1)

- [x] [DRV-001](tickets/DRV-001-banc-d-essai-favori.md) front · M · Banc d'essai du favori (outil de test jetable) · dépend de : — · Issue : [repas-front#38](https://github.com/kcharignon/repas-front/issues/38)
- [x] [DRV-002](tickets/DRV-002-execution-telephone-rapport.md) humain · S · Exécution sur le téléphone Android et rapport (faite sur PC puis dans l'application de test, DRV-011) · dépend de : 001 · Issue : [#66](https://github.com/kcharignon/repas/issues/66)
- [x] [DRV-003](tickets/DRV-003-lecture-cgu-leclerc.md) humain · S · Lecture des CGU/CGV de Leclerc (clos par décision de l'utilisateur le 2026-10-17, D23) · dépend de : — · Issue : [#67](https://github.com/kcharignon/repas/issues/67)
- [x] [DRV-004](tickets/DRV-004-releve-entetes-securite.md) humain · S · Relevé des en-têtes de sécurité (accueil et recherche faits ; page panier à relever à l'intégration) · dépend de : — · Issue : [#68](https://github.com/kcharignon/repas/issues/68)
- [x] [DRV-006](tickets/DRV-006-installation-favori-telephone.md) humain · S · Test d'installation d'un favori sur le téléphone (limite de taille) · dépend de : — · Issue : [#96](https://github.com/kcharignon/repas/issues/96)
- [x] [DRV-007](tickets/DRV-007-requete-ajout-panier.md) humain · S · Relevé de la vraie requête d'ajout au panier (l'ajout du banc répond 404) · dépend de : — · Issue : [#98](https://github.com/kcharignon/repas/issues/98)
- [x] [DRV-008](tickets/DRV-008-corriger-ajout-banc-d-essai.md) front · S · Corriger l'ajout du banc d'essai et tester les quantités · dépend de : 007 · Issue : [repas-front#60](https://github.com/kcharignon/repas-front/issues/60)
- [x] [DRV-009](tickets/DRV-009-chargeur-du-banc-d-essai.md) front · S · Chargeur court pour le banc d'essai (installation sur le téléphone) · dépend de : 008 · Issue : [repas-front#61](https://github.com/kcharignon/repas-front/issues/61)
- [ ] ~~[DRV-010](tickets/DRV-010-essai-firefox-android.md)~~ humain · S · (mis de côté) Essai du favori de démarrage dans Firefox Android (Chrome Android refuse les favoris JavaScript) · dépend de : 009 · Issue : [#112](https://github.com/kcharignon/repas/issues/112)
- [x] [DRV-011](tickets/DRV-011-spike-application-android.md) front · M · Spike : application Android jetable (WebView + banc d'essai) · dépend de : 009 · Issue : [repas-front#66](https://github.com/kcharignon/repas-front/issues/66)
- [x] [DRV-005](tickets/DRV-005-synthese-decision-g1.md) assistant · S · Synthèse et décision G1 (GO le 2026-10-17, application Android ; voir spikes/DRV-005-decision-g1.md) · dépend de : 002, 003, 004, 006, 007, 008, 009, 011 · Issue : [#69](https://github.com/kcharignon/repas/issues/69)

**Gate G1 (passée le 2026-10-17, GO avec application Android)** : le favori s'exécute sur le téléphone, les requêtes passent DataDome, les CGU n'interdisent pas l'usage, le cadre (P1) ou la liste de résultats (P2) fonctionne. Sinon : Firefox + gestionnaire de scripts, ou mode assisté seulement, ou arrêt. ADR 0022 passe `Accepted` ou `Rejected`.

## M1 — Back `Repas` (après G1)

- [x] [DRV-101](tickets/DRV-101-liste-envoyee-verrou.md) back · L · Liste envoyée : statut `SENT`, verrou, transitions · dépend de : 005 · Issue : [#70](https://github.com/kcharignon/repas/issues/70)
- [x] [DRV-102](tickets/DRV-102-api-send-reopen.md) back · M · API `send` / `reopen` et DTO · dépend de : 101, 103 · Issue : [#71](https://github.com/kcharignon/repas/issues/71)
- [x] [DRV-103](tickets/DRV-103-interrupteur-drive-enabled.md) back · S · Interrupteur `DRIVE_ENABLED` · dépend de : — · Issue : [#72](https://github.com/kcharignon/repas/issues/72)
- [x] [DRV-104](tickets/DRV-104-lignes-liste-slugs.md) back · S · Lignes de liste : `ingredientSlug` et `unitSlug` · dépend de : — · Issue : [#73](https://github.com/kcharignon/repas/issues/73)
- [x] [DRV-105](tickets/DRV-105-unite-dimension.md) back · M · Unités : `dimension` et `baseFactor` · dépend de : — · Issue : [#74](https://github.com/kcharignon/repas/issues/74)

## M2 — Back `Drive` (après G1)

- [x] [DRV-201](tickets/DRV-201-squelette-contexte-drive.md) back · S · Squelette du contexte `Drive` · dépend de : — · Issue : [#75](https://github.com/kcharignon/repas/issues/75)
- [x] [DRV-202](tickets/DRV-202-port-shared-liste-pour-drive.md) back · S · Port `Shared` de lecture de la liste · dépend de : 201, 104, 105 · Issue : [#76](https://github.com/kcharignon/repas/issues/76)
- [x] [DRV-203](tickets/DRV-203-reglages-du-drive.md) back · M · Réglages du drive · dépend de : 201, 103 · Issue : [#77](https://github.com/kcharignon/repas/issues/77)
- [x] [DRV-204](tickets/DRV-204-produits-et-choix.md) back · L · Produits, choix et priorités · dépend de : 201, 202, 203 · Issue : [#78](https://github.com/kcharignon/repas/issues/78)
- [x] [DRV-205](tickets/DRV-205-pack-calculator.md) back · M · `PackCalculator` · dépend de : 201 · Issue : [#79](https://github.com/kcharignon/repas/issues/79)
- [x] [DRV-206](tickets/DRV-206-plan-d-envoi.md) back · M · Plan d'envoi · dépend de : 202, 203, 204, 205 · Issue : [#80](https://github.com/kcharignon/repas/issues/80)
- [x] [DRV-207](tickets/DRV-207-purge-compte-drive.md) back · S · Purge à la suppression du compte (dont lignes de produits) · dépend de : 203, 204 · Issue : [#81](https://github.com/kcharignon/repas/issues/81)
- [x] [DRV-208](tickets/DRV-208-remplacement-preference.md) back · M · Remplacer la préférence d'un ingrédient (ordre, refus) · dépend de : 204 · Issue : [#82](https://github.com/kcharignon/repas/issues/82)

## M2b — Estimation du coût

- [x] [DRV-611](tickets/DRV-611-estimation-cout-liste.md) back · M · Estimation du coût d'une liste à partir des prix mémorisés · dépend de : 204, 205, 206 · Issue : [#144](https://github.com/kcharignon/repas/issues/144)
- [x] [DRV-612](tickets/DRV-612-ecran-estimation-cout.md) front · M · Écran de l'estimation du coût d'une liste · dépend de : 611, 401 · Issue : [repas-front#99](https://github.com/kcharignon/repas-front/issues/99)

## M2c — Durcissement (revue de sécurité du back)

- [x] [DRV-613](tickets/DRV-613-durcissement-fiches-produits.md) back puis front · M · Durcissement des fiches de produits partagées, plafonds, `reopen` interrupteur coupé (partie 1 back : PR #166 ; partie 2 agent : PR front #116, 2026-10-18) · dépend de : 204, 305, 306 · Issue : [#164](https://github.com/kcharignon/repas/issues/164)
- [x] [DRV-614](tickets/DRV-614-retour-limite-au-plan.md) front · S · Le retour de l'agent ne retient que les lignes du plan, message `DRIVE_LIMIT_REACHED`, « Rouvrir » interrupteur coupé (constat C1 de la revue du front) · dépend de : 405, 613

## M3 — Agent (front, `drive-agent/`)

- [x] [DRV-301](tickets/DRV-301-squelette-agent.md) front · M · Squelette de l'agent · dépend de : 005 · Issue : [repas-front#39](https://github.com/kcharignon/repas-front/issues/39)
- [x] [DRV-302](tickets/DRV-302-protocole-v1.md) front · M · Protocole v1 · dépend de : 301 · Issue : [repas-front#40](https://github.com/kcharignon/repas-front/issues/40)
- [x] [DRV-303](tickets/DRV-303-adaptateur-leclerc.md) front · L · Adaptateur Leclerc · dépend de : 301, 302 · Issue : [repas-front#41](https://github.com/kcharignon/repas-front/issues/41)
- [x] [DRV-304](tickets/DRV-304-interface-superposee.md) front · L · Interface superposée · dépend de : 301 · Issue : [repas-front#42](https://github.com/kcharignon/repas-front/issues/42)
- [x] [DRV-305](tickets/DRV-305-orchestrateur-phase-a.md) front · M · Orchestrateur, phase A · dépend de : 302, 303, 304 · Issue : [repas-front#43](https://github.com/kcharignon/repas-front/issues/43)
- [x] [DRV-306](tickets/DRV-306-choix-guide-phase-b.md) front · L · Choix guidé, phase B · dépend de : 305 · Issue : [repas-front#44](https://github.com/kcharignon/repas-front/issues/44)
- [x] [DRV-307](tickets/DRV-307-mode-configuration.md) front · S · Mode configuration · dépend de : 302, 303 · Issue : [repas-front#45](https://github.com/kcharignon/repas-front/issues/45)
- [x] [DRV-308](tickets/DRV-308-mode-diagnostic.md) front · S · Mode diagnostic · dépend de : 303, 304 · Issue : [repas-front#46](https://github.com/kcharignon/repas-front/issues/46)
- [x] [DRV-309](tickets/DRV-309-faux-site-leclerc-e2e.md) front · L · Faux site Leclerc et tests e2e de l'agent · dépend de : 303 · Issue : [repas-front#47](https://github.com/kcharignon/repas-front/issues/47)
- [ ] ~~[DRV-310](tickets/DRV-310-publication-favori.md)~~ (remplacé par DRV-315 : plus de favori, l'agent est embarqué dans l'application) front · S · Publication du favori · dépend de : 306 · Issue : [repas-front#48](https://github.com/kcharignon/repas-front/issues/48)

## M3b — Application Android (front, `android/`)

Prépare l'application « Repas Drive » (WebView en mode ordinateur, agent embarqué). Décision et contexte : [`spikes/DRV-005-decision-g1.md`](spikes/DRV-005-decision-g1.md), [`pivot-application-android.md`](pivot-application-android.md).

- [x] [DRV-311](tickets/DRV-311-squelette-application-android.md) front · M · Squelette de l'application Android (`android/`) · dépend de : 005 · Issue : [repas-front#69](https://github.com/kcharignon/repas-front/issues/69)
- [x] [DRV-312](tickets/DRV-312-hote-webview-mode-ordinateur.md) front · M · Hôte WebView en mode ordinateur · dépend de : 311 · Issue : [repas-front#70](https://github.com/kcharignon/repas-front/issues/70)
- [x] [DRV-313](tickets/DRV-313-injection-agent-et-pont.md) front · L · Injection de l'agent et pont de messages · dépend de : 312, 301, 302 · Issue : [repas-front#71](https://github.com/kcharignon/repas-front/issues/71)
- [x] [DRV-314](tickets/DRV-314-passage-du-plan-et-retour.md) front · L · Passage du plan et retour vers Repas · dépend de : 313, 302 · Issue : [repas-front#72](https://github.com/kcharignon/repas-front/issues/72)
- [ ] [DRV-315](tickets/DRV-315-publication-et-mises-a-jour-application.md) front+humain · M · Publication signée et mises à jour de l'application · dépend de : 311 · Issue : [repas-front#73](https://github.com/kcharignon/repas-front/issues/73)
- [x] [DRV-316](tickets/DRV-316-aide-installation-application.md) front · S · Aide et installation de l'application dans Repas (remplace DRV-408) · dépend de : 315, 402 · Issue : [repas-front#74](https://github.com/kcharignon/repas-front/issues/74)
- [x] [DRV-317](tickets/DRV-317-coque-application-repas.md) front · L · Coquille de l'application Repas (WebView sur le front Repas) · dépend de : 312, 313 · Issue : [repas-front#86](https://github.com/kcharignon/repas-front/issues/86)
- [x] [DRV-318](tickets/DRV-318-test-automatique-application.md) front · M · Test automatique en un geste dans l'application de débogage · dépend de : 313 · Issue : [repas-front#87](https://github.com/kcharignon/repas-front/issues/87)
- [x] [DRV-319](tickets/DRV-319-emulateur-android-docker.md) front · L · Émulateur Android en Docker pour tester l'application sans Leclerc · dépend de : 313 (partie 1), 317 et 309 (partie 2) · Issue : [repas-front#88](https://github.com/kcharignon/repas-front/issues/88)
- [x] [DRV-320](tickets/DRV-320-lecture-magasin-depuis-application.md) front · M · Lecture du magasin depuis l'application (`drive.setup`) et menu natif (PR front #111, 2026-10-18) · dépend de : 314, 307, 403 · Issue : [repas-front#103](https://github.com/kcharignon/repas-front/issues/103)



## M4 — Front

- [x] [DRV-401](tickets/DRV-401-liste-envoyee-ui.md) front · M · Liste envoyée dans l'interface · dépend de : 102, 104 · Issue : [repas-front#49](https://github.com/kcharignon/repas-front/issues/49)
- [x] [DRV-402](tickets/DRV-402-module-drive-bridge.md) front · M · Module `driveBridge` · dépend de : 302 · Issue : [repas-front#50](https://github.com/kcharignon/repas-front/issues/50)
- [x] [DRV-403](tickets/DRV-403-reglages-drive-ui.md) front · L · Réglages du drive · dépend de : 203, 204, 307, 402 · Issue : [repas-front#51](https://github.com/kcharignon/repas-front/issues/51)
- [x] [DRV-404](tickets/DRV-404-flux-d-envoi.md) front · L · Flux d'envoi · dépend de : 102, 206, 401, 402 · Issue : [repas-front#52](https://github.com/kcharignon/repas-front/issues/52)
- [x] [DRV-405](tickets/DRV-405-retour-et-recapitulatif.md) front · L · Retour et récapitulatif · dépend de : 204, 402 · Issue : [repas-front#53](https://github.com/kcharignon/repas-front/issues/53)
- [ ] [DRV-406](tickets/DRV-406-tests-e2e-pixel.md) front · M · Tests e2e Pixel 7, accessibilité, visuel · dépend de : 403, 404, 405 · Issue : [repas-front#54](https://github.com/kcharignon/repas-front/issues/54)
- [x] [DRV-407](tickets/DRV-407-adr-front-et-claude-md.md) front · S · ADR front 0008 et `CLAUDE.md` · dépend de : 404 · Issue : [repas-front#55](https://github.com/kcharignon/repas-front/issues/55)
- [ ] ~~[DRV-408](tickets/DRV-408-page-d-aide.md)~~ (remplacé par DRV-316) front · S · Page d'aide · dépend de : 403 · Issue : [repas-front#56](https://github.com/kcharignon/repas-front/issues/56)
- [x] [DRV-409](tickets/DRV-409-admin-unites-dimension.md) front · S · Administration des unités : dimension · dépend de : 105 · Issue : [repas-front#57](https://github.com/kcharignon/repas-front/issues/57)
- [x] [DRV-410](tickets/DRV-410-priorites-produits-ui.md) front · M · Écran « Mes produits » : priorités et produits refusés · dépend de : 208, 403 · Issue : [repas-front#58](https://github.com/kcharignon/repas-front/issues/58)

## M5 — Intégration et livraison

- [ ] [DRV-501](tickets/DRV-501-integration-dev.md) back+front · M · Intégration en développement et checklist de recette · dépend de : M2, M3, M3b, M4 · Issue : [#83](https://github.com/kcharignon/repas/issues/83) · partie back faite (PR #156), partie front faite (repas-front#106), `recette.md` écrite ; reste le parcours réel dans l'émulateur (DRV-319 partie 2) et `BASKET_PATH`
- [x] [DRV-502](tickets/DRV-502-deploiement.md) back · S · Déploiement, interrupteur, retour arrière · dépend de : 501 · Issue : [#84](https://github.com/kcharignon/repas/issues/84)
- [x] [DRV-503](tickets/DRV-503-runbook-leclerc-change.md) back · S · Runbook « Leclerc a changé » · dépend de : 501 · Issue : [#85](https://github.com/kcharignon/repas/issues/85)
- [ ] [DRV-504](tickets/DRV-504-note-vie-privee.md) back+front · S · Note de vie privée (partie back faite : texte et registre, PR #152 ; partie front faite : repas-front#101, #105 et #106 ; reste la relecture des textes par l'utilisateur) · dépend de : 204 · Issue : [#86](https://github.com/kcharignon/repas/issues/86)
- [ ] [DRV-505](tickets/DRV-505-recette-reelle.md) humain · S · Recette en conditions réelles et activation (procédure écrite : [`recette.md`](recette.md), à dérouler sur le téléphone, interrupteur allumé) · dépend de : 502, 503 · Issue : [#87](https://github.com/kcharignon/repas/issues/87)

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
