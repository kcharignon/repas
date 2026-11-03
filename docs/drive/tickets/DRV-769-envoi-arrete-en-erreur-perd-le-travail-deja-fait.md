# DRV-769 — Un envoi arrêté en erreur perd ce qui est déjà fait, et le passage suivant remet les produits au panier

- **Dépôt** : front (agent `drive-agent/`, écran de retour `src/features/drive/` ; puis une version de l'application)
- **Jalon** : M7 Plusieurs enseignes (trouvé à la recette de la 1.6.7 par le propriétaire, 2026-11-03, sur Auchan)
- **Taille** : M
- **Statut** : décrit, **non lancé** (en attente du « oui » du propriétaire) ; cause **à établir par un test**, pas devinée
- **Lire d'abord** : [DRV-745](DRV-745-lignes-cochees-et-articles-au-panier.md) (les lignes au panier se cochent après le passage, le plan suivant ne reprend que les lignes non cochées), [DRV-752](DRV-752-auchan-panier-illisible-apres-la-connexion.md) (la forme de la réponse du panier d'Auchan), `docs/drive/principes-produit.md` (« L'envoi s'est arrêté. Ce qui est déjà au panier y reste. [Reprendre l'envoi] »)

## Constat (propriétaire, Pixel 9, application 1.6.7, vrai Auchan, 2026-11-03)

1. Un envoi Auchan s'arrête en plein remplissage : « Auchan a changé son site, l'envoi automatique est arrêté. Ce qui est déjà … choisis sur Auchan : 3, Indisponible : 1 » (erreur `CONTRACT_CHANGED`, étape du flux `ERROR`, écran de fin). Les quatre articles étaient donc traités : l'arrêt est venu à la fin, **cause inconnue** : le diagnostic de l'agent ne la dit pas (session, premier plan et carte seulement), et l'écran « Détails » de Repas ne montre que le code.
2. Il touche « Reprendre l'envoi » : le second passage **va jusqu'au bout**.
3. **Il a dû rechoisir tous ses produits**, alors qu'il en avait déjà validé trois sur quatre : ce que le premier passage avait appris n'a pas été gardé.
4. **Les produits du premier passage sont en double dans son panier Auchan** (constat du propriétaire après avoir regardé le panier : « ceux de la liste juste avant étaient en doublon »). Le panier n'était pas vide au départ (28 articles, il ne le vide jamais), ce qui n'explique pas ces doublons-là.

## Ce que le code laisse penser (à prouver)

- `drive-agent/src/flow/orchestrator.ts` : sur le chemin d'erreur qui renvoie le résultat (`showError`), l'agent envoie `buildResult(plan, [], { outcome: 'error', … })` : **zéro ligne**. Repas n'apprend aucun choix et ne coche aucune ligne au panier (`tickBasket`, DRV-745) : le plan suivant renvoie donc les quatre articles, à choisir de nouveau, et l'agent les remet au panier. Si c'est bien le chemin pris, une seule cause explique le point 3 et le point 4.
- **Non établi** : si le propriétaire est passé par ce chemin ou par un autre qui garde les lignes (l'écran de fin affichait « 3 choisis, 1 indisponible »). Le premier travail est de le **tracer par un test sur le faux Auchan**, pas de le supposer.

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
