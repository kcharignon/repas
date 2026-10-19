# DRV-622 — L'écran de l'envoi et le récapitulatif tiennent en une phrase

- **Dépôt** : front (`src/features/drive/`, `src/i18n/fr.ts`, `drive-agent/src/ui/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-621 (mots et voix)
- **Statut** : fait (2026-10-19, repas-front#141 ; le point 4 par DRV-623, repas-front#140)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (§ 2, § 4), [`audit-simplicite.md`](../audit-simplicite.md) (F6, F9, F10, F11), [`DRV-404`](DRV-404-flux-d-envoi.md), [`DRV-405`](DRV-405-retour-et-recapitulatif.md), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`.

## Contexte

L'écran de l'envoi s'ouvre sur une introduction de 37 mots et quatre compteurs ; le récapitulatif affiche sept compteurs (même à zéro, dont « Prix lus » et « Échecs »), des titres incompris (« Passage arrêté ») et, parfois, un code. Un proche ne sait pas quoi faire ensuite. Retour de l'utilisateur à la recette : « Passage arrêté… je dois faire quoi ? »

## Portée

1. **Écran de l'envoi** (`driveSend`) : un titre ; **une phrase de synthèse construite avec les comptes** (« 6 articles : 2 sont déjà choisis, 4 sont à choisir sur Leclerc. ») ; une phrase d'explication au plus (« Le panier de Leclerc va se remplir, puis vous revenez ici. ») ; le bouton principal « Ouvrir le drive » est visible sans défiler ; la liste des articles est repliée sous « Voir les articles » quand elle compte plus de 6 lignes ; le bloc de compteurs disparaît (la phrase le remplace).
2. **Récapitulatif** (`driveReturn`, et l'écran de fin de l'agent `end`) : un titre qui dit le résultat (« Le panier est rempli », « Le panier est rempli en partie », « L'envoi s'est arrêté »), **une phrase** qui dit ce qui est sauf et ce qui reste à faire, **un seul bouton principal** selon le résultat (terminé : « Finaliser sur Leclerc » ; partiel, arrêté ou en erreur : « Reprendre l'envoi »), les autres actions (« Terminer la liste », « Rouvrir la liste ») plus discrètes ; **les compteurs ne montrent que ce qui existe** (jamais de ligne à zéro), « Prix lus » et « Échecs » disparaissent de l'écran (les prix restent mémorisés) ; la liste détaillée est repliée sous « Détails ».
3. **Erreurs** : toute erreur de `errors`, `driveSend.failure`, `driveReturn.errorCode` et `driveReturn.refused/invalid` suit le format en trois phrases de `principes-produit.md` § 4 (ce qui s'est passé, ce qui est sauf, une action avec son bouton) ; **plus aucun code visible** (le code reste dans une ligne « Détails » repliée, pour le dépannage) ; « lignes inattendues ignorées » devient une phrase sans jargon ou disparaît de l'écran principal.
4. **Magasin manquant** : si l'utilisateur touche « Envoyer au drive » sans magasin, l'écran affiche « Choisissez votre magasin » et un bouton « Choisir mon magasin » qui lance directement la lecture du magasin dans l'application (même action que « Lire mon magasin dans l'application »), puis revient à l'envoi une fois le magasin enregistré (DRV-623). Vérifier d'abord le comportement actuel (`driveNotConfigured`) et le décrire dans la PR.
5. L'écran de fin de l'agent (`end`) reprend les mêmes titres et la même règle « on n'affiche que ce qui existe ».

## Hors portée

Le magasin en un seul geste (DRV-623), le choix du produit (DRV-624), l'entrée dans le magasin et le retour automatiques (DRV-625), la suppression de l'écran « Prêt à remplir ».

## Critères d'acceptation

- Aucun écran de l'envoi ni du récapitulatif ne montre un compteur à zéro, un code ou plus d'un bouton principal.
- Aucune phrase de plus de 20 mots dans ces écrans ; chaque erreur a une action.
- À 360 px de large, le bouton principal est visible sans défiler sur l'écran de l'envoi (liste repliée).
- Sans magasin, un seul geste (« Choisir mon magasin ») lance la lecture.

## Tests exigés

Tests de composants des variantes (terminé, partiel, arrêté, erreur, magasin manquant), test de non-régression « aucun compteur à zéro », e2e web et émulateur mis à jour, test visuel Chromium avec **captures lues** (360 px).

## Definition of Done

Celle du dépôt front, plus les six questions de `principes-produit.md` § 6 dans la description de la PR (touches avant / après comprises).

## Pièges connus

- Le récapitulatif reste le lieu où les choix sont enregistrés (`persist()`, DRV-405/614) : ne pas déplacer cette logique, seulement l'affichage.
- Le texte « Estimation peu fiable » appartient à l'estimation (DRV-612), pas ici.
- Les textes d'erreur du serveur (`SHOPPING_LIST_SENT`, `DRIVE_LIMIT_REACHED`…) sont déjà en français dans `errors` : les reformuler, sans changer les codes.

## Réalisation (2026-10-19, PR repas-front#141, agent 0.18.0)

Points 1, 2, 3 et 5. Le point 4 (magasin manquant) a été fait par [DRV-623](DRV-623-un-seul-geste-pour-le-magasin.md) (repas-front#140). Le protocole ne change pas : aucun ordre de publication particulier entre le front et l'application (la règle « site web avant application » vient de `stats`, agent 0.15.0).

- **Touches du parcours « envoi »** : 3 avant, 3 après (« Ouvrir le drive », « Retourner dans Repas », « Finaliser sur Leclerc »), aucune ajoutée. Sur l'écran de l'envoi à 360 px avec 12 articles, « Ouvrir le drive » passe de 3 387 px (environ 5 défilements) à 399 px (aucun) ; la page passe de 3 544 à 1 015 px. Récapitulatif partiel : 5 boutons dont 2 d'égal poids avant, 1 bouton principal et 3 liens discrets après.
- **Écran de l'envoi** : titre « Votre liste est prête pour le drive » ; une phrase de synthèse construite avec les comptes (« 12 articles : 8 sont déjà choisis, 3 sont à choisir sur Leclerc, 1 ne sera pas mis au panier. ») puis « Le panier de Leclerc va se remplir, puis vous revenez ici. » ; le bloc de quatre compteurs disparaît ; « Ouvrir le drive » passe avant l'estimation et la liste ; liste repliée sous « Voir les articles » au-delà de 6 articles (au plus 6 : « Vos articles », ouverte ; hors application : toujours ouverte). Échecs du lancement en trois phrases (« Repas n'a pas pu préparer l'envoi. Rien n'a été mis au panier. Rechargez la page, puis réessayez. » ; « Le drive n'a pas répondu comme prévu. Rien n'a été perdu. Mettez l'application à jour, puis réessayez. » avec le nouveau bouton « Mettre à jour l'application » ; « Le drive ne fonctionne pas sur ce téléphone », avec mise à jour de l'application et de « Android System WebView »).
- **Récapitulatif (web)** : le titre est le résultat (« Le panier est rempli » ; « Le panier est rempli en partie » ; « Vous avez arrêté l'envoi » ; « L'envoi s'est arrêté ») ; compteurs « Ajoutés au panier », « Choisis sur Leclerc », « J'en ai toujours », « Pas cette fois », « Indisponibles », **jamais un zéro** ; « Échecs » et « Prix lus » disparaissent de l'écran (les prix restent mémorisés) ; « Détails » replié (code, message du site, « N articles ne figuraient pas dans votre liste : Repas les a laissés de côté. ») ; **un seul bouton principal** : « Finaliser sur Leclerc » (terminé) ou « Reprendre l'envoi » (sinon), plus « Chercher à la main sur Leclerc » (site changé) ou « Mettre à jour l'application » (application qui ne comprend plus Repas) ; « Terminer la liste », « Rouvrir la liste » et (panier non vide) « Finaliser sur Leclerc » en liens discrets ; « Réessayer les échecs » supprimé (« Reprendre l'envoi » reprend toute la liste, le panier n'étant jamais réduit).
- **Erreurs** : toutes en « ce qui s'est passé. Ce qui est déjà au panier y reste. Une action. », sans code visible hors « Détails » : non connecté (« Connectez-vous à Leclerc, puis reprenez l'envoi. »), autre magasin (« Ouvrez votre magasin sur Leclerc, puis reprenez l'envoi. »), ralentissement (« Leclerc a ralenti les demandes. … Attendez quelques minutes, puis reprenez l'envoi. »), site changé (« Leclerc a changé son site, l'envoi automatique est arrêté. … Cherchez vos produits à la main sur Leclerc. »), réseau, message invalide, page non prise en charge. Refus de l'application : « L'envoi n'a pas démarré. Rien n'a été mis au panier. Réessayez depuis l'envoi, ou mettez l'application à jour si cela se répète. » ; réponse mal formée : « Le drive n'a pas répondu comme prévu. Rien n'a été perdu. Mettez l'application à jour, puis réessayez. ». Erreurs du serveur (`errors`) reformulées sans changer les codes (`DRIVE_RATE_LIMITED` : « Trop de demandes en peu de temps. Rien n'est perdu. Attendez une minute, puis réessayez. ») ; les erreurs génériques restent (elles ne peuvent pas promettre que rien n'a changé).
- **Écran de fin de l'agent** : mêmes titres (« Le panier est rempli », « …en partie », « Vous avez arrêté l'envoi », « L'envoi s'est arrêté ») ; « Ajoutés au panier : n », « Choisis sur Leclerc : n », « Échecs » disparaît, aucun zéro ; texte « Vos produits sont dans votre panier Leclerc. Retournez dans Repas pour le finaliser. » ; site changé comme au web ; le bouton reste « Retourner dans Repas ». Dans `error.message` du résultat, seul le titre `BLOCKED` change (« Leclerc a ralenti les demandes »).
- **Écart avec le ticket** : le titre d'un arrêt n'est pas « Le remplissage s'est arrêté » (le glossaire interdit « remplissage » en dehors du résultat « Le panier est rempli ») ; ticket et `principes-produit.md` § 4 corrigés en « L'envoi s'est arrêté ».
- **Tests** : `src/features/drive/__tests__/simpleScreens.spec.ts` échoue sur un compteur à zéro, un code visible hors « Détails », plus d'un bouton principal ou une phrase de plus de 20 mots (écrans `driveSend`, `driveReturn`, `errors`) ; l'écran de fin de l'agent est couvert dans `drive-agent/test/ui.spec.ts`. `make check` (2583 tests), `make quality`, `make agent-e2e` (53), Playwright web (94), `make android-e2e` (139 contrôles, avant et après le rebase), captures lues.

### Points laissés

- `?lignes=` reste lu par l'écran de l'envoi (anciens liens) mais plus aucun écran ne le propose.
- « Finaliser sur Leclerc » et « Chercher à la main sur Leclerc » ouvrent toujours l'adresse du magasin (F16, [DRV-625](DRV-625-entrer-dans-le-magasin-sans-geste.md)).
- Hors ticket, laissés : `driveNotConfigured` et `driveStoreInvalid` (DRV-623), `driveProducts.unavailable` (« Mes produits »).
