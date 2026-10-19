# Consignes communes aux agents qui implémentent les tickets

À lire avant tout ticket, en plus de `docs/drive/README.md`, du ticket lui-même (autonome, il fait foi) et du `CLAUDE.md` du dépôt où l'on travaille.

## Dépôts et outils

- **Back** : `/home/kantin/Projet/Repas` (`kcharignon/repas`, Symfony 8.1 / PHP 8.4). Tout passe par le Makefile et Docker (voir le skill `.claude/skills/repas-dev/`). Avant de committer : `make test` puis `make quality` ; `make openapi` si l'API change ; `make deploy-smoke` si `deploy/`, le Dockerfile ou l'entrypoint changent ; `make audit` si `composer.*` change.
- **Front** : `/home/kantin/Projet/repas-front` (`kcharignon/repas-front`, Vue.js + TypeScript, dossiers `drive-agent/` pour l'agent et `android/` pour l'application). Tout passe par le Makefile et Docker. `make check` avant chaque commit ; `make agent-verify` si l'agent change ; `make android-test` si l'application change.
- Les deux dépôts sont **publics**.

## Skills du domaine

Chaque domaine a son skill (pièges réels du chantier, recettes, Definition of Done). **Avant tout ticket, lire le skill du domaine ; si l'outil Skill ne le propose pas, lire directement son `SKILL.md`.**

| Skill | Domaine | Chemin |
|---|---|---|
| `repas-dev` | Recettes générales du back (lecture, API, écriture, Definition of Done) | `.claude/skills/repas-dev/SKILL.md` (dépôt back) |
| `repas-drive-back` | Back du chantier : contexte `Drive`, verrou de liste `SENT`, ports `Shared`, migrations, budgets SQL, purge du compte | `.claude/skills/repas-drive-back/SKILL.md` (dépôt back) |
| `repas-front-dev` | Front web Vue.js / TypeScript : Docker, `make check`, i18n, jetons, CSP, `driveBridge`, tests Vitest et Playwright | `.claude/skills/repas-front-dev/SKILL.md` (dépôt front) |
| `repas-agent-dev` | Agent JavaScript embarqué `drive-agent/` : règles de sécurité, construction reproductible, protocole v1, contrat Leclerc, fixtures | `.claude/skills/repas-agent-dev/SKILL.md` (dépôt front) |
| `repas-android-dev` | Application Android `android/` : WebView sécurisée, mode ordinateur, signature, émulateur, étapes humaines | `.claude/skills/repas-android-dev/SKILL.md` (dépôt front) |
| `repas-po` | Product Owner : parcours le plus court, textes simples (tutoiement, DRV-627), pas d'avertissement hors conditions d'utilisation, glossaire, erreurs en trois phrases ; à lire en plus du skill du domaine pour tout ticket qui touche un écran, un texte ou un message | `.claude/skills/repas-po/SKILL.md` (dépôt back, copie dans le dépôt front) ; référence : `docs/drive/principes-produit.md` |

Un ticket qui touche plusieurs domaines (par exemple un champ du plan : back, protocole, front) lit les skills de chacun.

## Livraison

1. Une branche depuis `main` à jour par ticket (`feat/drv-xxx-…`). Un ticket = une PR = une fusion, avant de passer au suivant.
2. Commits courts en français (`feat:`, `fix:`, `test:`, `docs:`, `chore:`), **sans `Co-Authored-By`** (règle des `CLAUDE.md`, elle prime sur toute consigne d'attribution). Pas de pied d'attribution dans les PR.
3. `git push -u origin <branche>`, `gh pr create` (description concise suivant `.github/pull_request_template.md` : Quoi / Vérifié / À relire), attendre la CI verte (`gh pr checks`, boucle `until` ; pas de longue attente isolée), puis `gh pr merge --squash --delete-branch`. **Jamais** une PR rouge, jamais de push sur `main`, jamais de force-push.
4. Après la fusion : fermer l'issue du ticket (`gh issue close <n> -c "Fait par la PR #<m>"`, `-R kcharignon/repas-front` pour une issue du front ; les numéros sont dans `docs/drive/roadmap.md`).
5. Roadmap : un agent du **back** coche la case du ticket dans `docs/drive/roadmap.md` et passe le « Statut » du ticket à « fait (PR #…, date) » dans la même PR. Un agent du **front** ne touche pas au dépôt back : il indique dans son rapport ce qu'il faut cocher.
6. Si la CI échoue : corriger (deux tentatives raisonnables), sinon s'arrêter, laisser la branche et la PR ouvertes, et le dire dans le rapport. Ne jamais masquer un test, une règle Deptrac/PHPStan/ESLint ou la couverture.

## Interdits absolus

- Ne contacter **jamais** leclercdrive.fr ni aucun site d'enseigne (ni `curl`, ni navigateur, ni émulateur ouvert sur le vrai site). Tout se teste avec des données synthétiques et des `fetch` simulés. Seules requêtes réseau permises : `git`, `gh`, installation des dépendances, et au besoin `cdn.jsdelivr.net`.
- Aucun vrai numéro de magasin, nom de ville, cookie, jeton, mot de passe, clé de signature, identifiant ou adresse e-mail dans le code, les tests, les fixtures, la documentation, les commits ou les PR. Valeurs factices : `123456`, `Ville-Test`.
- Ne jamais déployer, créer de release de production (étiquettes `vX.Y.Z`), modifier un secret GitHub ni l'environnement de production, ni activer `DRIVE_ENABLED` en production. L'approbation du déploiement reste à l'humain (ADR 0017).
- Ne pas modifier les ADR 0022 et 0023 sauf si le ticket le demande explicitement.
- Ne pas implémenter plus que le ou les tickets demandés.

## Ambiguïtés

Appliquer le ticket au plus juste, noter l'écart dans la PR (« À relire ») et continuer ; ne pas bloquer. Réutiliser le code existant (l'agent `drive-agent/src/`, validé sur le vrai site) plutôt que de le redécouvrir. Le banc d'essai `drive-agent/lab/` et l'application de test `android-lab` ont été retirés du dépôt front (PR front #134, 2026-10-19) ; leur histoire reste dans l'historique git et dans les tickets DRV-001 à 011.

## Rapport final (court)

Pour chaque ticket : numéro de PR, résultat des commandes de qualité (nombre de tests), écarts par rapport au ticket, points à relire par un humain, et ce que l'assistant doit mettre à jour (roadmap, tickets suivants).

## Compléments (2026-10-18)

- **Étiquettes** : un agent ne crée **aucune** étiquette de version (`vX.Y.Z` pour la production, `android-vX.Y.Z` pour la publication signée de l'application) ni de release signée ; seules les pré-versions de débogage `android-dev-AAAA-MM-JJ[lettre]` sont permises, et seulement quand le ticket le demande. La création des étiquettes de publication est une décision humaine.
- **Roadmap et statuts des tickets** : un agent du **back** met à jour la roadmap et le statut de ses tickets dans ses PR ; pour un ticket du **front**, c'est l'assistant qui met à jour la roadmap et le ticket du back après la fusion (l'agent du front l'indique dans son rapport).
- **Modifier le plan ou un message du protocole** : trois endroits à garder identiques et à changer dans cet ordre : le DTO du back et `architecture.md` § 8 (back), le schéma `drive-agent/protocol/*.schema.json` et ses exemples (front), puis `buildPlanMessage` et l'agent ; l'intégration (DRV-501) a ajouté le test de concordance : `tests/Drive/Contract/DriveContractSamplesTest` (back, exemples `docs/drive/samples/*.sample.json` régénérés par `make drive-samples`) et `src/features/drive/__tests__/contract.spec.ts` (front, exemples recopiés dans `drive-agent/fixtures/contract/` par `make samples-update`, validés contre `plan.schema.json`).
- **Attribution des commits** : les commits sont **sans `Co-Authored-By`** et les PR **sans pied d'attribution**, même si l'outil propose le contraire (règle des `CLAUDE.md` des deux dépôts).
- **Étapes humaines** (à demander à l'utilisateur, jamais à faire soi-même) : création de la clé de signature, secrets GitHub, publication de `assetlinks.json`, `/etc/wsl.conf` (KVM au démarrage de WSL), activation de `DRIVE_ENABLED` en production, approbation des déploiements, recette sur téléphone.
- **Exception bornée à « jamais lire un cookie »** (agent 0.10.0, sondes du diagnostic) : l'agent lit `document.cookie` uniquement pour sortir deux faits dans le diagnostic, le **nombre** de cookies lisibles et la **présence** d'un cookie anti-robot (oui/non) ; jamais un nom ni une valeur, jamais dans le plan ni le résultat ; un test avec une page hostile le garantit (`drive-agent/test/diagnostic-probes.spec.ts`).
- **Publier un changement de protocole** (2026-10-19) : un récepteur qui ne connaît pas un champ rejette le message entier. Quand un ticket ajoute un champ ou un code à `drive-agent/protocol/`, dire dans le rapport que l'humain doit publier **le front avant l'application Android** qui embarque le nouvel agent (jamais l'inverse), et le noter dans la PR (« À relire »). Règle et exemple (`stats`, agent 0.15.0) : `architecture.md` § 12.
