# DRV-622 — L'écran de l'envoi et le récapitulatif tiennent en une phrase

- **Dépôt** : front (`src/features/drive/`, `src/i18n/fr.ts`, `drive-agent/src/ui/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-621 (mots et voix)
- **Statut** : à faire
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (§ 2, § 4), [`audit-simplicite.md`](../audit-simplicite.md) (F6, F9, F10, F11), [`DRV-404`](DRV-404-flux-d-envoi.md), [`DRV-405`](DRV-405-retour-et-recapitulatif.md), skills `repas-po`, `repas-front-dev`, `repas-agent-dev`.

## Contexte

L'écran de l'envoi s'ouvre sur une introduction de 37 mots et quatre compteurs ; le récapitulatif affiche sept compteurs (même à zéro, dont « Prix lus » et « Échecs »), des titres incompris (« Passage arrêté ») et, parfois, un code. Un proche ne sait pas quoi faire ensuite. Retour de l'utilisateur à la recette : « Passage arrêté… je dois faire quoi ? »

## Portée

1. **Écran de l'envoi** (`driveSend`) : un titre ; **une phrase de synthèse construite avec les comptes** (« 6 articles : 2 sont déjà choisis, 4 sont à choisir sur Leclerc. ») ; une phrase d'explication au plus (« Le panier de Leclerc va se remplir, puis vous revenez ici. ») ; le bouton principal « Ouvrir le drive » est visible sans défiler ; la liste des articles est repliée sous « Voir les articles » quand elle compte plus de 6 lignes ; le bloc de compteurs disparaît (la phrase le remplace).
2. **Récapitulatif** (`driveReturn`, et l'écran de fin de l'agent `end`) : un titre qui dit le résultat (« Le panier est rempli », « Le panier est rempli en partie », « Le remplissage s'est arrêté »), **une phrase** qui dit ce qui est sauf et ce qui reste à faire, **un seul bouton principal** selon le résultat (terminé : « Finaliser sur Leclerc » ; partiel, arrêté ou en erreur : « Reprendre l'envoi »), les autres actions (« Terminer la liste », « Rouvrir la liste ») plus discrètes ; **les compteurs ne montrent que ce qui existe** (jamais de ligne à zéro), « Prix lus » et « Échecs » disparaissent de l'écran (les prix restent mémorisés) ; la liste détaillée est repliée sous « Détails ».
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
