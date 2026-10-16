# Plan du front Vue.js (`repas-front`)

Ce document cadre le projet front qui remplacera Twig. Il est la source de vérité du chantier : on le met à jour à chaque jalon, comme la [roadmap de migration](migration-roadmap.md) (dont la phase 10, « extinction de Twig », dépend de lui).

**Décisions déjà prises** (par l'utilisateur) : le front est un **projet séparé** (autre dépôt GitHub), en **Vue.js** ; il doit couvrir **toute** l'application Twig ; l'interface est **redessinée** (le Twig est fonctionnel mais laid) ; la **bascule en production** se fait quand la parité est atteinte, avec l'aide de Claude ; **Twig est supprimé définitivement après la bascule**.

**Tout le reste de ce document est une proposition à valider** (section 11). Rien n'est engagé tant qu'un point n'est pas validé.

---

## 1. Objectif et critères de réussite

Le projet est réussi quand :

1. **Parité** : toute action possible aujourd'hui dans Twig l'est dans le front (inventaire en section 5, recette de parité en section 9).
2. **Design** : interface refaite, mobile d'abord (la liste de courses se consulte en magasin, sur téléphone), accessible (WCAG AA), avec thème sombre.
3. **Qualité** : mêmes exigences que le back (tests, analyse statique, CI, dépendances à jour, `composer audit` → `npm audit`), zéro régression de sécurité.
4. **Bascule réversible** : on peut revenir à Twig tant qu'il existe.
5. **Extinction de Twig** : suppression nette (code, dépendances, documentation).

## 2. Ce que le back offre déjà

- **API `/api/v1`** : 53 routes, JWT, erreurs `{error:{message}}`, OpenAPI sur `/api/doc.json` (ADR 0007, 0008). Toutes les actions des 14 controllers AJAX de Twig ont leur endpoint (parité prouvée : [roadmap phase 9](migration-roadmap.md)).
- **Sécurité** : plafonds de débit (connexion, inscription), jetons révoqués au changement de mot de passe, CORS limité à `/api` (`CORS_ALLOW_ORIGIN`).
- **Contrat stable** : DTO `final readonly`, clés JSON testées (ADR 0010), codes 404 / 409 / 422 (ADR 0007 §12).
- **Écritures sans HTML** : `204` ou `201` + `Location`, le front relit l'état par les `GET`.

## 3. Ce qui manque côté API (phase 0, dans ce dépôt)

À livrer **avant** les écrans concernés, en PR séparées. État au 2026-10-15 : `GET /ingredients` (PR #42), position des lignes et validation d'unité (PR #41), refresh token (ADR 0015, PR #47), contrat OpenAPI versionné (`docs/api/openapi.json`, PR #44) et contrôle de version (ADR 0016, PR #50) sont fusionnés ; restent la **pagination** (le front n'en a pas encore besoin : 73 recettes s'affichent sans gêne) et la **vérification d'e-mail** (décision produit).

| Manque | Pourquoi | Taille |
|---|---|---|
| `GET /api/v1/ingredients` (liste des ingrédients utilisables par l'utilisateur, recherche `q`) | Le sélecteur d'ingrédients d'une recette l'obtient aujourd'hui par un formulaire Twig (`ListAvailableIngredientsQuery` existe, aucun endpoint) | S |
| **Refresh token** et déconnexion (ADR dédié, ADR 0008 §5) | Un JWT de 1 h force à se reconnecter ; un SPA ne doit pas garder un jeton longue durée dans `localStorage` | M |
| Ordre des lignes d'une recette (colonne `position`) | Sinon l'ordre affiché change après une modification | S |
| Validation d'unité à l'enregistrement d'une recette | Une unité sans conversion est acceptée puis fait échouer la planification | S |
| Pagination / recherche sur les listes volumineuses (recettes d'un type, ingrédients d'un rayon) | 73 recettes pour un utilisateur ; les listes grossissent | S |
| Contrôle de version (ETag / `If-Match`) sur recette et liste | Deux onglets ou deux appareils modifient la même ressource : aujourd'hui « le dernier enregistrement gagne » (ADR 0013 règle 7) | M |
| Export du contrat OpenAPI versionné (`openapi.json` publié par la CI) | Le front génère son client typé depuis ce fichier | S |
| Vérification d'email à l'inscription (si souhaitée) | Inscription publique sans confirmation ; nécessite un service d'envoi (`symfony/mailer`) | M, **décision produit** |

### État au 2026-10-16 : terminé

Le front est en production sur `kantin.eu` (releases `v0.1.0-rc1` du back et du front, [ADR 0017](adr/0017-deploiement-et-releases.md)) ; la recette de parité a été jouée par l'utilisateur ; **Twig est supprimé du back** ([ADR 0018](adr/0018-extinction-de-twig.md)) et `app.kantin.eu` redirige vers `kantin.eu`. Le reste de ce document est l'historique du plan : les sections 5 (inventaire), 8 à 10 décrivent ce qui a été fait, la section 10 « Bascule et extinction » a été exécutée en un jour, sans délai de grâce, à la demande de l'utilisateur.

### État du front au 2026-10-15

Dépôt `kcharignon/repas-front`, PR #1 à #23 fusionnées : les phases 1 à 5 sont faites. Chaque route Twig a son équivalent (`PARITY.md` du front : route Twig, écran, tests unitaires et de bout en bout, état) ; les anciennes adresses Twig sont redirigées côté front ; l'application est installable, la liste en cours se lit hors-ligne (ADR front 0006) ; Lighthouse sur l'image de production : performance 94 à 100, accessibilité 100, bonnes pratiques 100, SEO 100. **Reste à faire avec l'utilisateur** : la phase 6 (recette de parité jouée à la main, une semaine de « dogfooding »), puis la bascule (phase 7) et l'extinction de Twig (phase 8), **seulement sur son accord explicite**.

## 4. Architecture proposée du front

**Dépôt** : `kcharignon/repas-front` (nom à confirmer), séparé de `repas`. Le seul couplage est le **contrat OpenAPI**.

**Stack**
- **Vue 3** (Composition API, `<script setup>`), **TypeScript strict**, **Vite**.
- **Vue Router** (mode `history`), **Pinia** pour le peu d'état global (session, préférences d'affichage).
- **TanStack Query (Vue Query)** pour les données serveur : cache, invalidation après écriture (c'est le « le client relit l'état » de l'ADR 0007 §6), états de chargement et d'erreur uniformes.
- **Client API typé généré** depuis `openapi.json` (`openapi-typescript` + `openapi-fetch`) : un changement de contrat casse la compilation du front, pas la production.
- **Formulaires** : composables maison (`useForm`) qui affichent les erreurs 422 du serveur champ par champ (la validation métier reste dans le back, ADR 0007 §4). Pas de bibliothèque de validation en double.
- **Style** : **Tailwind CSS** + composants **headless accessibles** (Reka UI, ex-Radix Vue), jetons de design maison (couleurs, espacements, typographie), icônes Lucide. Alternative écartée : un kit complet (Vuetify, PrimeVue) impose son esthétique, difficile à redessiner.
- **i18n** : français seul, mais textes dans `vue-i18n` dès le départ (aucune chaîne en dur dans les composants).
- **PWA** (phase 5) : installable, assets en cache, liste de courses active consultable hors-ligne.

**Structure** (par domaine, miroir du back)
```
src/
  api/            client généré, intercepteurs (jeton, 401, 429), erreurs
  app/            routeur, layout, session
  features/
    shopping-lists/  recipes/  ingredients/  departments/  user/  admin/
      pages/ components/ queries/ (une query/mutation par endpoint)
  design/         jetons, composants de base (Button, Field, Dialog, Toast…)
  e2e/            Playwright
```

**Tests**
- **Vitest + Vue Test Utils** pour composants et composables ; **MSW** pour simuler l'API (les corps de réponse viennent du contrat, pas écrits à la main).
- **Playwright** en bout en bout contre le **vrai back** (docker compose du dépôt `repas`, base de test) : un parcours par groupe de pages, en CI.
- **axe-core** dans les tests de composants et d'e2e (accessibilité), **Lighthouse CI** avec budgets (performance, accessibilité).
- Mêmes règles que le back : un test avant de merger, sortie sans bruit (pas de warning de console tolérés), couverture mesurée.

**Qualité et CI** : ESLint + Prettier, `vue-tsc`, `npm audit` en CI, **Dependabot hebdomadaire** (mêmes règles que l'ADR 0014 : mineures et correctifs regroupés, majeures à la main), contrainte de taille de bundle (`size-limit`).

## 5. Inventaire de parité

Source : les 47 routes Twig. « Gap » = endpoint à créer en phase 0.

| Groupe | Pages Twig | Écran Vue | Endpoints API | État |
|---|---|---|---|---|
| **Accès** | `/login`, `/registration`, `/` (accueil) | connexion, inscription, accueil (tableau de bord : liste active, raccourcis) | `POST /api/login_check`, `POST /users`, `GET /me`, `GET /shopping-lists/active/summary` | prêt (+ refresh : gap) |
| **Réglages** | `/user/{id}` | réglages (nombre de personnes, mot de passe) | `PUT /me/settings` | prêt |
| **Listes de courses** | `/shopping-list`, `/shopping-list/{id}`, `/shopping-list/active`, créer, renommer, activer, pause, terminer, supprimer, cocher/décocher, portions d'un repas | liste des listes, détail (deux colonnes : recettes planifiées / ingrédients ; une seule colonne et deux onglets sur mobile), actions | `GET/POST/PATCH/DELETE /shopping-lists…`, `POST …/{id}/activate|pause|complete`, `POST /shopping-list-rows/{id}/tick|uncheck`, `PUT /meals/{id}/serving`, `PUT /shopping-lists/active` | prêt |
| **Planning** | ajouter/retirer une recette ou un ingrédient de la liste active (depuis recettes et rayons) | boutons dans recettes et rayons, résumé de la liste active partout | `PUT/DELETE /shopping-lists/active/recipes/{id}`, `POST …/ingredients/{slug}/increase|decrease`, `GET …/active/summary` | prêt |
| **Recettes** | `/recipes` (types), `/recipe/type/{slug}`, `/recipes/other-people`, `/recipe/{id}`, créer, modifier, copier, supprimer | types, liste par type, recettes des autres, détail, éditeur (lignes ingrédient/quantité/unité ajoutables, unités compatibles chargées selon l'ingrédient) | `GET /recipe-types`, `GET /recipe-types/{slug}/recipes`, `GET /recipes/other-people`, `GET/POST/PUT/DELETE /recipes…`, `POST /recipes/{id}/copy`, `GET /ingredients/{slug}/units` | **gap** : liste d'ingrédients, position des lignes |
| **Ingrédients et rayons** | `/department`, `/department/{slug}`, `/ingredient` (créer), `/ingredient/{slug}` (modifier, supprimer), `/ingredients/{slug}/units` | rayons, ingrédients d'un rayon, éditeur d'ingrédient (le champ « coefficient » n'apparaît que si unité de recette et d'achat diffèrent) | `GET /departments`, `GET /departments/{slug}/ingredients`, `GET/POST/PUT/DELETE /ingredients…` | prêt |
| **Administration** | `/admin/unit`, `/admin/department`, `/admin/recipe-type`, `/admin/conversion`, `/admin/user` (liste, créer, modifier) | cinq écrans de gestion (réservés au rôle administrateur) | `GET/POST/PUT /units|departments|recipe-types|conversions`, `GET /users` | prêt |

**Comportements JavaScript à reproduire** (aujourd'hui jQuery) : fragments qui se rafraîchissent après une action (`ajax.js`), bascule mobile ingrédients/recettes de la liste (`repas_shopping_list.js`), lignes de recette ajoutables et supprimables (`form_recipe`), champ coefficient conditionnel (`_ingredient_form`). Aucun n'a de logique métier : tous deviennent des composants Vue.

**Pas repris** (disparaissent avec Twig) : `GET /shopping-list/active` qui crée la liste sur un GET (le front appelle `PUT /shopping-lists/active`), formulaires admin « Nouveau… » mal titrés, page de connexion à erreur dédoublée, `UrlType` des rayons.

**Ressources statiques** : 1,7 Mo d'images (`public/images/{department,ingredient,recipe}`) référencées par chemin relatif en base. Décision : le front les sert via le même domaine (`/images/*` reste servi par le back, ou copie dans le front : à trancher section 11).

## 6. Authentification et sécurité du front

- **Jeton d'accès (JWT, 1 h) en mémoire uniquement** (jamais `localStorage`, jamais lisible par un script tiers). Rechargement de page : le front redemande un jeton avec le refresh.
- **Refresh token** : cookie `HttpOnly; Secure; SameSite=Strict`, limité au chemin `/api/auth`, à **rotation** à chaque usage, révoqué au changement de mot de passe (comme les JWT, ADR 0008 §13) et à la déconnexion, stocké **haché** en base. Un refresh rejoué (jeton déjà tourné) révoque toute la famille.
- **Même site, même domaine** : le front et l'API partagent un domaine (le serveur web envoie `/api/*` au back, le reste au front). Pas de CORS en production, cookies `SameSite=Strict`, pas de problème de cookies tiers. `CORS_ALLOW_ORIGIN` ne sert qu'au développement (front en `localhost:5173`).
- **Politique de contenu stricte** (`Content-Security-Policy`, pas de script en ligne), `Referrer-Policy`, `Permissions-Policy` posés par le serveur web.
- **UX des erreurs de sécurité** : `401` → refresh silencieux puis rejeu, sinon retour à la connexion ; `429` → message avec le délai du `Retry-After` ; `403` → page dédiée ; `422` → erreurs sous les champs.
- Pas de dépendance non maintenue ; `npm audit` et Dependabot dès le premier commit.

## 7. Design

Le front n'est pas qu'un portage : l'interface est refaite. Démarche, du plus cheap au plus coûteux à défaire :

1. **Audit de l'existant** : captures de toutes les pages Twig (Chromium, déjà outillé), liste des frictions (trop de clics pour cocher en magasin, boutons minuscules, hiérarchie plate…).
2. **Principes** : mobile d'abord, lisible d'une main en magasin (cases et boutons ≥ 44 px, lignes cochées qui s'estompent mais restent lisibles), contraste WCAG AA, thème clair et sombre, mouvement discret (`prefers-reduced-motion`), vocabulaire du domaine (« rayon », « liste », « repas »).
3. **Jetons de design** (couleurs, typographie, rayons, ombres, espacements) et **bibliothèque de composants** (Button, Field, Select, Checkbox, Dialog, Toast, Card, Tabs, EmptyState…), documentée dans un catalogue (Storybook ou Histoire).
4. **Maquettes interactives des écrans clés**, produites par Claude en HTML/CSS pur **avant** le code Vue et **validées par l'utilisateur** : liste de courses (mobile et bureau), détail et éditeur de recette, rayons, tableau de bord. On ajuste là, pas dans Vue.
5. **Écrans restants** par déclinaison du système (administration d'abord utilitaire, sans maquette dédiée).
6. **Recette visuelle** à chaque PR du front : captures Chromium lues (comme aujourd'hui pour Twig), clair et sombre, mobile et bureau.

L'**identité** (nom affiché, logo `public/images/logo.png`, palette de départ) est à fournir ou à choisir ensemble (section 11).

## 8. Phases et jalons

Chaque phase est une série de PR (une par unité logique, description Quoi / Vérifié / À relire, jamais de merge par Claude sauf demande explicite). Un jalon n'est atteint que si les critères de sortie sont vérifiés.

| Phase | Contenu | Critère de sortie |
|---|---|---|
| **0 — Fondations back** (dépôt `repas`) | Section 3 : liste d'ingrédients, refresh token, position des lignes, validation d'unité, pagination, ETag, contrat OpenAPI publié ; ADR « front Vue » et ADR « authentification du SPA » | `make test` et `make quality` verts ; contrat publié par la CI |
| **1 — Socle front** (nouveau dépôt) | Création du dépôt (Claude, avec le jeton), outillage, CI, Dependabot, client généré, session (connexion, inscription, refresh, déconnexion), layout, jetons et composants de base, maquettes validées | Un utilisateur se connecte et voit un écran vide thémé ; e2e de connexion en CI |
| **2 — Parcours cœur** | Listes de courses (liste, détail, toutes les actions, mobile), recettes (types, liste, détail, éditeur, copie, suppression), planning depuis recettes | Faire ses courses de bout en bout dans le front, sur téléphone |
| **3 — Référentiel utilisateur** | Rayons et ingrédients (création, modification, suppression), recettes des autres, réglages | Parité des pages « utilisateur » |
| **4 — Administration** | Unités, rayons, types de recette, conversions, utilisateurs | Parité des pages « admin » |
| **5 — Finitions** | PWA et lecture hors-ligne de la liste active, audit d'accessibilité complet, budgets Lighthouse, polish du design, états vides et erreurs | Lighthouse ≥ 90 (performance, accessibilité), axe sans violation |
| **6 — Recette de parité** | Checklist de la section 5 jouée à la main puis en e2e, une semaine d'usage réel (« dogfooding »), correction | 100 % des lignes de l'inventaire cochées |
| **7 — Bascule en production** | Section 10 | Front en production, Twig inactif mais présent |
| **8 — Extinction de Twig** | Section 10, étape finale | Twig supprimé, documentation et ADR à jour |

Les phases 2 à 4 peuvent se recouvrir ; la phase 0 ne doit pas être sautée pour les écrans qui en dépendent.

## 9. Recette de parité

Un fichier `PARITY.md` dans le dépôt front reprend le tableau de la section 5, une ligne par action, avec : écran Vue, test e2e, capture claire/sombre/mobile, état. La bascule exige **toutes** les lignes cochées. On y ajoute l'inventaire **négatif** : ce que Twig faisait implicitement et que personne n'a listé (redirections après action, messages, droits d'accès : un utilisateur ordinaire n'accède à aucune page `/admin`, une ressource d'un autre renvoie 404, etc.), repris de la suite de tests des controllers Twig avant leur suppression.

## 10. Bascule en production et extinction de Twig

**Bascule** (réalisée avec Claude, un pas à la fois, chaque pas vérifié)

1. **Pré-requis** : parité 100 %, e2e verts sur le back de production-like, sauvegarde de la base, plan de retour écrit.
2. **Marche parallèle** : le front est déployé sur son chemin ou sous-domaine de test, branché sur la **vraie base**, Twig toujours servi à la racine. Les deux écrivent dans les mêmes tables (c'est le même back) : aucune migration de données.
3. **Configuration du serveur** (FrankenPHP/Caddy) : `/api/*` → Symfony ; `/images/*` → fichiers ; le reste → `index.html` du front (repli SPA) ; en-têtes de sécurité. Variables : domaine, `CORS_ALLOW_ORIGIN` retiré en production, `SYMFONY_TRUSTED_PROXIES` si proxy.
4. **Bascule** : on change l'aiguillage de la racine ; les anciennes URLs Twig (`/recipes`, `/shopping-list/active`…) redirigent vers leurs équivalents Vue (table de redirections générée depuis la section 5).
5. **Surveillance** : journaux d'erreurs du front et de l'API, taux de 4xx/5xx, quelques jours. **Retour arrière** : remettre l'aiguillage sur Twig (un changement de configuration, sans migration).
6. **Délai de grâce** (proposé : 2 semaines sans retour arrière).

**Extinction de Twig** (PR dédiées dans `repas`, après le délai de grâce)

1. Supprimer les 47 routes Twig : `*ViewController`, templates, `Http/Form`, `public/js`, `public/css`, `public/bundles` ; `ajax.js`, jQuery.
2. Retirer les dépendances devenues inutiles : `symfony/twig-bundle`, `symfony/form`, `symfony/asset`, `twig/intl-extra`, `symfony/web-profiler-bundle` si seul Twig l'utilisait (`composer remove`, `composer audit`).
3. **Sécurité** : le pare-feu `main` (session, `remember_me`, `AuthenticatorEmail`, CSRF) disparaît ; il ne reste que `login` et `api` (JWT). Les routes `/login`, `/logout`, `/registration` Twig partent. Mettre à jour l'ADR 0008 (session retirée), `access_control`, le test `EveryControllerHasARouteTest`.
4. **Tests et qualité** : retirer l'exclusion de couverture de `Http/Controller` et `Http/Form` (devenue sans objet), supprimer les tests de controllers Twig, `QueryBusWebTestCase` pour la session, scripts Chromium Twig ; règle Deptrac `no-repository-in-http` à garder.
5. **Documentation** : ADR 0009 « strangler » clos, ADR 0008 et 0007 mis à jour, `CLAUDE.md`, skill `repas-dev` (plus de recette « migrer un ViewController »), roadmap phase 10 cochée, nouvel ADR « Twig supprimé ».
6. Vérifier le budget SQL, la couverture (le seuil remonte), `make quality`, le smoke API avec JWT.

## 11. Décisions à valider et informations à fournir

**Décisions proposées (tu peux en changer)**

| # | Décision | Ma recommandation |
|---|---|---|
| 1 | Stack | Vue 3 + TypeScript + Vite + Pinia + TanStack Query + client OpenAPI généré |
| 2 | Style | Tailwind + composants headless (Reka UI), design maison ; pas de kit complet |
| 3 | Déploiement | Même domaine que l'API, serveur web unique (Caddy/FrankenPHP) qui sert le front statique |
| 4 | Jeton | Accès en mémoire + refresh en cookie `HttpOnly` à rotation (ADR dédié) |
| 5 | Images | Servies par le back sous `/images` (aucune copie à maintenir) |
| 6 | Catalogue de composants | Storybook (ou Histoire, plus léger) pour documenter le design |
| 7 | Vérification d'email | À décider : utile si l'inscription reste publique ; demande un fournisseur d'envoi |
| 8 | PWA hors-ligne | Lecture seule de la liste active (phase 5) ; l'édition hors-ligne est hors périmètre |

**À me fournir pour démarrer la phase 1**
- Un **jeton GitHub** pouvant agir sur les deux dépôts : fine-grained, propriétaire `kcharignon`, dépôts `repas` et le futur `repas-front`, permissions **Contents** (lecture/écriture), **Pull requests**, **Actions** (lecture), **Workflows**, **Administration** (pour créer le dépôt), **Secrets**/**Variables** si la CI en a besoin. Je ne l'écris dans aucun fichier ni journal.
- Le **nom** du dépôt et sa visibilité (privé ?).
- Le **domaine** de production et la manière dont le site est hébergé aujourd'hui (pour la section 10, étape 3).
- L'**identité visuelle** : logo existant conservé ? couleur d'accent ? ambiance (douce, culinaire, sobre) ? des applications dont tu aimes l'interface ?

## 12. Risques

| Risque | Mitigation |
|---|---|
| Oubli d'un comportement de Twig | Inventaire négatif de la section 9, tests de controllers Twig relus avant suppression, dogfooding |
| Dérive du contrat API | Client généré, e2e contre le vrai back en CI, `openapi.json` versionné |
| Refresh token mal conçu (faille) | ADR dédié, rotation, détection de rejeu, tests de sécurité, revue avant merge |
| Design jamais validé | Maquettes HTML validées avant tout composant Vue |
| Bascule ratée | Marche parallèle, retour arrière par configuration, délai de grâce, sauvegarde |
| Deux dépôts qui divergent | Même discipline des deux côtés (PR, CI, Dependabot, ADR) ; Claude garde les deux à jour |
| Dette si le projet traîne | Twig reste en maintenance minimale (correctifs de sécurité seulement) pendant les phases 1 à 6 |
