# Roadmap : envoi d'une liste au drive

Suivi vivant. Cocher une case quand le ticket est fusionné (et noter la PR). Le numéro d'issue GitHub est ajouté dans la colonne « Issue » quand les issues sont créées. Chaque ticket est un fichier autonome dans [`tickets/`](tickets/). Décisions : [ADR 0022](../adr/0022-remplissage-du-panier-drive.md), [ADR 0023](../adr/0023-liste-envoyee-au-drive.md). Architecture : [`architecture.md`](architecture.md).

**Dépôts** : `back` = `kcharignon/repas` (ce dépôt) ; `front` = `kcharignon/repas-front` (dossier `drive-agent/` pour l'agent) ; `humain` = tâche pour l'utilisateur ; `assistant` = rédaction de documentation.

**Règle du chantier** : G1 (DRV-005) est décidée (GO, 2026-10-17) : les tickets M1 à M5 peuvent démarrer selon leurs dépendances. Le spike `android-lab`, le banc `lab` et le contenu de `drive-agent/lab/` ont été retirés du dépôt front (PR front #134, 2026-10-19), après l'essai réel ; la pré-version `android-dev-*` (workflow `android.yml`) reste, le runbook s'en sert pour le « Test automatique ».

**Épiques GitHub** (dépôt back) : M1 à M5 fermées le 2026-10-24 ; [M0 #88](https://github.com/kcharignon/repas/issues/88) · [M1 #89](https://github.com/kcharignon/repas/issues/89) · [M2 #90](https://github.com/kcharignon/repas/issues/90) · [M3 #91](https://github.com/kcharignon/repas/issues/91) · [M4 #92](https://github.com/kcharignon/repas/issues/92) · [M5 #93](https://github.com/kcharignon/repas/issues/93). Une issue par ticket, dans le dépôt indiqué (labels `drive`, `spike`, `agent`, `humain`, `epic`).

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

- [x] [DRV-501](tickets/DRV-501-integration-dev.md) back+front · M · Intégration en développement et checklist de recette · dépend de : M2, M3, M3b, M4 · Issue : [#83](https://github.com/kcharignon/repas/issues/83) · fait (2026-10-24) : partie back (PR #156), partie front (repas-front#106), `recette.md` déroulée (DRV-505 signée le 2026-10-19), parcours dans l'émulateur fait (DRV-319), `BASKET_PATH` n'existe plus dans le front
- [x] [DRV-502](tickets/DRV-502-deploiement.md) back · S · Déploiement, interrupteur, retour arrière · dépend de : 501 · Issue : [#84](https://github.com/kcharignon/repas/issues/84)
- [x] [DRV-503](tickets/DRV-503-runbook-leclerc-change.md) back · S · Runbook « Leclerc a changé » · dépend de : 501 · Issue : [#85](https://github.com/kcharignon/repas/issues/85)
- [x] [DRV-504](tickets/DRV-504-note-vie-privee.md) back+front · S · Note de vie privée (partie back faite : texte et registre, PR #152 ; partie front faite : repas-front#101, #105 et #106 ; textes relus par l'utilisateur le 2026-10-24) · dépend de : 204 · Issue : [#86](https://github.com/kcharignon/repas/issues/86)
- [x] [DRV-505](tickets/DRV-505-recette-reelle.md) humain · S · Recette en conditions réelles et activation (procédure écrite : [`recette.md`](recette.md), à dérouler sur le téléphone, interrupteur allumé ; **signée par l'utilisateur le 2026-10-19, `DRIVE_ENABLED` reste allumé**, voir le compte rendu § 17 bis de `recette.md`) · dépend de : 502, 503 · Issue : [#87](https://github.com/kcharignon/repas/issues/87)

## M6 — Simplicité d'usage (audit du PO, 2026-10-19)

Références : [`principes-produit.md`](principes-produit.md), [`audit-simplicite.md`](audit-simplicite.md). Ordre conseillé : 621, puis 623 et 622, puis 624 ; 625 seulement après la vérification humaine qu'il décrit.

- [x] [DRV-621](tickets/DRV-621-voix-et-vocabulaire-uniques.md) front · M · Voix unique (le vouvoiement, remplacé ensuite par le tutoiement : DRV-627) et glossaire appliqué au web, à l'agent, à l'application et à l'aide, avec un test qui garde le glossaire · dépend de : — · PR front #139
- [x] [DRV-622](tickets/DRV-622-ecrans-envoi-et-recapitulatif-simples.md) front · M · Écran de l'envoi et récapitulatif en une phrase, compteurs non nuls, erreurs en trois phrases, magasin manquant = bouton · dépend de : 621 · PR front #141 (le point « magasin manquant » : #140, DRV-623)
- [x] [DRV-623](tickets/DRV-623-un-seul-geste-pour-le-magasin.md) front · S · Un seul geste pour enregistrer son magasin · dépend de : 320, 621 · PR front #140
- [x] [DRV-624](tickets/DRV-624-choix-de-produit-sans-jargon.md) front · M · Choix du produit sans jargon, « passer les suivants » · dépend de : 306, 208, 621 · PR front #142
- [x] [DRV-625](tickets/DRV-625-entrer-dans-le-magasin-sans-geste.md) front · M · Entrer dans le magasin, revenir et finaliser dans l'application sans geste inutile (après vérification humaine) · dépend de : 314, 405, 622 · PR front #149
- [x] [DRV-626](tickets/DRV-626-balayage-apres-revue-de-parcours.md) front · M · Balayage après la revue de parcours : douze points lus sur les captures (compteurs à zéro, « Passer ces n articles », estimation sans prix, récapitulatif sans impasse…) · dépend de : 621 à 624 · PR front #147
- [x] [DRV-628](tickets/DRV-628-interface-sans-avertissement.md) front · M · Une interface sans avertissement ni explication superflue : les risques vivent dans une seule page de conditions d'utilisation · dépend de : 626 · PR front #148
- [x] [DRV-627](tickets/DRV-627-tutoiement.md) front · M · Passer toute la voix de Repas au tutoiement (web, agent 0.23.0, application, aide, conditions d'utilisation) · dépend de : 628 · PR front #150
- [x] [DRV-629](tickets/DRV-629-finaliser-et-entrer-par-les-boutons.md) front · M · « Finaliser » vers la page du panier (`detail-panier.aspx`, ville dans le chemin) et entrée par le **bouton** « Commencer mes courses » (constats réels du 2026-10-19) · dépend de : 625 · PR front #151
- [x] [DRV-631](tickets/DRV-631-finaliser-comprendre-le-repli.md) front · S · « Finaliser sur Leclerc » : voir pourquoi il retombe sur l'accueil (raison du repli dans le diagnostic), puis corriger · dépend de : 629 · PR front #157 (fusionnée)
- [x] [DRV-632](tickets/DRV-632-fiches-produit-sans-ligne-a-zero.md) front · S · Fiches produit de l'agent : pas de « En promotion : 0,00 € », pluriels justes · dépend de : — · absorbé par DRV-644 (PR front #163, fusionnée)
- [x] [DRV-633](tickets/DRV-633-photos-des-produits-dans-le-choix.md) front · M · Photos des produits dans le choix de l'agent (demande de l'utilisateur : mémoire visuelle des paquets) ; lot 2 (photos dans Repas) en DRV-634 · dépend de : — · lot 1 : PR front #156 (fusionnée)
- [x] [DRV-637](tickets/DRV-637-version-de-l-application-connue-du-serveur.md) back + front · M · Le serveur connaît la version de l'application de chacun (`user_app_version`, `appUpdate` de `GET /api/v1/me`, « à mettre à jour » dans l'administration) · dépend de : — · **partie back faite** (PR back #224) ; **partie front faite** (PR front #173, site v0.5.12, 2026-10-20 : envoi de la version, bandeau dans « Moi », site qui s'adapte à l'application, phrase des conditions d'utilisation)
- [x] [DRV-638](tickets/DRV-638-page-de-mise-a-jour-sobre.md) front · S · Page de mise à jour sobre (version + un bouton vers la release) (demande de l'utilisateur) · dépend de : — · PR front #159 (fusionnée)
- [x] [DRV-639](tickets/DRV-639-mise-a-jour-dans-l-application.md) front/android · L · L'application télécharge, vérifie, installe et supprime l'APK (variante hors Play, effort xhigh) · dépend de : DRV-638, AUTH-015 · **fait** (PR front #191, sites v0.5.23)
- [x] [DRV-640](tickets/DRV-640-version-minimale-pour-le-drive.md) front · S · Une application trop ancienne ne lance pas le drive (message court, mise à jour, repli à la main) · dépend de : — · PR front #160 (fusionnée)
- [x] [DRV-641](tickets/DRV-641-pas-de-verrou-sans-application-a-jour.md) front · S · Ne jamais verrouiller la liste si l'envoi ne peut pas se faire (fait, PR front #161, fusionnée)
- [ ] [DRV-642](tickets/DRV-642-chercher-dans-le-bon-rayon.md) back/agent · M-L · Chercher dans le bon rayon (produits frais) : classer les candidats par rayon appris · lot 1a (back) fait, PR back #217 (fusionnée) ; lot 1b (agent 0.31.0, protocole, front) fait, PR front #166 (fusionnée) ; **lot 2 « filtre du site » réalisé par DRV-647** ; cause racine des produits frais introuvables corrigée (PR front #178, agent 0.38.0) ; le rayon 284311 est « Bébé », les fruits et légumes sont 284323 ; graine de départ non faite
- [x] [DRV-643](tickets/DRV-643-tous-les-resultats-en-defilant.md) agent · M · Tous les résultats de la recherche, affichés en défilant (demande de l'utilisateur) · dépend de : DRV-633 · PR front #163 (fusionnée)
- [x] [DRV-644](tickets/DRV-644-enregistrer-le-choix-refuse-et-expliquer.md) agent · S · Un produit choisi mais refusé par le panier est enregistré, avec la raison (absorbe DRV-632) · dépend de : DRV-635 · PR front #163 (fusionnée)
- [x] [DRV-645](tickets/DRV-645-panier-pret-un-message.md) front · S · Panier prêt : un message, pas la page ordinateur (fait, PR front #162, fusionnée)
- [x] [DRV-646](tickets/DRV-646-diagnostic-des-rayons.md) agent · S · Diagnostic des rayons et familles vus par la recherche pour quelques mots frais (prépare DRV-642) · dépend de : — · PR front #164 puis #165 (fusionnées)
- [x] [DRV-647](tickets/DRV-647-filtrer-le-choix-par-rayon-du-drive.md) agent · M · Filtrer le choix du produit par rayon du drive, sans lien avec les rayons de Repas (idée de l'utilisateur du 2026-10-20) : étape 0 mesure des libellés de rayons (agent 0.32.0), étape 1 rangée de rayons dans le choix · dépend de : 642 lot 1, 646 · fait, recette en cours (PR front #169, #172, #174 à #178)
- [x] [DRV-648](tickets/DRV-648-envoi-sans-clic-inutile.md) front/agent · M · Envoyer au drive sans clic inutile : une intention = un clic (ouverture du drive automatique, plus de « Commencer » ni de « Commencer mes courses » à toucher) · demande de l’utilisateur du 2026-10-20- [x] [DRV-649](tickets/DRV-649-bouton-ajouter-a-la-liste-compact.md) front · S · Bouton « Ajouter à la liste » compact (rond sur les cartes, pastille courte sur la fiche) · demande de l’utilisateur du 2026-10-20 · **fait** (PR front #179)
- [x] [DRV-649](tickets/DRV-649-bouton-ajouter-a-la-liste-compact.md) front · S · Bouton « Ajouter à la liste » compact (rond sur les cartes, pastille courte sur la fiche) · demande de l’utilisateur du 2026-10-20 · **fait** (PR front #179)
- [x] [DRV-650](tickets/DRV-650-memoriser-la-recherche-du-drive.md) back/agent/front · M · Mémoriser la recherche du drive qui a fait trouver le produit choisi (propre à l'enseigne, sous la clé du choix), pour chercher un remplaçant avec ce texte quand le produit n'est plus disponible (demande de l'utilisateur du 2026-10-20) · dépend de : 642, 635 · **lot 1 (back) fait, PR back #228** ; lot 2 (agent + site) fait, PR front #181 (v0.5.15)
- [x] [DRV-652](tickets/DRV-652-memoriser-la-contenance-en-unite-de-la-liste.md) back/agent/front · M · Mémoriser la contenance d'un produit dans l'unité de la liste (« 20 tranches » pour « 15 tranches de cheddar ») : une réponse privée, propre au couple (choix, produit), pour calculer le nombre de produits au lieu de le redemander à chaque envoi (demande de l'utilisateur du 2026-10-20) · dépend de : 205, 650 · **lot 1 (back) fait, PR back #229** ; lot 2 (agent + site) fait, PR front #185 et #186 (v0.5.19, v0.5.20) ; texte « Combien d'unités… » et fiche produit
- [x] [DRV-653](tickets/DRV-653-memoriser-la-recherche-d-un-choix-pas-mon-habituel.md) back/agent/front · S · Mémoriser la recherche aussi quand le produit est pris « Pas mon habituel » (`ONCE`) : nouvelle intention `NOTE_SEARCH` de `PUT /drive/choices` qui note la recherche d'un article sans toucher à ses produits (demande de l'utilisateur du 2026-10-20) · dépend de : 650 · **lot 1 (back) fait, PR back #230** ; lot 2 (agent + site) fait, PR front #183 ; recherche retenue `NOTE_SEARCH`, back #230 (v0.6.8)
- [ ] [DRV-654](tickets/DRV-654-aligner-les-scripts-d-emulateur-sur-l-envoi-sans-clic.md) front · S · Aligner `android/emulator/smoke.sh` et `e2e.sh` sur l'envoi sans clic (DRV-648/DRV-625) · dépend de : 648 · à faire, non urgent
- [ ] [DRV-655](tickets/DRV-655-propositions-restantes-de-l-audit-des-clics.md) front/agent · S-M · Propositions restantes de l'audit des clics (magasin au premier envoi, reprise directe, fin après « Arrêter » ; piste 6 à mesurer d'abord) · dépend de : 648 · à faire, non urgent
- [x] [DRV-630](tickets/DRV-630-emails-du-back-au-tutoiement.md) back · S · E-mail de vérification d'adresse et message de connexion du back au tutoiement (restes du vouvoiement après le front) · dépend de : 627 · PR back #200

## M7 — Plusieurs enseignes (cadrage du 2026-10-19)

**En pause depuis le 2026-10-20 sur ordre de l’utilisateur** : aucun ticket de ce jalon ne se lance avant qu’il le dise. En attendant, tout nouveau code ou texte reste indépendant de l’implémentation de Leclerc (règle « Ne pas durcir Leclerc »).

Références : [`multi-enseignes.md`](multi-enseignes.md) (audit, conception, faits sur Auchan), [ADR 0027](../adr/0027-plusieurs-enseignes-de-drive.md) (`Proposed`). **Chaque ticket du découplage ne change aucun comportement visible tant qu'il n'y a qu'une enseigne.** Les spikes Auchan sont des tâches `humain` sur téléphone (jamais depuis un ordinateur). Ordre conseillé : 701, 704 et 708 en parallèle, puis 702, 703, 705, 706, puis 707 et 709, puis 710 ; 711 en continu. Les tickets après 717 (recherche, panier, entrée, recette Auchan) seront écrits par DRV-715 d'après les rapports des spikes. Effort recommandé entre parenthèses.

- [ ] [DRV-701](tickets/DRV-701-registre-d-enseignes-du-back.md) back · M · Registre d'enseignes du back, enseigne en paramètre des lectures, contrat des enseignes (high) · dépend de : —
- [ ] [DRV-702](tickets/DRV-702-interrupteur-par-enseigne.md) back · S · Interrupteur par enseigne `DRIVE_RETAILERS` (high) · dépend de : 701
- [ ] [DRV-703](tickets/DRV-703-drive-habituel-et-derniere-enseigne.md) back · M · Drive habituel, dernière enseigne utilisée, retrait d'un drive, sélection `RetailerSelection` (xhigh) · dépend de : 701, 702
- [ ] [DRV-704](tickets/DRV-704-registre-d-enseignes-de-l-agent.md) front (agent) · M · Registre d'enseignes de l'agent, types neutres, protocole par enseigne (high) · dépend de : 701
- [ ] [DRV-705](tickets/DRV-705-textes-de-l-agent-avec-le-nom-de-l-enseigne.md) front (agent) · S · Textes de l'agent : le nom de l'enseigne en paramètre (medium) · dépend de : 704
- [ ] [DRV-706](tickets/DRV-706-hotes-autorises-par-enseigne-dans-le-coeur-kotlin.md) front (`android/core`) · M · Hôtes autorisés, origines du pont et mémoires par enseigne (xhigh) · dépend de : 704
- [ ] [DRV-707](tickets/DRV-707-ecran-du-drive-par-enseigne.md) front (`android/app`) · M · Écran du drive, injection, profil, menu et `app.info` par enseigne (high) · dépend de : 706
- [ ] [DRV-708](tickets/DRV-708-le-nom-de-l-enseigne-en-parametre-sur-le-web.md) front (web) · M · Le web : nom en paramètre, plus de littéral `leclerc` (medium) · dépend de : 701
- [ ] [DRV-709](tickets/DRV-709-mes-drives-dans-moi.md) front (web) · M · « Mes drives » dans « Moi » : liste, ajout, drive habituel (high) · dépend de : 703, 708
- [ ] [DRV-710](tickets/DRV-710-choisir-le-drive-a-l-envoi.md) front (web) · M · Choisir le drive à l'envoi (R1 à R5), passage et retour par enseigne (high) · dépend de : 703, 707, 708, 709
- [ ] [DRV-711](tickets/DRV-711-documentation-par-enseigne.md) back + front (docs) · S · Runbook, recette, skills et documentation « par enseigne » (medium) · dépend de : 701 à 710
- [ ] [DRV-712](tickets/DRV-712-spike-auchan-parcours-et-magasin.md) humain · M · Spike Auchan A1 : parcours, mode drive ou livraison, magasin, site mobile ou ordinateur · dépend de : —
- [ ] [DRV-713](tickets/DRV-713-spike-auchan-anti-robot-et-connexion.md) humain · M · Spike Auchan A2 : anti-robot et connexion · dépend de : 712
- [ ] [DRV-714](tickets/DRV-714-spike-auchan-recherche-et-panier.md) humain · M · Spike Auchan A3 : recherche, produits, panier · dépend de : 712
- [ ] [DRV-715](tickets/DRV-715-decision-auchan.md) assistant · S · Décision « Auchan : adaptateur complet, mode assisté ou abandon » et tickets suivants (medium) · dépend de : 712, 713, 714
- [ ] [DRV-716](tickets/DRV-716-declarer-auchan-dans-les-registres.md) back + front · M · Déclarer Auchan dans les quatre registres, éteint (high) · dépend de : 715, 701, 704, 706, 708
- [ ] [DRV-717](tickets/DRV-717-adaptateur-auchan-squelette.md) front (agent) · L · Adaptateur Auchan (squelette) et faux site (high) · dépend de : 715, 716

## Backlog (non détaillé, à écrire quand il devient utile)

DRV-601 suggestions automatiques · DRV-602 journal d'envoi · DRV-603 Firefox + gestionnaire de scripts ou extension · DRV-604 vignettes dans Repas (exception CSP ou relais d'images) · DRV-605 conversions unité↔poids par ingrédient · DRV-606 reprise des choix au changement de magasin (EAN, libellé) · DRV-607 deuxième enseigne (remplacé par le jalon M7 : DRV-701 à 717) · DRV-718 « j'en ai toujours » commun à toutes les enseignes · DRV-719 comparer deux enseignes (estimation du coût côte à côte) · DRV-608 enregistrement direct des choix par jeton à usage unique · DRV-609 partage de choix entre proches · DRV-610 rapport d'exécution anonyme.

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
