# DRV-766 — Réaligner la suite `make android-e2e` (scénarios rouges sur `main`)

- **Dépôt** : front (`android/emulator/`, faux sites `drive-agent/fake-leclerc/` et `fake-auchan/`)
- **Jalon** : M7 Plusieurs enseignes (dette de test découverte pendant DRV-765)
- **Taille** : M
- **Dépend de** : rien (le scénario 22, session de Repas à rotation, vient de [DRV-765](DRV-765-session-de-repas-perdue-a-la-mise-a-jour.md))
- **Statut** : **fusionné le 2026-11-03 (front #319, squash `4aa9dd8`, CI de `main` verte)**, sans passe verte d'un bloc (voir « Ce qui reste » ci-dessous). Historique : PR front #319, relecture indépendante faite (rien d'affaibli, points corrigés). **Constat de la première passe complète sur `main`** : 178 contrôles en échec en 6064 s (1 h 41), cause commune (b/a) : le faux Leclerc et le plan décrivent l'ancien parcours (le plan est refusé, `INVALID_PLAN`, hors des hôtes acceptés) et le scénario 1 laissé ouvert contamine les scénarios 2 à 15. **Une vraie régression (c)** trouvée, non corrigée ici : [DRV-768](DRV-768-la-carte-du-guide-clignote-dans-l-arbre-d-accessibilite.md) (la carte du guide bascule `hidden` toutes les 500 ms ; contrôle « RÉGRESSION DE L'AGENT » du scénario 8 volontairement rouge). **Reste à rejouer d'un bloc** (Docker était tombé) : scénarios 13 à 17, 19, 21 et 22 réalignés mais non rejoués, durée de chaque scénario.
- **Lire d'abord** : `android/README.md` (« Émulateur de test », « Les scénarios complets »), `android/emulator/e2e.sh`, [DRV-754](DRV-754-leclerc-par-la-version-mobile.md) et les tickets DRV-755, 757, 759, 760, 761 (ce qui a changé dans le parcours et les cartes depuis le dernier alignement)

## Contexte

`make android-e2e` rejoue de vrais passages (l'agent réel, la vraie WebView, dans l'émulateur Android de Docker) contre le faux site Leclerc complet. **Il ne tourne qu'en local** : la CI n'a pas KVM, `make check` ne le lance jamais. Sa dernière mise en phase date du 2026-10-30 (agent 0.39.0, « une intention = un clic »).

Pendant DRV-765 (2026-11-02), l'agent de code a constaté que les scénarios **1, 1b et 1c échouent déjà sur `origin/main`**, sans lien avec sa PR, avec des messages du type « l'agent n'a pas demandé confirmation », « bouton Utiliser ce magasin introuvable », « le lien Se connecter est introuvable ». Il a arrêté son exécution complète pendant le scénario 2 : l'état des scénarios 2 à 22 est **inconnu**.

Depuis le dernier alignement, le produit a beaucoup changé : Leclerc par sa version mobile (DRV-754), le magasin choisi lu par l'agent et accepté par Repas (DRV-755, clé `chosenStore`), la carte du guide repliable avec sous-étapes (DRV-757), le mur de cookies, le nom accessible, la carte qui ne couvre plus un champ de saisie (DRV-759 à 764). Les scénarios et le faux Leclerc décrivent probablement l'ancien parcours.

## À faire

1. Jouer la suite **entière** sur `main` sans rien changer, et lister tous les scénarios rouges (message, rapport, capture).
2. Classer chaque rouge **avec preuve** : (a) le scénario décrit un ancien parcours que le produit a changé volontairement ; (b) le faux site ne reproduit pas le site réel mesuré ; (c) une **vraie régression** de l'agent ou de l'application.
3. Réaligner les cas (a) et (b) (scénario ou faux site) **sans jamais affaiblir une assertion** pour passer ; décrire précisément les cas (c) sans les corriger en douce, et laisser leur scénario rouge ou explicitement marqué.
4. Mettre à jour les en-têtes de `e2e.sh` et `android/README.md` (paragraphe daté du réalignement). Dans la procédure de publication : jouer les scénarios de la zone touchée ; **pas de règle « toute la suite avant chaque étiquette »** (décision du propriétaire du 2026-11-02 : la suite sur faux sites doit tenir en 5 minutes au plus et ne garder que le flux critique, la recette sur téléphone avec les vrais sites fait foi ; c'est le ticket [DRV-767](DRV-767-suite-android-e2e-en-cinq-minutes.md), après celui-ci).
5. Mesurer la durée de **chaque scénario** (passe verte, ou meilleure mesure disponible) et dire où part le temps ; DRV-767 en part.

## Non vérifié

- Les scénarios 2 à 22 : leur état est à établir par la première exécution complète.
- Le temps d'une exécution complète (environ une à deux heures attendues).

## Livraison

Front (tests et faux sites seulement : ni l'agent, ni l'application, ni le site ne changent, sauf une vraie régression décidée à part). PR, relecture indépendante, fusion si la CI est verte ; pas de nouvelle version de l'application.

## Ce qui reste (état à la fusion, 2026-11-03)

- **Aucune passe verte d'un bloc de `make android-e2e` n'existe.** La dernière (nuit du 2026-11-02, après le redémarrage de Docker) est **invalide** : un rejeu partiel lancé à 16 h 33, resté suspendu pendant la panne de Docker, a repris au redémarrage et piloté le même émulateur en même temps qu'elle. Le scénario 1 y est rouge (boîte « Abandonner la lecture du magasin ? » 9 s après « Commencer mes courses ») : **cause probable, non prouvée**, les autres causes possibles (fusion de `main`, émulateur à froid, changements de `lib.sh`) ne sont pas écartées.
- Verts sur un état antérieur (passes isolées du 2026-11-02) : 0, 1, 1b, 1c, 2 à 7, 8 (sauf le contrôle de la carte, rouge voulu : DRV-768), 8b, 9 à 12, 18. **Jamais vus verts après réalignement** : fin du 13, 14, 15, 16, 16b, 17, 19, 21, 22.
- Durées : aucun tableau complet. Mesures partielles et où part le temps (attentes d'échec, attentes fixes, lectures `uiautomator`, relances de l'application) dans `android/README.md` ; le rapport écrit désormais « durée : N s » par scénario.
- **Pour DRV-767** : commencer par un rejeu du scénario 1 seul, sur un émulateur sans autre pilote ; vérifier avant chaque passe qu'aucun processus `e2e.sh` ou `.pick-run.sh` d'une tentative précédente ne tourne encore (un rejeu suspendu reprend tout seul quand Docker revient).
- Constat de méthode : DRV-766 a pris plus de 10 heures d'agent. Le propriétaire a refusé de poursuivre en boucle (2026-11-03) : la suite sera réduite au flux critique, les scénarios qui en sortent n'ont pas à être perfectionnés.
