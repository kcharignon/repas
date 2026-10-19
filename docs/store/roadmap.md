# Chantier : publication de l'application Android sur Google Play

Suivi vivant du chantier « publier Repas sur Google Play » (application Android `eu.kantin.repas.drive`, dépôt front `android/`). Cadrage du 2026-10-19 ; décision d'architecture : [ADR 0025](../adr/0025-publication-sur-google-play.md) (`Proposed` jusqu'à STORE-001). **À lire en premier par tout agent qui reprend ce chantier**, dans cet ordre :

1. Ce fichier.
2. [`publication-play-store.md`](publication-play-store.md) : l'analyse (modes, compte, signature, build, conformité, fiche, mises à jour, étapes de la console, risques, sources datées).
3. L'[ADR 0025](../adr/0025-publication-sur-google-play.md), puis le ticket choisi dans [`tickets/`](tickets/) (autonome, il fait foi).
4. [`docs/drive/consignes-agents.md`](../drive/consignes-agents.md) (livraison : une branche par ticket, commits courts en français sans `Co-Authored-By`, PR au gabarit Quoi / Vérifié / À relire sans pied d'attribution, fusion en squash seulement si la CI est verte) et les skills `repas-dev` (back), `repas-front-dev` (front), `repas-android-dev` (application), `repas-po` (tout écran, tout texte), `repas-ops` (secrets, déploiement, guidage pas à pas de l'humain).
5. Le `CLAUDE.md` du dépôt où l'on travaille.

## Le besoin

Les proches installent et mettent à jour « Repas » depuis Google Play, sans « sources inconnues », sans avertissement Play Protect et sans télécharger un `.apk` ; plus tard, n'importe qui en France peut l'installer. Le critère de réussite est celui du produit : **un proche installe l'application seul, depuis un lien, en trois touches** ([`principes-produit.md`](../drive/principes-produit.md)).

## Règles du chantier

- **Aucun secret dans les dépôts publics ni dans le chat** : clés de signature et d'envoi, mots de passe, compte de service, identifiants du compte de relecture, adresses de la famille (testeurs). Le propriétaire les saisit lui-même (terminal, console, GitHub). **Publiques** : les empreintes SHA-1 et SHA-256 des certificats.
- **Sauvegarder la clé de publication actuelle avant de l'exporter** (STORE-004) ; ne jamais en changer sans ticket.
- **La clé de signature se choisit avant toute publication en test ouvert ou en production** (STORE-005) : après, elle est figée.
- **Les étapes 11 à 13 d'AUTH-002 (client OAuth Android) attendent STORE-005.**
- Un agent ne crée **aucune** étiquette (`vX.Y.Z`, `android-vX.Y.Z`), n'envoie rien dans la console, ne crée aucun compte de service, ne touche ni aux secrets GitHub ni au serveur. L'humain approuve.
- Ordre quand le protocole du pont change : site d'abord, application ensuite (`PUBLICATION.md` étape 5). Un numéro d'étiquette n'est jamais réutilisé ; Google Play refuse un code de version déjà utilisé.
- Tout texte visible suit `repas-po` (tutoiement, phrases courtes, glossaire, erreurs en trois phrases).
- Changer une convention = mettre à jour l'ADR 0025, `CLAUDE.md`, le skill concerné et cette roadmap dans le même commit.

## M0 — Décisions, testeurs, compte

- [ ] [STORE-001](tickets/STORE-001-decisions-du-proprietaire.md) assistant + humain · S · Décisions D1 à D5, ADR 0025 `Accepted` · dépend de : —
- [ ] [STORE-002](tickets/STORE-002-recruter-les-testeurs.md) humain · S · Recruter au moins 15 testeurs (**à lancer tout de suite**) · dépend de : —
- [ ] [STORE-003](tickets/STORE-003-compte-developpeur-google-play.md) humain · M · Compte développeur Google Play (**à lancer tout de suite**, le plus long à attendre) · dépend de : 001 (D1, D2)

## M1 — Clé de signature et construction

- [ ] [STORE-004](tickets/STORE-004-sauvegarde-cle-et-cle-d-envoi.md) humain · S · Sauvegarde de la clé actuelle, création de la clé d'envoi · dépend de : 001 (D3)
- [ ] [STORE-005](tickets/STORE-005-application-et-cle-de-signature.md) humain · M · Application dans la console et clé de signature importée (PEPK) · dépend de : 003, 004 · **bloque AUTH-002 (étapes 11 à 13)**
- [ ] [STORE-006](tickets/STORE-006-aab-dans-le-workflow.md) front (`android/`, workflow) · M · Android App Bundle signé de la clé d'envoi, contrôlé, dans le workflow · dépend de : — (secrets : 004)
- [ ] [STORE-007](tickets/STORE-007-empreintes-publiques-et-documentation.md) front · S · Empreintes publiques (`assetlinks.json`, `release-cert`) et documentation de signature · dépend de : 005 · **peut bloquer l'App Link et AUTH-002 si l'empreinte diffère**

## M2 — Conformité aux règles de Google Play

- [ ] [STORE-008](tickets/STORE-008-supprimer-son-compte-back.md) back · M · Supprimer son propre compte (`POST /api/v1/users/me/deletion`, ADR 0021 amendé) · dépend de : 001
- [ ] [STORE-009](tickets/STORE-009-supprimer-son-compte-front.md) front · M · « Supprimer mon compte » dans « Moi » et page publique `/suppression-de-compte` · dépend de : 008, 001 (D2)
- [ ] [STORE-010](tickets/STORE-010-politique-de-confidentialite-publique.md) front · S · Page publique de confidentialité `/confidentialite`, lien dans l'application · dépend de : 001 (D2) ; à coordonner avec AUTH-010
- [ ] [STORE-011](tickets/STORE-011-signaler-une-recette-back.md) back · M · Signaler une recette d'un autre (conditionnel à D5 = B) · dépend de : 001
- [ ] [STORE-012](tickets/STORE-012-signaler-une-recette-front.md) front · S · Bouton « Signaler » et mention des conditions à l'inscription · dépend de : 011, AUTH-010
- [ ] [STORE-013](tickets/STORE-013-compte-de-relecture-google.md) humain · S · Compte de relecture pour l'équipe de Google · dépend de : 009, 010

## M3 — Fiche du store

- [ ] [STORE-014](tickets/STORE-014-textes-de-la-fiche.md) front (`android/store/`) · S · Textes de la fiche versionnés, tests de longueur, de voix et de marque · dépend de : 001 (D4)
- [ ] [STORE-015](tickets/STORE-015-visuels-de-la-fiche.md) front (`android/`) · M · Icône 512, image de présentation, captures d'écran (données fictives) · dépend de : 014

## M4 — Essais et publication

- [ ] [STORE-016](tickets/STORE-016-test-interne.md) humain · M · Test interne : premier bundle, installation par-dessus l'APK installé à la main · dépend de : 005, 006 (007 si l'empreinte diffère) · **joue la partie C d'AUTH-013 sur l'application Play**
- [ ] [STORE-017](tickets/STORE-017-declarations-et-fiche-dans-la-console.md) humain · M · Déclarations « Contenu de l'application » et fiche dans la console · dépend de : 009, 010, 013, 014, 015 (011 et 012 si D5 = B)
- [ ] [STORE-018](tickets/STORE-018-test-ferme-et-demande-de-production.md) humain · M · Test fermé (12 testeurs, 14 jours) et demande d'accès à la production · dépend de : 002, 016, 017
- [ ] [STORE-019](tickets/STORE-019-publication-en-production.md) humain · M · Publication en production (France) et signature · dépend de : 018

## M5 — Transition et suites

- [ ] [STORE-020](tickets/STORE-020-circuit-de-mise-a-jour.md) front · S · Circuit de mise à jour : aide, lien, APK GitHub, `version.json` · dépend de : 016 (lien) ; sa fin dépend de 019
- [ ] [STORE-021](tickets/STORE-021-envoi-automatique-piste-interne.md) front · S · (facultatif) Envoi automatique vers la piste de test interne · dépend de : 016 et trois envois manuels réussis
- [ ] [STORE-022](tickets/STORE-022-variante-sans-drive.md) front · M · (conditionnel) Variante du store sans l'écran du drive, **seulement si Google refuse** · dépend de : refus constaté

## Chemin critique et parallélisme

- **Critique** : 003 (compte) → 005 (clé) → 016 (test interne : jalon « famille », la famille installe depuis Google Play) → 017 → 018 (**14 jours**) → demande de production → 019 (jalon « public »). Environ 5 à 6 semaines ; la famille est servie en 1 à 2 semaines.
- **À lancer le jour même, sans code** : 001 (décisions), 002 (testeurs), 003 (compte), 004 (clés).
- **En parallèle, par des agents, dès 001** : 006 (AAB), 008 → 009, 010, 011 → 012 (si D5 = B), 014 → 015.
- **017 attend toute la conformité** (009, 010, 013) : c'est le seul endroit où le code retarde le calendrier.
- AUTH-002 (étapes 1 à 10), AUTH-003 à AUTH-012 et AUTH-015 ne dépendent d'aucun ticket STORE.

## Bloquants pour la connexion Google (chantier `docs/auth/`)

| Ticket STORE | Ce qu'il bloque | Pourquoi |
|---|---|---|
| STORE-005 | AUTH-002 étapes 11 à 13 (client OAuth Android : paquet + SHA-1) | l'empreinte à enregistrer est celle du certificat qui signe l'application installée, donc celle de la clé de Google Play |
| STORE-007 | `assetlinks.json` et le contrôle de l'APK du workflow (si l'empreinte affichée diffère de l'actuelle) | App Links et client OAuth utilisent la même empreinte |
| STORE-016 | AUTH-013 partie C (recette de la connexion Google dans l'application) | on la rejoue sur l'application installée depuis Google Play, celle que tout le monde aura |

## Ce qui exige le téléphone, le compte Play ou la console

- **Aucun test automatique ne les exige** : 006 à 012, 014, 015 et 020 à 022 se font et se testent sans téléphone ni compte Play (clés jetables, émulateur, faux Repas).
- **Téléphone Android réel** : 003 (vérification de l'appareil), 016, 018 (testeurs), 019.
- **Compte Play / console** : 003, 005, 016, 017, 018, 019 (et la preuve de 021).
- **Terminal de l'humain** : 004 (clés), 005 (PEPK).

## Entretien annuel (hors tickets)

Chaque été : relever le `targetSdk` demandé (la page de Google l'annonce vers juin), vérifier qu'aucun code de version n'est réutilisé, relire les mises à jour de politique de Google Play (`Policy announcement`), rejouer la page « sécurité des données » si les données changent, tester la suppression de compte. Chaque année : tester la restauration de la clé de publication et de la clé d'envoi.

## Definition of Ready

Les dépendances du ticket sont fusionnées (ou faites, pour un ticket humain), les décisions D1 à D5 qu'il cite sont tranchées, il tient dans une PR relisible (ou une session de guidage).

## Definition of Done (communes)

- **Back** : celle du skill `repas-dev` § 4 (`make test`, `make quality`, `make openapi`, budget SQL de l'écriture, purge du compte, ADR et `CLAUDE.md` mis à jour dans le même commit).
- **Front** : `make check` vert, couverture non abaissée, textes dans `fr.ts` (tutoiement, glossaire), **captures lues** (360 px, clair et sombre), `make android-test` et `make android-release-selftest` si `android/` ou le workflow changent.
- **Humain** : une étape à la fois, résultat attendu vu à l'écran, aucune valeur secrète dans le chat ; l'assistant note la date de fin (sans valeur secrète) dans cette roadmap.
- **Tous** : commits courts en français sans `Co-Authored-By`, PR au gabarit sans pied d'attribution, jamais de push sur `main` ni de force-push, cette roadmap cochée (le back coche ; pour un ticket du front, l'assistant le fait après la fusion), aucun identifiant, secret, nom de magasin ni donnée personnelle dans le dépôt.
