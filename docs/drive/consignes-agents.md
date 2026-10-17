# Consignes communes aux agents qui implémentent les tickets

À lire avant tout ticket, en plus de `docs/drive/README.md`, du ticket lui-même (autonome, il fait foi) et du `CLAUDE.md` du dépôt où l'on travaille.

## Dépôts et outils

- **Back** : `/home/kantin/Projet/Repas` (`kcharignon/repas`, Symfony 8.1 / PHP 8.4). Tout passe par le Makefile et Docker (voir le skill `.claude/skills/repas-dev/`). Avant de committer : `make test` puis `make quality` ; `make openapi` si l'API change ; `make deploy-smoke` si `deploy/`, le Dockerfile ou l'entrypoint changent ; `make audit` si `composer.*` change.
- **Front** : `/home/kantin/Projet/repas-front` (`kcharignon/repas-front`, Vue.js + TypeScript, dossiers `drive-agent/` pour l'agent et `android/` pour l'application). Tout passe par le Makefile et Docker. `make check` avant chaque commit ; `make agent-verify` si l'agent change ; `make android-test` si l'application change.
- Les deux dépôts sont **publics**.

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

Appliquer le ticket au plus juste, noter l'écart dans la PR (« À relire ») et continuer ; ne pas bloquer. Réutiliser le code existant (notamment le banc d'essai `drive-agent/lab/src/`, validé sur le vrai site) plutôt que de le redécouvrir.

## Rapport final (court)

Pour chaque ticket : numéro de PR, résultat des commandes de qualité (nombre de tests), écarts par rapport au ticket, points à relire par un humain, et ce que l'assistant doit mettre à jour (roadmap, tickets suivants).
