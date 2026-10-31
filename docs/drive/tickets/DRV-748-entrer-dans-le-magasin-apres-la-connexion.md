# DRV-748 — L'agent entre dans le magasin tout seul après la connexion

- **Dépôt** : front (`drive-agent/`, éventuellement `android/` pour les messages d'en-tête) ; la doc de l'agent est dans le back (`docs/drive/architecture.md`)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : S
- **Dépend de** : DRV-625 et DRV-629 (l'agent suit lui-même « Commencer mes courses »), DRV-717 (l'entrée chez Auchan)
- **Statut** : à faire. Effort recommandé : `high`.
- **Lire d'abord** : [`../architecture.md`](../architecture.md) (§ de l'agent) ; [`../principes-produit.md`](../principes-produit.md) ; `drive-agent/src/flow/orchestrator.ts` (`enterStore`, `dismissWelcome`) ; `drive-agent/src/flow/run-state.ts` (`markEntered`, `hasEntered`) ; `android/core/src/main/kotlin/eu/kantin/repas/drive/DrivePlan.kt` et `OpeningWatch.kt`.

## Contexte

Constat de la recette du 2026-10-31 (téléphone de test, application 1.3.0, Leclerc, **installation neuve** : aucune session ni magasin dans le drive de l'application). Le drive s'ouvre sur l'accueil de Leclerc, la personne se connecte, le site affiche « Vous êtes connecté(e) au drive », le nom du magasin et un bouton **« Commencer mes courses »**. Le bandeau de l'application dit alors « Touche « Commencer mes courses » » et **rien ne se passe tant que la personne n'a pas touché le bouton**. Elle demande : si un magasin est déjà choisi, pourquoi toucher ce bouton ? L'agent n'a rien à lui demander.

Aujourd'hui l'agent suit déjà ce bouton seul (DRV-625, DRV-629), mais **une seule fois par envoi et par onglet** (`runs.markEntered(runId)`, `enterStore` et `dismissWelcome` partagent cette garde, pour ne jamais boucler). Hypothèse la plus probable, **à confirmer en lisant le code et en rejouant le cas avec le faux site** : l'agent a déjà touché le bouton sur l'accueil, avant la connexion (le site a répondu par sa page de connexion) ; après la connexion le même bouton revient, la garde le refuse et le bandeau repasse au geste manuel (`ui.show('entry', { stage: 'manual' })`).

## À faire

1. **Trouver la vraie cause** avant de coder : rejouer, avec le faux site (`make android-e2e`, scénarios d'entrée), le parcours « accueil → bouton → page de connexion → connexion → page du magasin avec la fenêtre « Vous êtes connecté(e) au drive » et son bouton ». Si la cause n'est pas la garde, le dire dans la PR et corriger la vraie cause.
2. **Permettre une seconde entrée après la connexion**, et seulement dans ce cas : la page lue est celle d'un magasin **qui est celui du plan** (même identifiant et même hôte), la lecture de l'entrée dit `BUTTON` (ou `LINK` sûr), aucun défi de l'anti-robot, session **connectée** (`readSession`). Deux touchers au plus par envoi et par onglet (le premier avant la connexion, le second après) ; au-delà, le geste manuel reste, comme aujourd'hui. Jamais de boucle, jamais de requête ni d'écriture avant l'entrée.
3. **Un magasin différent de celui du plan** ne déclenche rien (`STORE_MISMATCH` comme avant). Pas de magasin visible : le bandeau garde son message d'aujourd'hui.
4. **Auchan** : même règle côté `auchan/entry.ts` (« Commencer mes achats ») **seulement si** le faux site d'Auchan et `docs/drive/hypotheses-site-reel-auchan.md` le permettent ; sinon ne rien changer à Auchan et le dire.
5. Le bandeau « Ouverture de ton magasin… » s'affiche pendant l'entrée, comme pour le premier toucher ; aucun texte nouveau si possible (voix `repas-po`).
6. `AGENT_VERSION` 0.55.0 (`drive-agent/src/version.ts`), empreinte `android/agent.js.sha384` régénérée, tests de l'orchestrateur (un premier toucher qui mène à la connexion, puis le second après connexion ; trois touchers jamais ; magasin d'un autre : rien ; défi de l'anti-robot : rien).
7. Docs dans le même commit : `docs/drive/architecture.md` (back, la règle « une seule tentative » devient « deux au plus, la seconde après la connexion »), `android/README.md`, et les skills du front que [`../consignes-agents.md`](../consignes-agents.md) liste s'ils parlent de cette règle.

## Ordre de livraison

Aucune clé de protocole ne change : **application Android seule** (elle embarque l'agent 0.55.0), pas de release du back ni du site. Recette réelle sur le téléphone de test, installation neuve : se connecter, vérifier que le passage démarre **sans toucher « Commencer mes courses »** ; refaire avec une session déjà ouverte (aucun toucher de trop) ; refaire sur Auchan.

## Hors périmètre

La connexion elle-même (le mot de passe se tape toujours à la main, jamais par l'agent) ; le choix du magasin (il reste celui de la personne).
