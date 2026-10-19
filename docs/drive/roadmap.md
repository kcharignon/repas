# Roadmap : envoi d'une liste au drive

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Le numéro d'issue GitHub est ajouté dans la colonne « Issue » quand les issues sont créées. Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md), [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Architecture : [`architecture.md`](architecture.md).

**Dépôts** : `back` = `kcharignon/repas` (ce dépôt) ; `front` = `kcharignon/repas-front` (dossier `drive-agent/` pour l'agent) ; `humain` = tâche pour l'utilisateur ; `assistant` = rédaction de documentation.

**Règle du chantier** : G1 (DRV-005) est décidée (GO, 2026-10-17) : les tickets M1 à M5 peuvent démarrer selon leurs dépendances. Le spike `android-lab`, le banc `lab` et le contenu de `drive-agent/lab/` ont été retirés du dépôt front (PR front #134, 2026-10-19), après l'essai réel ; la pré-version `android-dev-*` (workflow `android.yml`) reste, le runbook s'en sert pour le « Test automatique ».

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
- [x] [DRV-615](tickets/DRV-615-limiteur-de-debit.md) back · S · Limiteur de débit par utilisateur sur les écritures et les lectures coûteuses du drive, `429 DRIVE_RATE_LIMITED` (constat C2 de la revue du back) · dépend de : 204, 613 · Issue : [#175](https://github.com/kcharignon/repas/issues/175)

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
- [ ] [DRV-315](tickets/DRV-315-publication-et-mises-a-jour-application.md) front+humain · M · Publication signée et mises à jour de l'application (workflow livré, PR front #122 ; **applications signées publiées : `android-v0.1.0` à `android-v0.1.4`, 2026-10-18 (UTC)**, `repas-drive.apk`, `.sha256` et `version.json` ; reste la vérification « nouvelle version disponible » dans l'application) · dépend de : 311 · Issue : [repas-front#73](https://github.com/kcharignon/repas-front/issues/73)
- [x] [DRV-316](tickets/DRV-316-aide-installation-application.md) front · S · Aide et installation de l'application dans Repas (remplace DRV-408) · dépend de : 315, 402 · Issue : [repas-front#74](https://github.com/kcharignon/repas-front/issues/74)
- [x] [DRV-317](tickets/DRV-317-coque-application-repas.md) front · L · Coquille de l'application Repas (WebView sur le front Repas) · dépend de : 312, 313 · Issue : [repas-front#86](https://github.com/kcharignon/repas-front/issues/86)
- [x] [DRV-318](tickets/DRV-318-test-automatique-application.md) front · M · Test automatique en un geste dans l'application de débogage · dépend de : 313 · Issue : [repas-front#87](https://github.com/kcharignon/repas-front/issues/87)
- [x] [DRV-319](tickets/DRV-319-emulateur-android-docker.md) front · L · Émulateur Android en Docker pour tester l'application sans Leclerc · dépend de : 313 (partie 1), 317 et 309 (partie 2) · Issue : [repas-front#88](https://github.com/kcharignon/repas-front/issues/88)
- [x] [DRV-320](tickets/DRV-320-lecture-magasin-depuis-application.md) front · M · Lecture du magasin depuis l'application (`drive.setup`) et menu natif (PR front #111, 2026-10-18) · dépend de : 314, 307, 403 · Issue : [repas-front#103](https://github.com/kcharignon/repas-front/issues/103)

## M3c — Corrections de la recette réelle (front, `drive-agent/` et `android/`, 2026-10-18 et 19)

La recette réelle sur téléphone (DRV-505) a montré des défauts que le faux site ne montrait pas. Ils ont été corrigés **sans ticket DRV**, par des PR du dépôt front, à partir de l'audit [`hypotheses-site-reel.md`](hypotheses-site-reel.md) (tickets candidats A à P de son § 6, constats de son § 8 ; statut de chacun en § 9). Une ligne par PR : quoi · PR front · version de l'agent (`drive-agent/src/version.ts`) · première application qui l'embarque. Les sections « Réalisation » des tickets DRV concernés (DRV-302 à 309, 312, 314, 319, 320) renvoient ici.

**Au 2026-10-19** : `main` du front porte l'agent **0.15.0** ; les applications publiées sont `android-v0.1.0` à `android-v0.1.4` (le front web est en `v0.5.1`, le back en `v0.6.1`) ; la dernière, `android-v0.1.4`, embarque l'agent **0.11.0** : les agents 0.12.0 à 0.15.0 ne sont dans aucune application publiée. **Avant de publier l'application suivante, déployer le front** (le validateur du protocole accepte le champ `stats`, #138) : [`architecture.md`](architecture.md) § 12 et [`runbook.md`](runbook.md) § 7.4.

- [x] repas-front#125 · agent inchangé (0.8.2) · Un plan s'ouvre sur `/magasin-N-N/` (au lieu de la racine de l'hôte) ; un plan n'est envoyé à l'agent que sur une page de magasin, sinon un message d'attente (`StoreBanner`). **Hypothèse réfutée par la recette** (cette adresse est une page 404 du vrai site) : remplacée par #128, puis #132 · `android-v0.1.1`
- [x] repas-front#126 · agent 0.9.0 · Interface de l'agent lisible et touchable sur le site en mode ordinateur (cadre agrandi de 1 / `visualViewport.scale`, borné à 1..3 ; mesuré à 2,377 sur un Pixel 9), « Agrandir la page » et « Réduire la page » dans le menu du drive, émulateur réglé comme un Pixel 9 · `android-v0.1.1`
- [x] repas-front#127 · agent 0.9.1 · Tickets A, C et G de l'audit : **cause fine** de chaque erreur (suffixe `(SEARCH_NO_STATE)`, `(HTTP_404)`… dans `error.message`, dans le diagnostic), **« aucun résultat » reconnu par la structure** de la page (plus jamais `CONTRACT_CHANGED` pour une liste vide), **visiteur non connecté** détecté (`NOT_LOGGED_IN`, cause `SESSION_VISITOR`, avant le récapitulatif de départ) · `android-v0.1.2`
- [x] repas-front#128 · agent inchangé (0.9.1) · Ticket E : bandeau d'attente (« Commencer mes courses » se touche à la main) et **repli** quand le site refuse la page d'ouverture (statut 4xx/5xx, 25 s sans fin de chargement, 10 s sans agent) : bandeau et bouton « Ouvrir l'accueil du drive » (`OpeningWatch`, `StoreBanner`), page de défi laissée visible, retour du téléphone = abandon de l'envoi une fois le plan parti. Ouvrait la racine de l'hôte du magasin, **autre hypothèse fausse** (page 404) : adresse corrigée par #132 · `android-v0.1.3`
- [x] repas-front#129 · agent 0.10.0 · Ticket B : la recherche de l'agent part du **chemin avec la ville lu dans la page** (cause du HTTP 400 de la recette confirmée), repli sur un 400 par chaque autre forme de requête, **sondes du diagnostic** (formes, noms et nombres, jamais une valeur du site ; exception bornée à la lecture de `document.cookie`, voir `consignes-agents.md`) · `android-v0.1.3`
- [x] repas-front#130 · agent 0.11.0 · Ticket D : **repli de recherche par formes dérivées du nom** (sans accents, « œ » en « oe », singulier ou pluriel, sans mots de liaison, premier mot ; 4 formes au plus, 5 recherches au plus par nom) en phase B, et par les formes du libellé d'un produit connu en phase A avant de le dire « plus disponible » · `android-v0.1.4`
- [x] repas-front#131 · aucun code de production · Tests de l'injection isolés (flake à 27 requêtes au lieu de 25 : 11 échecs sur 60 avant sous charge, 0 sur 160 après) · `android-v0.1.4`
- [x] repas-front#132 · agent inchangé (0.11.0) · **Le plan s'ouvre sur l'accueil de l'enseigne `https://www.leclercdrive.fr/`** (`NavigationPolicy.START_URL`, comme la lecture du magasin) ; faux site rendu fidèle (racine de l'hôte du magasin et `/magasin-N-N/` en 404) ; scénario 12 de `make android-e2e` · `android-v0.1.4`
- [x] repas-front#133 · docs · Skill `/repas-po` copié dans le front (référence : [`principes-produit.md`](principes-produit.md))
- [x] repas-front#134 · agent inchangé (0.11.0) · Retrait du banc d'essai `drive-agent/lab/` et de l'application de test `android-lab` (constat C6 de la revue de sécurité du front) ; `android.yml` et ses pré-versions `android-dev-*` restent
- [x] repas-front#135 · agent 0.12.0 · Ticket M : **ne jamais réduire une quantité déjà au panier** ; la quantité du plan est un plancher, l'agent n'écrit que si elle dépasse ce que le panier contient, et l'écran de fin le dit (« Déjà N au panier : conservé ») · pas encore publiée
- [x] repas-front#136 · agent 0.13.0 · Ticket K : **délai de 20 s par requête** (une reprise après 2 s, puis `NETWORK`, cause `REQUEST_TIMEOUT`) et **pause devant la page de défi de l'anti-robot** (bandeau « Leclerc demande une vérification », « Reprendre » / « Arrêter », cause `CHALLENGE`) · pas encore publiée
- [x] repas-front#137 · agent 0.14.0 · Ticket J : **site mobile `m-courses` refusé tout de suite** (`UNSUPPORTED_PAGE`, cause `PAGE_MOBILE`), côté agent et côté application (bandeau « Leclerc affiche son site mobile », `NavigationPolicy.isMobileSite`) · pas encore publiée
- [x] repas-front#138 · agent 0.15.0 · Ticket L : **compteurs d'un passage** (champ de résultat facultatif `stats: {requests, errors, blocks}`, ligne « Requêtes du diagnostic » dans le diagnostic) et **cadence adaptative** (valeurs non mesurées sur le vrai site) · pas encore publiée. **Protocole changé : le front doit être déployé avant l'application.**

**Tickets candidats de l'audit non faits** (`hypotheses-site-reel.md` § 6) : F (le plan porte l'adresse d'entrée du magasin), H (écriture du panier fidèle à la requête mesurée : `referrer`, point de livraison), I (poids variable, rupture, quantité réduite), N (liens ouverts dans le navigateur du téléphone), P (sites confiés au navigateur dans le diagnostic). O (gestes de vérification dans `recette.md`) revient à l'assistant, après DRV-621.



## M4 — Front

- [x] [DRV-401](tickets/DRV-401-liste-envoyee-ui.md) front · M · Liste envoyée dans l'interface · dépend de : 102, 104 · Issue : [repas-front#49](https://github.com/kcharignon/repas-front/issues/49)
- [x] [DRV-402](tickets/DRV-402-module-drive-bridge.md) front · M · Module `driveBridge` · dépend de : 302 · Issue : [repas-front#50](https://github.com/kcharignon/repas-front/issues/50)
- [x] [DRV-403](tickets/DRV-403-reglages-drive-ui.md) front · L · Réglages du drive · dépend de : 203, 204, 307, 402 · Issue : [repas-front#51](https://github.com/kcharignon/repas-front/issues/51)
- [x] [DRV-404](tickets/DRV-404-flux-d-envoi.md) front · L · Flux d'envoi · dépend de : 102, 206, 401, 402 · Issue : [repas-front#52](https://github.com/kcharignon/repas-front/issues/52)
- [x] [DRV-405](tickets/DRV-405-retour-et-recapitulatif.md) front · L · Retour et récapitulatif · dépend de : 204, 402 · Issue : [repas-front#53](https://github.com/kcharignon/repas-front/issues/53)
- [x] [DRV-406](tickets/DRV-406-tests-e2e-pixel.md) front · M · Tests e2e Pixel 7, accessibilité, visuel (couvert de fait par les spécifications `e2e/drive-*.spec.ts`, `e2e/shopping-list-sent.spec.ts` et `e2e/agent-ui.spec.ts` du front, voir le ticket ; deux écarts assumés : l'API y est simulée (`page.route`, exemples de contrat du back) et non le vrai back, et le bouton « J'en ai toujours » n'a pas de scénario e2e, seulement un test de composant) · dépend de : 403, 404, 405 · Issue : [repas-front#54](https://github.com/kcharignon/repas-front/issues/54)
- [x] [DRV-407](tickets/DRV-407-adr-front-et-claude-md.md) front · S · ADR front 0008 et `CLAUDE.md` · dépend de : 404 · Issue : [repas-front#55](https://github.com/kcharignon/repas-front/issues/55)
- [ ] ~~[DRV-408](tickets/DRV-408-page-d-aide.md)~~ (remplacé par DRV-316) front · S · Page d'aide · dépend de : 403 · Issue : [repas-front#56](https://github.com/kcharignon/repas-front/issues/56)
- [x] [DRV-409](tickets/DRV-409-admin-unites-dimension.md) front · S · Administration des unités : dimension · dépend de : 105 · Issue : [repas-front#57](https://github.com/kcharignon/repas-front/issues/57)
- [x] [DRV-410](tickets/DRV-410-priorites-produits-ui.md) front · M · Écran « Mes produits » : priorités et produits refusés · dépend de : 208, 403 · Issue : [repas-front#58](https://github.com/kcharignon/repas-front/issues/58)

## M5 — Intégration et livraison

- [ ] [DRV-501](tickets/DRV-501-integration-dev.md) back+front · M · Intégration en développement et checklist de recette · dépend de : M2, M3, M3b, M4 · Issue : [#83](https://github.com/kcharignon/repas/issues/83) · partie back faite (PR #156), partie front faite (repas-front#106), `recette.md` écrite ; reste le parcours réel dans l'émulateur (DRV-319 partie 2) et `BASKET_PATH`
- [x] [DRV-502](tickets/DRV-502-deploiement.md) back · S · Déploiement, interrupteur, retour arrière · dépend de : 501 · Issue : [#84](https://github.com/kcharignon/repas/issues/84)
- [x] [DRV-503](tickets/DRV-503-runbook-leclerc-change.md) back · S · Runbook « Leclerc a changé » · dépend de : 501 · Issue : [#85](https://github.com/kcharignon/repas/issues/85)
- [ ] [DRV-504](tickets/DRV-504-note-vie-privee.md) back+front · S · Note de vie privée (partie back faite : texte et registre, PR #152 ; partie front faite : repas-front#101, #105 et #106 ; reste la relecture des textes par l'utilisateur) · dépend de : 204 · Issue : [#86](https://github.com/kcharignon/repas/issues/86)
- [x] [DRV-505](tickets/DRV-505-recette-reelle.md) humain · S · Recette en conditions réelles et activation (procédure écrite : [`recette.md`](recette.md), à dérouler sur le téléphone, interrupteur allumé ; **signée par l'utilisateur le 2026-10-19, `DRIVE_ENABLED` reste allumé**, voir le compte rendu § 17 bis de `recette.md`) · dépend de : 502, 503 · Issue : [#87](https://github.com/kcharignon/repas/issues/87)

## M6 — Simplicité d'usage (audit du PO, 2026-10-19)

Références : [`principes-produit.md`](principes-produit.md), [`audit-simplicite.md`](audit-simplicite.md). Ordre conseillé : 621, puis 623 et 622, puis 624 ; 625 seulement après la vérification humaine qu'il décrit.

- [x] [DRV-621](tickets/DRV-621-voix-et-vocabulaire-uniques.md) front · M · Vouvoiement partout et glossaire appliqué au web, à l'agent, à l'application et à l'aide, avec un test qui garde le glossaire · dépend de : — · PR front #139
- [x] [DRV-622](tickets/DRV-622-ecrans-envoi-et-recapitulatif-simples.md) front · M · Écran de l'envoi et récapitulatif en une phrase, compteurs non nuls, erreurs en trois phrases, magasin manquant = bouton · dépend de : 621 · PR front #141 (le point « magasin manquant » : #140, DRV-623)
- [x] [DRV-623](tickets/DRV-623-un-seul-geste-pour-le-magasin.md) front · S · Un seul geste pour enregistrer son magasin · dépend de : 320, 621 · PR front #140
- [x] [DRV-624](tickets/DRV-624-choix-de-produit-sans-jargon.md) front · M · Choix du produit sans jargon, « passer les suivants » · dépend de : 306, 208, 621 · PR front #142
- [ ] [DRV-625](tickets/DRV-625-entrer-dans-le-magasin-sans-geste.md) front · M · Entrer dans le magasin, revenir et finaliser dans l'application sans geste inutile (après vérification humaine) · dépend de : 314, 405, 622
- [x] [DRV-626](tickets/DRV-626-balayage-apres-revue-de-parcours.md) front · M · Balayage après la revue de parcours : douze points lus sur les captures (compteurs à zéro, « Passer ces n articles », estimation sans prix, récapitulatif sans impasse…) · dépend de : 621 à 624 · PR front #147
- [x] [DRV-628](tickets/DRV-628-interface-sans-avertissement.md) front · M · Une interface sans avertissement ni explication superflue : les risques vivent dans une seule page de conditions d'utilisation · dépend de : 626 · PR front #148
- [ ] [DRV-627](tickets/DRV-627-tutoiement.md) front · M · Passer toute la voix de Repas au tutoiement (plus tard) · dépend de : 628

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
