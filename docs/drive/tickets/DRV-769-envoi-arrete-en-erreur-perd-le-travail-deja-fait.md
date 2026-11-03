# DRV-769 — Un envoi arrêté en erreur perd ce qui est déjà fait, et le passage suivant remet les produits au panier

- **Dépôt** : front (agent `drive-agent/`, écran de retour `src/features/drive/` ; puis une version de l'application)
- **Jalon** : M7 Plusieurs enseignes (trouvé à la recette de la 1.6.7 par le propriétaire, 2026-11-03, sur Auchan)
- **Taille** : M
- **Statut** : **livré dans l'application 1.6.8 (agent 0.62.11) le 2026-11-03** (front #322, squash `875e12f`, étiquette `android-v1.6.8`, release publiée, APK installé sur le téléphone de test) ; **recette réelle du propriétaire sur Auchan à faire** (ticket ouvert jusque-là) ; la cause du `CONTRACT_CHANGED` d'origine reste **inconnue**
- **Lire d'abord** : [DRV-745](DRV-745-lignes-cochees-et-articles-au-panier.md) (les lignes au panier se cochent après le passage, le plan suivant ne reprend que les lignes non cochées), [DRV-752](DRV-752-auchan-panier-illisible-apres-la-connexion.md) (la forme de la réponse du panier d'Auchan), `docs/drive/principes-produit.md` (« L'envoi s'est arrêté. Ce qui est déjà au panier y reste. [Reprendre l'envoi] »)

## Constat (propriétaire, Pixel 9, application 1.6.7, vrai Auchan, 2026-11-03)

1. Un envoi Auchan s'arrête en plein remplissage : « Auchan a changé son site, l'envoi automatique est arrêté. Ce qui est déjà … choisis sur Auchan : 3, Indisponible : 1 » (erreur `CONTRACT_CHANGED`, étape du flux `ERROR`, écran de fin). Les quatre articles étaient donc traités : l'arrêt est venu à la fin, **cause inconnue** : le diagnostic de l'agent ne la dit pas (session, premier plan et carte seulement), et l'écran « Détails » de Repas ne montre que le code.
2. Il touche « Reprendre l'envoi » : le second passage **va jusqu'au bout**.
3. **Il a dû rechoisir tous ses produits**, alors qu'il en avait déjà validé trois sur quatre : ce que le premier passage avait appris n'a pas été gardé.
4. **Les produits du premier passage sont en double dans son panier Auchan** (constat du propriétaire après avoir regardé le panier : « ceux de la liste juste avant étaient en doublon »). Le panier n'était pas vide au départ (28 articles, il ne le vide jamais), ce qui n'explique pas ces doublons-là.

## Ce que le code laisse penser (à prouver)

- `drive-agent/src/flow/orchestrator.ts` : sur le chemin d'erreur qui renvoie le résultat (`showError`), l'agent envoie `buildResult(plan, [], { outcome: 'error', … })` : **zéro ligne**. Repas n'apprend aucun choix et ne coche aucune ligne au panier (`tickBasket`, DRV-745) : le plan suivant renvoie donc les quatre articles, à choisir de nouveau, et l'agent les remet au panier. Si c'est bien le chemin pris, une seule cause explique le point 3 et le point 4.
- **Non établi** : si le propriétaire est passé par ce chemin ou par un autre qui garde les lignes (l'écran de fin affichait « 3 choisis, 1 indisponible »). Le premier travail est de le **tracer par un test sur le faux Auchan**, pas de le supposer.
- **Résultat du traçage (2026-11-03)** : l'hypothèse ci-dessus est **réfutée pour le chemin « erreur → bouton de retour »** (l'agent y renvoie bien les lignes traitées). La perte vient d'ailleurs : l'**abandon natif** (touche Retour d'Android puis « Abandonner l'envoi », ou « Abandonner » depuis l'écran d'envoi de Repas) fermait le passage avec `lines: []`, sans rien demander à l'agent. Le propriétaire n'avait « rien touché » sur l'écran d'erreur et se souvient d'avoir « sûrement abandonné » : c'est la cause **probable**, non rejouée sur le vrai site.

## À faire

1. **Reproduire d'abord** sur le faux site d'Auchan (`drive-agent/fake-auchan/`), sans émulateur : un envoi qui s'arrête en erreur après deux ou trois lignes traitées, puis le passage suivant sur la même liste. Compter, à chaque étape : les lignes du résultat reçu par Repas, les choix enregistrés, les lignes cochées « au panier », les lignes du faux panier. Le test doit échouer aujourd'hui s'il y a perte ou doublon.
2. **Corriger selon la preuve**, sans nouveau texte ni nouvelle action : un envoi arrêté (erreur, annulation, blocage) rapporte à Repas ce qu'il a fait (lignes ajoutées, indisponibles, produits choisis), pour que le plan suivant ne reprenne que ce qui reste et que les choix appris soient enregistrés. Vérifier le même comportement pour Leclerc.
3. **Dire la cause de la dernière erreur dans le diagnostic de l'agent** (code et cause de l'enum fermée, jamais une valeur du site, une adresse ni un nom) : au prochain arrêt, la boîte « Diagnostic de l'agent » la lira.
4. Ne pas toucher à la lecture du panier d'Auchan (DRV-752) tant que la cause du `CONTRACT_CHANGED` d'origine n'est pas lue : ce ticket ne la corrige pas, il la rend visible.
5. Nouvelle version de l'agent et de l'application (1.6.8 attendue), relecture indépendante, `make android-e2e` joué par le relecteur (la PR touche `drive-agent/` et `android/`), puis recette sur téléphone avec Auchan, panier vidé avant.

## Non vérifié

- La cause du `CONTRACT_CHANGED` d'origine (anti-robot, réponse du produit indisponible, autre chose) : inconnue, un seul cas, non rejoué.
- Si Leclerc a le même défaut.
- Si un arrêt par annulation ou blocage perd aussi les lignes.

## Livraison

Front : PR, relecture indépendante, fusion si la CI est verte et le jeu réduit de l'émulateur vert (relecteur), étiquette `android-vX.Y.Z`, approbation et `APP_LATEST_VERSION` par le propriétaire.

## Ce qui est livré et prouvé (2026-11-03)

- **Correctif** (front #322, squash `875e12f`, agent 0.62.11, application 1.6.8) : l'agent envoie un message `progress` (`{v, type, runId, lines}`, 64 Ko au plus, seulement avec le pont de l'application) après chaque ligne terminée. L'application en garde la dernière copie (100 lignes au plus, enregistrée avec le passage, validée par `ProgressLines.kt` qui reprend les règles de `validateResult` ; zéro ligne si elle n'est pas conforme). À l'abandon, `DrivePass.abort()` met ces lignes dans le résultat « abandonné ». Côté Repas, `driveBridge.abandon()` envoie `drive.cancel` en gardant le brouillon en mémoire, et `useDriveReturn` traite en silence un résultat `stale` + `abandoned` : les choix appris sont enregistrés (`useSaveChoices`) et les lignes au panier cochées (`tickBasket`, DRV-745), donc le passage suivant ne les reprend pas. Aucun texte ni action nouveaux.
- **Corrections de l'orchestrateur** trouvées en route : incident inattendu en plein passage, plantage de l'écran de fin, erreur avant tout travail avec un état de reprise ; une seule copie `progress` pour un échec bloquant.
- **Diagnostic de l'agent** : la boîte « Diagnostic de l'agent » dit maintenant « Dernière erreur : CODE (CAUSE) » (`Flow.lastFault`, code et cause d'une énumération fermée, jamais une valeur du site, une adresse ni un nom). Au prochain `CONTRACT_CHANGED`, la cause se lira là.
- **Budget de l'agent** : 320 000 octets (était 300 000 ; l'agent pèse 295 210). Seul `drive-agent/scripts/build.mjs` (+ `build.spec.ts` et le README) applique ce plafond ; l'application Android ne limite pas la taille du fichier. Décision du propriétaire : on garde 320 000 et on monte petit à petit.
- **Preuves** : test par le vrai chemin du clic (rouge avant, vert après) pour l'abandon depuis Repas ; `DrivePassTest` (41 tests) ; `protocol-progress.spec.ts`, `flow-stopped.spec.ts` ; CI verte ; **relecture indépendante** (un défaut trouvé et corrigé : le brouillon effacé avant la réponse faisait ignorer le résultat « abandonné ») ; **`make android-e2e` joué par le relecteur, vert en 126 s** (scénarios 5 et 22) ; CI de `main` verte après la fusion.

## Limites connues (dites dans la PR)

- (a) La copie « abandonnée » gardée par Repas est effacée par tout résultat non lié au brouillon qui arrive avant la comparaison du `runId`.
- (b) Le chemin silencieux ne connaît pas les produits déjà connus : une correction de contenance faite pendant le passage n'est pas mémorisée.
- (c) Recharger la page de Repas entre l'abandon et la réponse perd la copie gardée en mémoire.
- (d) Une ligne en cours d'ajout au moment exact de l'abandon n'est pas rapportée : elle peut encore se doubler au passage suivant.
- Piste voisine, non prouvée pour le propriétaire : `tickBasket` n'est pas attendu et ses échecs sont avalés (DRV-747), et « Réessayer l'enregistrement » ne le renvoie pas.

## Non vérifié

- La cause du `CONTRACT_CHANGED` d'origine (anti-robot, réponse du produit indisponible, autre chose) : inconnue, un seul cas, non rejoué ; DRV-752 reste intact.
- Le défaut sur Leclerc : le code est commun à l'agent, la recette réelle reste à faire.
- La recette réelle : abandon en plein passage sur Auchan (panier vidé avant), puis « Reprendre l'envoi » : choix conservés, aucun doublon dans le panier.

## Reste

- Recette du propriétaire sur téléphone avec Auchan, puis clôture du ticket.
- `APP_LATEST_VERSION=1.6.8` à écrire par le propriétaire.
- Éventuels tickets distincts : `tickBasket` attendu et son échec visible ; stabilité de l'émulateur (plantage environ une passe sur quatre, verrous restés).
