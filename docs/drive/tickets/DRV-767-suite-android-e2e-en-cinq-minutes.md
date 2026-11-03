# DRV-767 — La suite `make android-e2e` tient en 5 minutes (le flux critique seulement)

- **Dépôt** : front (`android/emulator/`, `Makefile`, `android/README.md`, `android/PUBLICATION.md`)
- **Jalon** : M7 Plusieurs enseignes (décision du propriétaire du 2026-11-02, pendant DRV-766)
- **Taille** : M
- **Dépend de** : [DRV-766](DRV-766-realigner-la-suite-android-e2e.md) (la suite doit d'abord être réalignée et mesurée ; on réduit ensuite). Aucun agent ne touche `android/emulator/` avant la fusion de DRV-766.
- **Statut** : **décisions prises le 2026-11-03, PR front #320 en brouillon, non fusionnable** (l'émulateur plante, aucune passe verte, durée non mesurée). Voir « Décisions du 2026-11-03 » en bas.
- **Ancien statut** : à lancer après la fusion de DRV-766
- **Lire d'abord** : le rapport de DRV-766 (durée de chaque scénario, où part le temps), `android/emulator/e2e.sh` (en-tête : liste des scénarios), `android/README.md` (« Les scénarios complets »)

## Contexte et décision

Une passe complète de `make android-e2e` prend de l'ordre de 10 à 15 minutes une fois verte (plus d'une heure quand beaucoup de contrôles échouent, chacun attendant son délai). Elle ne tourne qu'en local. Pendant la recette de 1.6.x, **aucun** des défauts trouvés (carte qui couvre le mot de passe, bandeau « session expirée », texte d'Auchan, nom accessible) ne l'a été par cette suite : ils l'ont tous été par la recette sur téléphone avec les vrais sites. Elle a en revanche prouvé ce qu'aucun autre test ne montre : la session de Repas qui survit à l'arrêt du processus (DRV-765, scénario 22b).

**Décision du propriétaire (2026-11-02), à tenir à la lettre :**

1. La suite sur faux sites a un **budget strict de 5 minutes de bout en bout** (démarrage de l'émulateur compris ; 5 min 1 s est trop). Elle ne garde que le **flux critique**.
2. La **recette sur téléphone avec les vrais sites** est la validation qui fait foi. Elle peut durer jusqu'à **10 minutes par site** (Leclerc, Auchan).
3. Une suite de tests sur des mocks ne doit pas ressembler à une recette : ce qui est vérifié par la recette réelle n'a pas à l'être deux fois par les faux sites.

## À faire

0. **Avant tout** (DRV-766 est fusionné sans passe verte d'un bloc) : s'assurer qu'aucun processus `e2e.sh` ou `.pick-run.sh` d'une tentative précédente ne tourne encore, puis rejouer le scénario 1 seul sur un émulateur sans autre pilote (la dernière passe de DRV-766 a été invalidée par deux rejeux simultanés ; cause du rouge du scénario 1 probable, non prouvée).
1. Partir de la mesure de DRV-766 (durée de chaque scénario) et décider avec preuve **ce qui est le flux critique** : ce qui casse l'app ou perd des données d'une personne si cela casse sans que personne ne le voie (le passage complet d'une liste d'un bout à l'autre, le retour du résultat à Repas, l'abandon propre, la session de Repas qui survit à l'arrêt du processus, le pont qui refuse une page étrangère). Écrire la liste et le **pourquoi de chaque retrait** dans le ticket.
2. Rendre les scénarios sélectionnables : `make android-e2e SCENARIOS="22 3"` (ou équivalent) joue seulement ceux-là, sans copie jetable de la fonction ; sans argument, le **jeu critique** (≤ 5 minutes). Les scénarios retirés du jeu critique ne sont pas supprimés sans raison : ils passent dans un jeu complet (`make android-e2e-full`), lancé à la main, et leur disparition éventuelle est décidée scénario par scénario.
3. Faire tenir le jeu critique dans le budget en traitant d'abord les attentes : délais d'échec trop longs, `sleep` fixes remplacés par des attentes sur condition, une seule application lancée à froid quand c'est possible, un seul démarrage d'émulateur. **Ne jamais affaiblir une assertion pour gagner du temps.**
4. Faire **échouer** le jeu critique s'il dépasse 5 minutes (une ligne dans le rapport, code de sortie non nul), pour que le budget ne dérive pas en silence.
5. Mettre à jour `e2e.sh` (en-tête), `android/README.md` et `android/PUBLICATION.md` : la procédure de publication dit de jouer les scénarios de la zone touchée plus le jeu critique, puis de faire la **recette sur téléphone avec les vrais sites**, et n'impose jamais la suite complète.
6. Mesurer et noter dans la PR la durée du jeu critique sur trois passes.

## Non vérifié

- La durée réelle d'une passe verte : à mesurer (rapport de DRV-766).
- Quels scénarios sont le flux critique : à décider avec preuve à partir de cette mesure ; le propriétaire valide la liste avant la fusion.

## Livraison

Front (tests, faux sites, scripts et documentation seulement : ni l'agent, ni l'application, ni le site ne changent ; pas de nouvelle version de l'application). PR, relecture indépendante (qui vérifie qu'aucune assertion du flux critique n'a été affaiblie et que la durée est mesurée), fusion si la CI est verte.

## Décisions du 2026-11-03 (propriétaire) et état

1. **On ne met dans l'émulateur que ce que rien d'autre ne couvre ; ce qui est couvert ailleurs « dégage »** (retiré, pas rangé dans un jeu complet : plus de `make android-e2e-full`). L'étage qui couvre est le test unitaire Kotlin (`android/core`), les tests Playwright de l'agent (`drive-agent/e2e/*.spec.ts`, en CI contre les faux sites), le test de fumée (`make android-smoke`), puis la recette sur téléphone avec les vrais sites.
2. **Gardés** : le scénario **5** (application tuée en plein passage puis reprise) et le **22** (session de Repas à rotation, DRV-765). **Retirés** : 1, 1b, 1c, 2, 3, 4, 6, 7, 8, 8b, 9 à 19, 21 ; correspondance dans le README android et l'en-tête de `e2e.sh` du front, établie sur les noms des tests et non sur une relecture ligne à ligne (à confirmer par la recette). La reproduction de DRV-768 (carte qui clignote) se fera par un test de l'agent, plus par l'émulateur.
3. **Règle de fusion** : une PR du front qui touche `android/` ou `drive-agent/` ne se fusionne que si `make android-e2e` est vert en local sur sa tête, une fois la CI verte ; c'est le relecteur indépendant qui le lance (pas l'agent de code) et qui note la tête jouée, le résultat et la durée. La CI n'a pas KVM. Écrite dans `CLAUDE.md`, `android/README.md` et `android/PUBLICATION.md` du front. (Lecture retenue : « chaque PR » = chaque PR de cette zone ; à élargir si le propriétaire le veut.)
4. Budget : 5 minutes au plus de bout en bout (construction de l'APK ~49 s, démarrage de l'émulateur 25 à 28 s, scénario 0 ~10 s : environ 90 s fixes), mesuré par `e2e.sh`, qui échoue au-delà.

**Ce qui reste (bloquant)** : l'émulateur n'a jamais pu jouer 5 et 22 sur l'état final. Le conteneur plante (signal 11, `RenderThread`, code 139) une à deux minutes après le début de chaque passe depuis 16 h 35 le 2026-11-02 (quatre fois). Docker Desktop s'est figé après les deux premiers plantages (WSL capture un vidage mémoire de plusieurs Go ; le conteneur ne vide plus sa mémoire depuis `ulimits: core: 0`, et Docker a tenu les deux fois suivantes, lien non prouvé). Couper Vulkan (`-feature -Vulkan`) n'a rien changé. Cause du plantage non établie ; piste non vérifiée : il survient au démarrage d'un passage de l'envoi (la carte de progression de l'agent, qui bascule `hidden` deux fois par seconde : DRV-768), pas pendant la lecture du magasin. Après un plantage, deux verrous périmés empêchent le redémarrage (dépannage dans le README android). **À faire** : une passe verte de `make android-e2e` sur la tête de la PR #320 (jouée par le relecteur), sa durée mesurée, puis fusion ; si l'émulateur plante encore, essayer après DRV-768 (la carte ne clignote plus) avant tout autre chantier.
